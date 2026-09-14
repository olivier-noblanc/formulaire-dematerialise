<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * Création des tokens d'un workflow et notifications associées.
 *
 * Extrait de WorkflowAdvancer (limite projet 350 lignes — audit adversarial
 * B1/B3 2026-09-14). Les dépendances ($conditionEvaluator, $recipientResolver,
 * $tokenRepository, $mailService, helpers privés) sont fournies par la classe
 * qui utilise ce trait (WorkflowAdvancer).
 *
 * @phpstan-type WorkflowNotification array{to: string, subject: string, submission: array<string, mixed>, step_label: string, token: string}
 */
trait WorkflowTokenCreationTrait
{
    /**
     * Crée les tokens manquants pour un groupe d'étapes parallèles.
     *
     * @param list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $groupe
     * @param array<string, list<string|null>> $tokensByStep map step_id => [done_at values] (sera mutée in-place pour les nouveaux tokens)
     * @param array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string} $submission
     * @param list<WorkflowNotification> $notifications notifications accumulées, à envoyer après le commit (B3)
     */
    private function createTokensForGroup(
        mixed $groupe,
        mixed &$tokensByStep,
        mixed $submission,
        string $submissionId,
        string $now,
        string $expiresAt,
        array &$notifications
    ): bool {
        $formData = json_decode($submission['data'] ?? '{}', true) ?? [];
        $validatorData = $this->getValidatorDataForEvaluation($submissionId);
        $tokenCreated = false;

        foreach ($groupe as $step) {
            $createdForStep = false;

            // Étape déjà démarrée (a au moins un token) → ne pas créer de doublon
            if (isset($tokensByStep[$step['step_id']])) {
                continue;
            }

            // Évaluer la condition
            if (!$this->conditionEvaluator->evaluate(
                $step['condition'] ?? '',
                $validatorData
            )) {
                continue;
            }

            $rawEmails = explode('|', $step['recipient_emails'] ?? '');
            $hasRecipient = false;
            foreach ($rawEmails as $rawEmail) {
                $rawEmail = trim($rawEmail);
                if ($rawEmail === '') {
                    continue;
                }
                if ($rawEmail === '0') {
                    continue;
                }

                $rawEmail = $this->recipientResolver->resolve($rawEmail, $formData, $submissionId);
                if (filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
                    error_log("WorkflowAdvancer: skipping invalid recipient '{$rawEmail}' for step {$step['step_id']}");
                    continue;
                }

                $hasRecipient = true;

                // Vérifier doublon
                if ($this->tokenRepository->hasPendingDuplicate($submissionId, $step['step_id'], $rawEmail)) {
                    continue;
                }

                $token = $this->generateToken();
                $tokenRowId = $this->generateUuid();
                try {
                    $this->tokenRepository->insertToken($tokenRowId, $submissionId, $step['step_id'], $rawEmail, $token, $now, $expiresAt);
                } catch (\PDOException $e) {
                    if ($e->getCode() === '23000') {
                        error_log("WorkflowAdvancer: duplicate token prevented for step {$step['step_id']}, email {$rawEmail}");
                        continue;
                    }
                    throw $e;
                }

                $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['step_label'];
                $notifications[] = [
                    'to' => $rawEmail,
                    'subject' => $subject,
                    'submission' => $submission,
                    'step_label' => $step['step_label'],
                    'token' => $token,
                ];
                $tokenCreated = true;
                $createdForStep = true;
                $tokensByStep[$step['step_id']][] = null; // done_at IS NULL pour le nouveau token
            }

            // Étape sans recipients valides — logger et ignorer (misconfiguration)
            if (!$hasRecipient && !in_array(trim($step['recipient_emails'] ?? ''), ['', '0'], true)) {
                error_log("WorkflowAdvancer: step {$step['step_id']} has condition true but no valid recipients — skipping");
            }

            // B1 (audit 2026-09-14) : l'étape n'est plus « démarrée » car ses
            // seuls tokens étaient invalidés (délégation/régénération/RGPD) ;
            // on vient d'en recréer un. On trace explicitement la recréation.
            if ($createdForStep && $this->tokenRepository->countInvalidatedBySubmissionAndStep($submissionId, $step['step_id']) > 0) {
                \App\Core\App::audit()->log(
                    'workflow_step_recreated_after_invalidation',
                    'submission:' . $submissionId,
                    'Token recréé pour l\'étape « ' . $step['step_label'] . ' » : les tokens précédents étaient invalidés (délégation/régénération/RGPD). Un nouveau lien a été envoyé.',
                    'WorkflowAdvancer'
                );
            }
        }

        return $tokenCreated;
    }

    /**
     * Envoie les notifications accumulées pendant la transaction, après commit.
     *
     * B3 (audit 2026-09-14) : l'I/O SMTP ne doit pas être exécutée dans la
     * transaction SQLite (verrou d'écriture tenu pendant l'I/O réseau).
     *
     * @param list<WorkflowNotification> $notifications
     */
    private function flushNotifications(array $notifications): void
    {
        foreach ($notifications as $n) {
            $body = $this->mailService->buildValidationEmail($n['submission'], $n['step_label'], $n['token']);
            if (!$this->mailService->send($n['to'], $n['subject'], $body)) {
                error_log("WorkflowAdvancer: mail failed for token {$n['token']} to {$n['to']}");
            }
        }
    }
}