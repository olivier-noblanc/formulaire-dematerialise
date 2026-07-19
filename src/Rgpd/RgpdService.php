<?php

declare(strict_types=1);

namespace App\Rgpd;

use App\Core\App;
use App\Core\Database;
use PDO;

/**
 * Service RGPD — export, suppression, purge automatique.
 */
final readonly class RgpdService
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
     *
     * @return array{email: string, export_date?: string, submissions?: array<int, array{id: string, form: string, status: string, submitted_at: string, closed_at: string|null, data: mixed}>, validations?: array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}>, error?: string}
     */
    public function exportUserData(string $email): array
    {
        $caller = App::auth()->getUser();
        $callerIsAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        if (!$callerIsAdmin && strtolower($email) !== strtolower($caller)) {
            App::audit()->log('access_denied', 'rgpd:' . $email, 'Tentative d\'export RGPD non autorisée par ' . $caller, '');
            return ['email' => $email, 'error' => 'Accès refusé : vous ne pouvez exporter que vos propres données.'];
        }

        $pdo = $this->database->getPdo();
        $data = ['email' => $email, 'export_date' => gmdate('c'), 'submissions' => [], 'validations' => []];

        $stmt = $pdo->prepare('SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status, s.admin_comment, s.rgpd_consent, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.submitted_by = ? ORDER BY s.submitted_at DESC');
        $stmt->execute([$email]);
        /** @var array<int, array{id: string, form_label: string, status: string, submitted_at: string, closed_at: string|null, data: string}> */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $stmt2 = $pdo->prepare('SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at, t.done_at, t.relance_at, t.expires_at, t.relance_count, st.label as step_label, f.label as form_label FROM tokens t JOIN steps st ON st.id = t.step_id JOIN submissions s ON s.id = t.submission_id JOIN forms f ON f.id = s.form_id WHERE t.email = ? AND t.done_at IS NOT NULL AND t.invalidated_at IS NULL ORDER BY t.done_at DESC');
        $stmt2->execute([$email]);
        /** @var array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, form_label: string}> $validations */
        $validations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $data['validations'] = $validations;

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

        $pdo = $this->database->getPdo();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, data FROM submissions WHERE submitted_by = ?');
            $stmt->execute([$email]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $submissionData = json_decode($row['data'], true) ?: [];
                foreach (['prenom', 'nom', 'email', 'telephone', 'mobile', 'adresse'] as $field) {
                    if (isset($submissionData[$field])) {
                        $submissionData[$field] = '[supprimé]';
                    }
                }
                $pdo->prepare('UPDATE submissions SET submitted_by = ?, data = ? WHERE id = ?')
                    ->execute(['[supprimé]', json_encode($submissionData, JSON_UNESCAPED_UNICODE), $row['id']]);
                $pdo->prepare('DELETE FROM attachments WHERE submission_id = ?')->execute([$row['id']]);
            }

            $pdo->prepare("UPDATE tokens SET email = '[supprimé]' WHERE email = ?")->execute([$email]);
            $pdo->prepare("UPDATE delegations SET from_email = '[supprimé]' WHERE from_email = ?")->execute([$email]);
            $pdo->prepare("UPDATE delegations SET to_email = '[supprimé]' WHERE to_email = ?")->execute([$email]);
            $pdo->prepare('DELETE FROM admin_requests WHERE email = ?')->execute([$email]);
            // Utiliser AuthService::removeAdmin() qui inclut le garde-fou anti-auto-suppression du super-admin
            App::auth()->removeAdmin($email);

            $pdo->commit();
            App::audit()->log('rgpd_delete', 'user:' . $email, 'Données utilisateur supprimées (RGPD)', $email);
            return true;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
        $pdo = $this->database->getPdo();
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$months} months") ?: time());

        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE status != 'en_cours' AND closed_at < ?");
        $stmt->execute([$cutoff]);
        $oldIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        $pdo->beginTransaction();
        try {
            foreach ($oldIds as $oldId) {
                $pdo->prepare('DELETE FROM attachments WHERE submission_id = ?')->execute([$oldId]);
                $pdo->prepare('DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)')->execute([$oldId]);
                $pdo->prepare('DELETE FROM tokens WHERE submission_id = ?')->execute([$oldId]);
                $pdo->prepare('DELETE FROM alert_log WHERE submission_id = ?')->execute([$oldId]);
                $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$oldId]);
                $count++;
            }
            $pdo->commit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
