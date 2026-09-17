<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Contract\MailInterface;
use App\Enum\SubmissionStatus;
use App\Forms\FieldService;
use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;

/**
 * Gère l'avancement du workflow : création de tokens, vérification
 * des étapes, clôture des soumissions.
 *
 * Extrait de WorkflowEngine (H-01, 2026-08-04).
 */
final readonly class WorkflowAdvancer
{
    use WorkflowTokenCreationTrait;
    public function __construct(
        private SettingsService $settingsService,
        private MailInterface $mailService,
        private FieldService $fieldService,
        private ConditionEvaluator $conditionEvaluator,
        private RecipientResolver $recipientResolver,
        private SubmissionRepository $submissionRepository,
        private TokenRepository $tokenRepository,
        private FormRepository $formRepository,
    ) {}

    public function advance(string $submissionId): void
    {
        // CS-01 (audit 2026-07-26) : god function de 160 lignes décomposée en 3 helpers
        // privés. Comportement inchangé, juste plus lisible et testable.
        $submission = $this->getSubmissionWithFormLabel($submissionId);
        if (!((bool)$submission)) {
            return;
        }
        if ((bool)($submission['closed_at'])) {
            return;
        }

        $formId = $submission['form_id'];
        $allSteps = $this->getWorkflowSteps($formId);

        // Grouper par ordre
        $byOrder = [];
        foreach ($allSteps as $step) {
            $byOrder[(int) $step['ordre']][] = $step;
        }
        ksort($byOrder);

        $now = gmdate('Y-m-d H:i:s');
        $expireDays = (int) $this->settingsService->get('token_expire_days', '30');
        $expiresAt_ts = strtotime("+{$expireDays} days");
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresAt_ts !== false ? $expiresAt_ts : time());

        // Transaction pour séquencer les lectures/écritures de tokens
        // et empêcher les doublons entre requêtes concurrentes.
        // BaseRepository expose beginTransaction/commit/rollBack — les repos
        // partagent la même connexion PDO (Database singleton).
        // B3 : les notifications sont accumulées puis envoyées APRÈS le commit
        // (voir flushNotifications) pour ne pas tenir le verrou d'écriture
        // SQLite pendant l'I/O SMTP.
        $notifications = [];
        $this->tokenRepository->beginImmediateTransaction();
        $committed = false;
        try {
            // Tokens déjà créés (lu dans la transaction pour un snapshot cohérent)
            $tokensByStep = [];
            foreach ($this->tokenRepository->findStepIdsAndDonesBySubmission($submissionId) as $t) {
                $tokensByStep[(string) $t['step_id']][] = $t['done_at'];
            }

            // B-W1 fix (audit fonctionnel 2026-07-26) : si toutes les étapes de TOUS les
            // groupes ont leur condition=false (ou aucun recipient valide), on arrive
            // ici sans avoir créé aucun token — et le code clôturait la soumission comme
            // 'valide'. C'est un bug métier : une soumission sans aucune validation ne
            // devrait pas être marquée validée. On lève une exception (rollback auto via
            // le catch en bas) et on log pour diagnose.
            foreach ($byOrder as $groupe) {
                $stepIds = array_column($groupe, 'step_id');
                $allStarted = count(array_intersect($stepIds, array_keys($tokensByStep))) === count($groupe);

                if (!$allStarted) {
                    // Créer les tokens manquants pour ce groupe. Si au moins un token a été
                    // créé, on s'arrête (en attendant leur validation).
                    $tokenCreated = $this->createTokensForGroup(
                        $groupe,
                        $tokensByStep,
                        $submission,
                        $submissionId,
                        $now,
                        $expiresAt,
                        $notifications
                    );
                    if ($tokenCreated) {
                        $this->tokenRepository->commit();
                        $committed = true;
                        return;
                    }
                    // Si aucun token créé pour ce groupe (conditions false ou recipients
                    // invalides), on ne le considère PAS comme complété. On sort en
                    // attendant qu'une action humaine corrige la config (condition,
                    // recipients) — la soumission reste en_cours, pas clôturée.
                    $this->tokenRepository->commit();
                    $committed = true;
                    \App\Core\App::audit()->log(
                        'workflow_stalled',
                        'submission:' . $submissionId,
                        'Workflow bloqué : aucune étape du groupe ordre=' . ($groupe[0]['ordre'] ?? '?') . ' n\'a pu créer de token (conditions false ou recipients invalides). Soumission laissée en_cours — intervention admin requise.',
                        'WorkflowAdvancer'
                    );
                    return;
                }

                // Vérifier si toutes les étapes de cet ordre sont validées
                if (!$this->isGroupComplete($groupe, $tokensByStep)) {
                    $this->tokenRepository->commit();
                    $committed = true;
                    return;
                }
            }

            // B-W1 : on n'arrive ici QUE si tous les groupes sont déjà validés
            // (tokens créés et done_at set pour tous). On ne clôture QUE si des
            // tokens existent — sinon la soumission n'avait aucune étape active.
            if ($tokensByStep === []) {
                // Aucune étape active dans le formulaire → on ne clôture PAS
                $this->tokenRepository->commit();
                $committed = true;
                \App\Core\App::audit()->log(
                    'workflow_no_steps',
                    'submission:' . $submissionId,
                    'Aucune étape active trouvée pour ce formulaire — soumission laissée en_cours.',
                    'WorkflowAdvancer'
                );
                return;
            }

            // Toutes les étapes sont validées → clôturer.
            // R3 (audit 2026-09-14) : closeWithStatus est un CAS (en_cours +
            // closed_at NULL). Si elle échoue, une exécution concurrente de
            // advance() a déjà clôturé la soumission — premier commit gagne,
            // on rollback et on sort sans notifier (pas de double email).
            if (!$this->submissionRepository->closeWithStatus($submissionId, $now, SubmissionStatus::Valide->value)) {
                $this->tokenRepository->rollBack();
                return;
            }

            $this->tokenRepository->commit();
            $committed = true;

            // Notifier l'agent (hors transaction — side effect)
            $this->notifyAgentOfCompletion($submission);
        } catch (\Throwable $e) {
            if (!$committed && $this->tokenRepository->inTransaction()) {
                $this->tokenRepository->rollBack();
            }
            throw $e;
        } finally {
            // B3 : envoi SMTP hors transaction — le verrou d'écriture SQLite ne
            // doit pas être tenu pendant l'I/O réseau.
            if ($committed) {
                $this->flushNotifications($notifications);
            }
        }
    }

/**
     * Vérifie si toutes les étapes actives d'un groupe (ayant au moins un token)
     * sont validées (tous leurs tokens ont done_at IS NOT NULL).
     *
     * @param list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $groupe
     * @param array<string, list<string|null>> $tokensByStep
     */
    private function isGroupComplete(mixed $groupe, mixed $tokensByStep): bool
    {
        foreach ($groupe as $step) {
            // Étape sans token = condition false ou recipients invalides → pas concernée
            if (!isset($tokensByStep[$step['step_id']])) {
                continue;
            }
            $dones = $tokensByStep[$step['step_id']];
            if (!array_all($dones, fn(mixed $d): bool => $d !== null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Envoie l'email de notification à l'agent après clôture de la soumission.
     *
     * @param array{submitted_by: string, form_label: string} $submission
     */
    private function notifyAgentOfCompletion(mixed $submission): void
    {
        $agentEmail = $submission['submitted_by'] ?? '';
        if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $subject = 'Demande validée — ' . ($submission['form_label'] ?? '');
            $body = $this->mailService->renderEmailTemplate('Demande validée', '<p>Votre demande a été validée.</p>');
            $this->mailService->send($agentEmail, $subject, $body);
        }
    }

    /** @return array<string, string> */
    private function getValidatorDataForEvaluation(string $submissionId): array
    {
        $data = $this->fieldService->getValidatorData($submissionId);
        $result = [];
        foreach ($data as $vd) {
            $result[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
        }
        return $result;
    }

    /**
     * @return array{
     *   id: string,
     *   form_id: string,
     *   data: string,
     *   submitted_by: string,
     *   submitted_at: string|null,
     *   closed_at: string|null,
     *   status: string,
     *   admin_comment: string,
     *   rgpd_consent: int|null,
     *   form_label: string
     * }|null
     */
    private function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        return $this->submissionRepository->findWithFormLabelById($submissionId);
    }

    /**
     * @return list<array{
     *   step_id: string,
     *   step_label: string,
     *   ordre: int,
     *   actif: int,
     *   condition: string,
     *   recipient_emails: string
     * }>
     */
    private function getWorkflowSteps(string $formId): array
    {
        return $this->formRepository->findWorkflowStepsForEngine($formId);
    }

    private function generateToken(): string
    {
        return \generate_token();
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
