<?php

declare(strict_types=1);

namespace App\Rgpd;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Mail\MailOutbox;
use App\Repository\AdminRepository;
use App\Repository\AlertRepository;
use App\Repository\AttachmentRepository;
use App\Repository\DelegationRepository;
use App\Repository\MailRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;

/**
 * Service RGPD — export, suppression, purge automatique.
 *
 * Tout accès DB passe par les repositories injectés ou résolus via App.
 */
final readonly class RgpdService
{
    public SubmissionRepository $submissionRepository;
    public TokenRepository $tokenRepository;
    public AttachmentRepository $attachmentRepository;
    public AlertRepository $alertRepository;
    public AdminRepository $adminRepository;
    public DelegationRepository $delegationRepository;
    public MailRepository $mailRepository;

    public function __construct(
        ?SubmissionRepository $submissionRepository = null,
        ?TokenRepository $tokenRepository = null,
        ?AttachmentRepository $attachmentRepository = null,
        ?AlertRepository $alertRepository = null,
        ?AdminRepository $adminRepository = null,
        ?DelegationRepository $delegationRepository = null,
        ?MailRepository $mailRepository = null
    ) {
        $app = App::getInstance();
        $this->submissionRepository = $submissionRepository ?? $app->get(SubmissionRepository::class);
        $this->tokenRepository = $tokenRepository ?? $app->get(TokenRepository::class);
        $this->attachmentRepository = $attachmentRepository ?? $app->get(AttachmentRepository::class);
        $this->alertRepository = $alertRepository ?? $app->get(AlertRepository::class);
        $this->adminRepository = $adminRepository ?? $app->get(AdminRepository::class);
        $this->delegationRepository = $delegationRepository ?? $app->get(DelegationRepository::class);
        $this->mailRepository = $mailRepository ?? $app->get(MailRepository::class);
    }

    /**
     * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
     *
     * @return array{email: string, export_date?: string, submissions?: array<int, array{id: string, form: string, status: string, submitted_at: string|null, closed_at: string|null, data: mixed}>, validations?: array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}>, emails?: list<array{id: string, created_at: string, subject: string, status: string, attempts: int}>, error?: string}
     */
    public function exportUserData(string $email): array
    {
        $caller = App::auth()->getUser();
        $callerIsAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        if (!$callerIsAdmin && strtolower($email) !== strtolower($caller)) {
            App::audit()->log('access_denied', 'rgpd:' . $email, 'Tentative d\'export RGPD non autorisée par ' . $caller, '');
            return ['email' => $email, 'error' => 'Accès refusé : vous ne pouvez exporter que vos propres données.'];
        }

        $data = ['email' => $email, 'export_date' => gmdate('c'), 'submissions' => [], SubmissionField::VALIDATIONS->value => [], 'emails' => []];

        $rows = $this->submissionRepository->findForRgpdExportByEmail($email);
        foreach ($rows as $row) {
            $data['submissions'][] = [
                'id' => $row['id'],
                'form' => $row['form_label'],
                'status' => $row['status'],
                'submitted_at' => $row['submitted_at'],
                'closed_at' => $row['closed_at'],
                'data' => json_decode($row['data'], true),
            ];
        }

        $data[SubmissionField::VALIDATIONS->value] = $this->tokenRepository->findDoneValidationsByEmail($email);
        // A5 : métadonnées des emails adressés à l'agent (sans le corps HTML).
        $data['emails'] = $this->mailRepository->findByRecipient($email);

        return $data;
    }

    /**
     * Supprime les données d'un agent (droit à l'effacement RGPD)
     */
    public function deleteUserData(string $email): bool
    {
        $caller = App::auth()->getUser();
        $callerIsAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        if (!$callerIsAdmin && strtolower($email) !== strtolower($caller)) {
            App::audit()->log('access_denied', 'rgpd:' . $email, 'Tentative de suppression RGPD non autorisée par ' . $caller, '');
            return false;
        }

        try {
            $this->tokenRepository->beginImmediateTransaction();

            // B-RG1 fix (audit fonctionnel 2026-07-26) : avant d'anonymiser, on doit
            // invalider les tokens actifs de l'agent ET fermer ses soumissions en cours.
            // Sinon, l'agent se retrouvait avec submitted_by='[supprimé]' mais des
            // soumissions toujours en_cours — les validateurs recevaient encore des
            // relances, et l'agent pouvait théoriquement encore agir sur ses anciens
            // tokens (lien email). Maintenant : on clôture explicitement.
            $now = gmdate('Y-m-d H:i:s');
            // 1. Invalider tous les tokens actifs de l'agent
            $this->tokenRepository->invalidateActiveByEmail($email, $now);
            // 2. Clôturer les soumissions en_cours de l'agent (status annule, closed_at now)
            $this->submissionRepository->cancelActiveBySubmitter($email, $now);

            $rows = $this->submissionRepository->findIdAndDataBySubmitter($email);
            foreach ($rows as $row) {
                $submissionData = json_decode($row['data'], true) ?? [];
                foreach (['prenom', 'nom', 'email', 'telephone', 'mobile', 'adresse'] as $field) {
                    if (isset($submissionData[$field])) {
                        $submissionData[$field] = '[supprimé]';
                    }
                }
                $encoded = json_encode($submissionData, JSON_UNESCAPED_UNICODE);
                $this->submissionRepository->updateSubmittedByAndData($row['id'], '[supprimé]', $encoded === false ? '{}' : $encoded);
                $this->attachmentRepository->deleteBySubmissionId($row['id']);
            }

            $this->tokenRepository->updateEmailByOldEmail($email, '[supprimé]');
            $this->delegationRepository->anonymizeFromEmail($email, '[supprimé]');
            $this->delegationRepository->anonymizeToEmail($email, '[supprimé]');
            // A5 : anonymiser les emails sortants (destinataire + corps purgé).
            $this->mailRepository->anonymizeByRecipient($email, '[supprimé]');
            $this->adminRepository->deleteAdminRequestsByEmail($email);
            // Utiliser AuthService::removeAdmin() qui inclut le garde-fou anti-auto-suppression du super-admin
            App::auth()->removeAdmin($email);

            $this->tokenRepository->commit();
            App::audit()->log('rgpd_delete', 'user:' . $email, 'Données utilisateur supprimées (RGPD)', $email);
            return true;
        } catch (\Exception $e) {
            // @silent-ok: log-only with audit trail and rollback, returns false
            if ($this->tokenRepository->inTransaction()) {
                $this->tokenRepository->rollBack();
            }
            $errorMsg = 'RGPD delete error: ' . $e->getMessage();
            error_log($errorMsg);
            App::audit()->log('rgpd_delete_failed', 'user:' . $email, $errorMsg);
            return false;
        }
    }

    /**
     * Purge automatique des données anciennes (RGPD - conservation limitée)
     */
    public function autoPurge(int $months = 24): int
    {
        $cutoff_ts = strtotime("-{$months} months");
        $cutoff = gmdate('Y-m-d H:i:s', $cutoff_ts !== false ? $cutoff_ts : time());

        // Rétention courte du corps HTML de l'outbox (en UTC) : succès après
        // BODY_KEEP_SENT_DAYS, échecs terminaux après BODY_KEEP_TERMINAL_DAYS.
        // Seul le corps est purgé ; la ligne `mail_log` est conservée (traçabilité).
        $successCutoff = gmdate('Y-m-d H:i:s', time() - MailOutbox::BODY_KEEP_SENT_DAYS * 86400);
        $terminalCutoff = gmdate('Y-m-d H:i:s', time() - MailOutbox::BODY_KEEP_TERMINAL_DAYS * 86400);

        $oldIds = $this->submissionRepository->findIdsPurgeableByCutoffForRgpd($cutoff);

        $count = 0;
        $mailsPurged = 0;
        $bodiesPurged = 0;
        $this->tokenRepository->beginImmediateTransaction();
        try {
            foreach ($oldIds as $oldId) {
                // Cascade delete : attachments, delegations, tokens, alert_log, submissions.
                // Décomposé en appels individuels (et non via une méthode unique) car on est
                // déjà dans une transaction, et SQLite ne supporte pas les transactions
                // imbriquées.
                $this->attachmentRepository->deleteBySubmissionId($oldId);
                $this->delegationRepository->deleteBySubmissionId($oldId);
                $this->tokenRepository->deleteBySubmissionId($oldId);
                $this->alertRepository->deleteLogBySubmissionId($oldId);
                $this->submissionRepository->deleteById($oldId);
                $count++;
            }
            // A5 : purger les emails sortants trop anciens (conservation limitée).
            $mailsPurged = $this->mailRepository->purgeOlderThan($cutoff);
            // Lane B : purger les CORPS des emails trop anciens (rétention courte,
            // lignes conservées) — `pending` jamais purgé (nécessaire au rejeu).
            $bodiesPurged = $this->mailRepository->purgeOutboxBodies($successCutoff, $terminalCutoff);
            $this->tokenRepository->commit();
        } catch (\Exception $e) {
            // @silent-ok: log-only background cleanup with rollback
            if ($this->tokenRepository->inTransaction()) {
                $this->tokenRepository->rollBack();
            }
            error_log('RGPD autoPurge error: ' . $e->getMessage());
            return 0;
        }

        if ($count > 0 || $mailsPurged > 0 || $bodiesPurged > 0) {
            App::audit()->log(
                'rgpd_purge',
                '',
                "Purge RGPD : {$count} soumissions et {$mailsPurged} emails de plus de {$months} mois supprimés ; "
                    . "{$bodiesPurged} corps d'emails purgés (rétention "
                    . MailOutbox::BODY_KEEP_SENT_DAYS . 'j succès / '
                    . MailOutbox::BODY_KEEP_TERMINAL_DAYS . "j échecs)",
                ''
            );
        }

        return $count;
    }
}
