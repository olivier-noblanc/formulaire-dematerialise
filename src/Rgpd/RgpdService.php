<?php
declare(strict_types=1);

namespace App\Rgpd;

use App\Core\App;
use App\Core\Database;
use PDO;

/**
 * Service RGPD — export, suppression, purge automatique.
 */
final class RgpdService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
     * @return array<string, mixed>
     */
    public function exportUserData(string $email): array
    {
        $caller = App::auth()->getUser();
        $callerIsAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        if (!$callerIsAdmin && strtolower($email) !== strtolower($caller)) {
            App::audit()->log('access_denied', 'rgpd:' . $email, 'Tentative d\'export RGPD non autorisée par ' . $caller, '');
            return ['email' => $email, 'error' => 'Accès refusé : vous ne pouvez exporter que vos propres données.'];
        }

        $pdo = $this->db->getPdo();
        $data = ['email' => $email, 'export_date' => gmdate('c'), 'submissions' => [], 'validations' => []];

        $stmt = $pdo->prepare("SELECT s.*, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.submitted_by = ? ORDER BY s.submitted_at DESC");
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data['submissions'][] = [
                'id' => $row['id'],
                'form' => $row['form_label'],
                'status' => $row['status'],
                'submitted_at' => $row['submitted_at'],
                'closed_at' => $row['closed_at'],
                'data' => json_decode($row['data'], true),
            ];
        }

        $stmt2 = $pdo->prepare("SELECT t.*, st.label as step_label, f.label as form_label FROM tokens t JOIN steps st ON st.id = t.step_id JOIN submissions s ON s.id = t.submission_id JOIN forms f ON f.id = s.form_id WHERE t.email = ? AND t.done_at IS NOT NULL ORDER BY t.done_at DESC");
        $stmt2->execute([$email]);
        $data['validations'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

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

        $pdo = $this->db->getPdo();

        try {
            $stmt = $pdo->prepare("SELECT id, data FROM submissions WHERE submitted_by = ?");
            $stmt->execute([$email]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $submissionData = json_decode($row['data'], true) ?: [];
                foreach (['prenom', 'nom', 'email', 'telephone', 'mobile', 'adresse'] as $field) {
                    if (isset($submissionData[$field])) {
                        $submissionData[$field] = '[supprimé]';
                    }
                }
                $pdo->prepare("UPDATE submissions SET submitted_by = ?, data = ? WHERE id = ?")
                    ->execute(['[supprimé]', json_encode($submissionData, JSON_UNESCAPED_UNICODE), $row['id']]);
                $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$row['id']]);
            }

            $pdo->prepare("UPDATE tokens SET email = '[supprimé]' WHERE email = ?")->execute([$email]);
            $pdo->prepare("UPDATE delegations SET from_email = '[supprimé]' WHERE from_email = ?")->execute([$email]);
            $pdo->prepare("UPDATE delegations SET to_email = '[supprimé]' WHERE to_email = ?")->execute([$email]);
            $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
            $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);

            App::audit()->log('rgpd_delete', 'user:' . $email, 'Données utilisateur supprimées (RGPD)', $email);
            return true;
        } catch (\Exception $e) {
            error_log('RGPD delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge automatique des données anciennes (RGPD - conservation limitée)
     */
    public function autoPurge(int $months = 24): int
    {
        $pdo = $this->db->getPdo();
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$months} months") ?: time());

        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE status != 'en_cours' AND closed_at < ?");
        $stmt->execute([$cutoff]);
        $oldIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($oldIds as $sid) {
            $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)")->execute([$sid]);
            $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM alert_log WHERE submission_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sid]);
            $count++;
        }

        if ($count > 0) {
            App::audit()->log('rgpd_purge', '', "Purge RGPD : {$count} soumissions de plus de {$months} mois supprimées", '');
        }

        return $count;
    }
}
