<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Mail\MailService;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;

/**
 * Gère la validation/refus d'un token (appelé par validateToken()).
 *
 * Extrait de WorkflowEngine (H-01, 2026-08-04).
 */
final readonly class TokenValidationHandler
{
    public function __construct(
        private TokenRepository $tokenRepository,
        private SubmissionRepository $submissionRepository,
        private MailService $mailService,
    ) {}

    /**
     * Valide ou refuse un token.
     *
     * @param callable(string): void $advanceWorkflow Callback pour avancer le workflow après validation
     * @return array{
     *   status: string,
     *   data?: array{
     *     id: string,
     *     submission_id: string,
     *     step_id: string,
     *     email: string,
     *     token: string,
     *     sent_at: string,
     *     done_at: string|null,
     *     relance_at: string|null,
     *     expires_at: string|null,
     *     relance_count: int,
     *     step_label: string,
     *     form_id: string,
     *     form_label: string,
     *     data: string,
     *     closed_at: string|null,
     *     status: string,
     *     submitted_by: string
     *   },
     *   message?: string
     * }
     */
    public function validate(
        string $token,
        string $action,
        string $comment,
        string $doneBy,
        callable $advanceWorkflow,
        callable $getTokenWithContext,
    ): array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['status' => 'invalid'];
        }
        if (!in_array($action, [ValidationAction::Valider->value, ValidationAction::Refuser->value], true)) {
            return ['status' => 'invalid', 'message' => 'Action non autorisée.'];
        }

        $this->tokenRepository->beginTransaction();

        $t = $getTokenWithContext($token);
        if ($t === null) {
            $this->tokenRepository->rollBack();
            return ['status' => 'invalid'];
        }
        // B-V1 fix (audit fonctionnel 2026-07-26) : un token invalidé (par cancel,
        // regenerate ou delegate) ne doit pas pouvoir être validé même si done_at
        // est NULL. Avant, le check seul `!empty($t['done_at'])` laissait passer
        // les tokens invalidés — l'utilisateur voyait une page de validation
        // fonctionnelle alors que le token était mort.
        if (!empty($t['done_at']) || !empty($t['invalidated_at'])) {
            $this->tokenRepository->rollBack();
            return ['status' => 'already_done', 'data' => $t];
        }
        if (!empty($t['closed_at'])) {
            $this->tokenRepository->rollBack();
            return ['status' => 'closed', 'data' => $t];
        }

        if (!empty($t['expires_at'])) {
            // B1 fix (audit 2026-07-26) : les dates sont stockées en UTC (soit via
            // SQLite datetime('now'), soit via PHP gmdate()). strtotime() sans
            // fuseau explicite interprète la chaîne avec le fuseau serveur
            // (Europe/Paris en prod), causant un décalage de 1-2h : tokens
            // marqués expirés trop tôt. On force l'interprétation UTC en suffixant
            // la chaîne avec ' UTC' (notation reconnue par strtotime).
            // Même pattern que les fixes historiques #12 (alert_check.php) et
            // v10.22.0 (remind.php) — n'avait pas été appliqué ici.
            $expTs = strtotime($t['expires_at'] . ' UTC');
            if ($expTs !== false && $expTs < time()) {
                $this->tokenRepository->rollBack();
                return ['status' => 'expired', 'data' => $t];
            }
        }

        $comment = mb_substr($comment, 0, 1000);

        if ($action === ValidationAction::Refuser->value) {
            $rowCount = $this->tokenRepository->markDoneByTokenValue($token, gmdate('Y-m-d H:i:s'));
            if ($rowCount === 0) {
                $this->tokenRepository->rollBack();
                return ['status' => 'already_done', 'data' => $t];
            }

            $this->submissionRepository->closeWithStatus($t['submission_id'], gmdate('Y-m-d H:i:s'), SubmissionStatus::Refuse->value);
        } else {
            $rowCount = $this->tokenRepository->markDoneByTokenValue($token, gmdate('Y-m-d H:i:s'));
            if ($rowCount === 0) {
                $this->tokenRepository->rollBack();
                return ['status' => 'already_done', 'data' => $t];
            }
        }

        $validationEntry = [
            'step_label' => $t['step_label'],
            'email' => $t['email'],
            'done_by' => $doneBy,
            'action' => $action,
            'commentaire' => $comment,
            'date' => gmdate('Y-m-d H:i:s'),
        ];

        // B8 fix : appendToDataJson() fait de l'optimistic locking (WHERE data = old_json)
        // et peut retourner false si 3 conflits successifs. Avant, ce retour était
        // ignoré — l'audit_log disait 'validated' mais la data JSON n'avait pas la nouvelle
        // validation. Maintenant on rollback et on informe l'appelant.
        $appended = $this->submissionRepository->appendToDataJson($t['submission_id'], function (array $data) use ($validationEntry): array {
            $data['validations'][] = $validationEntry;
            return $data;
        });
        if (!$appended) {
            $this->tokenRepository->rollBack();
            // Audit l'échec pour diagnose (règle AGENTS.md #9 : ne pas avaler silencieusement)
            \App\Core\App::audit()->log(
                'validation_data_append_failed',
                'submission:' . $t['submission_id'],
                'Échec appendToDataJson (conflit optimistic locking 3x) pour token ' . $token,
                $doneBy
            );
            return ['status' => 'data_conflict', 'data' => $t];
        }

        $this->tokenRepository->commit();

        // Emails et advanceWorkflow APRES le commit (side effects hors transaction)
        if ($action === ValidationAction::Refuser->value) {
            $agentEmail = $t['submitted_by'] ?? '';
            if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Demande refusée — ' . ($t['form_label'] ?? '');
                $body = '<h2 style="color:#c0392b;">Demande refusée</h2>'
                    . '<p>Votre demande <strong>' . \App\Core\App::html()->escape($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . \App\Core\App::html()->escape($t['step_label']) . '</strong>.</p>'
                    . ($comment === '' || $comment === '0' ? '' : '<p><strong>Motif :</strong> ' . \App\Core\App::html()->escape($comment) . '</p>');
                $this->mailService->send($agentEmail, $subject, $this->mailService->renderEmailTemplate('Demande refusée', $body));
            }
        } else {
            $advanceWorkflow($t['submission_id']);
        }

        $t['done_at'] = gmdate('Y-m-d H:i:s');
        return ['status' => 'ok', 'data' => $t];
    }
}
