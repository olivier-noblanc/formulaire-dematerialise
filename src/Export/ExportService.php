<?php

declare(strict_types=1);

namespace App\Export;

use App\Auth\AuthService;
use App\Core\Database;
use PDO;

/**
 * Service d'export CSV des soumissions.
 *
 * Extrait de lib/export_csv.php — export streamé avec filtres et headers HTTP.
 * Les fonctions globales dans lib/export_csv.php délèguent maintenant ici.
 */
final readonly class ExportService
{
    public function __construct(private Database $database, private AuthService $authService)
    {
    }

    /**
     * Exporte les soumissions au format CSV et force le téléchargement.
     *
     * @param array<string, mixed> $options Filtres optionnels ['form_id' => string, 'status' => string]
     */
    public function exportCsv(array $options = []): void
    {
        if (!$this->authService->isAdmin()) {
            (new \App\Render\ErrorRenderer())->errorPage(403, 'Accès refusé', 'Vous n\'avez pas accès à l\'export CSV. Cette fonctionnalité est réservée aux administrateurs.');
        }

        $where = ['1=1'];
        $params = [];
        if (!empty($options['form_id'])) {
            $where[] = 's.form_id = ?';
            $params[] = $options['form_id'];
        }
        if (!empty($options['status'])) {
            $where[] = 's.status = ?';
            $params[] = $options['status'];
        }
        $where_sql = implode(' AND ', $where);

        $pdo = $this->database->getPdo();

        // Récupérer les colonnes JSON distinctes via json_each (une seule requête légère)
        $keysStmt = $pdo->prepare("
            SELECT DISTINCT j.key
            FROM submissions s, json_each(s.data) j
            JOIN forms f ON f.id = s.form_id
            WHERE $where_sql AND json_valid(s.data) AND j.key != 'validations'
        ");
        $keysStmt->execute($params);
        $all_keys = $keysStmt->fetchAll(PDO::FETCH_COLUMN);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_submissions_' . gmdate('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        // BOM pour Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // En-tête fixe
        $headers = array_merge(['ID', 'Formulaire', 'Agent', 'Statut', 'Soumis le', 'Clôturé le'], $all_keys);
        fputcsv($out, $headers, ';', '"', '\\');

        // Streamer les lignes par batch de 500
        $batch_size = 500;
        $offset = 0;

        do {
            $stmt = $pdo->prepare("
                SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status,
                       f.label as form_label, f.slug as form_slug
                FROM submissions s
                JOIN forms f ON f.id = s.form_id
                WHERE $where_sql
                ORDER BY s.submitted_at DESC
                LIMIT $batch_size OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $data = json_decode($row['data'], true) ?: [];
                $line = [
                    $row['id'],
                    $row['form_label'],
                    $row['submitted_by'],
                    $row['status'],
                    $row['submitted_at'],
                    $row['closed_at'] ?? '',
                ];
                foreach ($all_keys as $all_key) {
                    $val = $data[$all_key] ?? '';
                    if ($val === '1') {
                        $val = 'Oui';
                    } elseif ($val === '0') {
                        $val = 'Non';
                    } elseif (is_array($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                    }
                    // Neutraliser injection CSV (Excel formula injection)
                    if (is_string($val) && preg_match('/^[=\-+\@]/', $val)) {
                        $val = "'" . $val;
                    }
                    $line[] = $val;
                }
                fputcsv($out, $line, ';', '"', '\\');
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);

        fclose($out);
        exit;
    }
}
