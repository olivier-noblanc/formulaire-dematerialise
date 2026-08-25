<?php

declare(strict_types=1);

namespace App\Rgpd;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Repository\AdminRepository;
use App\Repository\AlertRepository;
use App\Repository\AttachmentRepository;
use App\Repository\DelegationRepository;
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

    public function __construct(
        ?SubmissionRepository $submissionRepository = null,
        ?TokenRepository $tokenRepository = null,
        ?AttachmentRepository $attachmentRepository = null,
        ?AlertRepository $alertRepository = null,
        ?AdminRepository $adminRepository = null,
        ?DelegationRepository $delegationRepository = null
    ) {
        $app = App::getInstance();
        $this->submissionRepository = $submissionRepository ?? $app->get(SubmissionRepository::class);
        $this->tokenRepository = $tokenRepository ?? $app->get(TokenRepository::class);
        $this->attachmentRepository = $attachmentRepository ?? $app->get(AttachmentRepository::class);
        $this->alertRepository = $alertRepository ?? $app->get(AlertRepository::class);
        $this->adminRepository = $adminRepository ?? $app->get(AdminRepository::class);
        $this->delegationRepository = $delegationRepository ?? $app->get(DelegationRepository::class);
    }

    /**
     * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
     *
     * @return array{email: string, export_date?: string, submissions?: array<int, array{id: string, form: string, status: string, submitted_at: string|null, closed_at: string|null, data: mixed}>, validations?: array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}>, error?: string}
     */
    public function exportUserData(string $email): array
    {
        $caller = App::auth()->getUser();
        $callerIsAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        if (!$callerIsAdmin && strtolower($email) !== strtolower($caller)) {
            App::audit()->log('access_denied', 'rgpd:' . $email, 'Tentative d\'export RGPD non autorisée par ' . $caller, '');
            return ['email' => $email, 'error' => 'Accès refusé : vous ne pouvez exporter que vos propres données.'];
        }

        $data = ['email' => $email, 'export_date' => gmdate('c'), 'submissions' => [], SubmissionField::VALIDATIONS->value => []];

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
            $this->tokenRepository->beginTransaction();

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

        $oldIds = $this->submissionRepository->findIdsPurgeableByCutoffForRgpd($cutoff);

        $count = 0;
        $this->tokenRepository->beginTransaction();
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
            $this->tokenRepository->commit();
        } catch (\Exception $e) {
            // @silent-ok: log-only background cleanup with rollback
            if ($this->tokenRepository->inTransaction()) {
                $this->tokenRepository->rollBack();
            }
            error_log('RGPD autoPurge error: ' . $e->getMessage());
            return 0;
        }

        if ($count > 0) {
            App::audit()->log('rgpd_purge', '', "Purge RGPD : {$count} soumissions de plus de {$months} mois supprimées", '');
        }

        return $count;
    }
}
