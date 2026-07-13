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
     * Transforme une valeur brute pour l'export CSV.
     *
     * - '1' → 'Oui', '0' → 'Non'
     * - tableaux → json_encode
     * - Neutralise l'injection CSV (formules Excel)
     */
    public function transformValue(mixed $val): mixed
    {
        if ($val === '1') {
            return 'Oui';
        }
        if ($val === '0') {
            return 'Non';
        }
        if (is_array($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($val) && preg_match('/^[=\-+\@]/', $val)) {
            return "'" . $val;
        }
        return $val;
    }

    /**
     * Construit la clause WHERE et les paramètres à partir des options.
     *
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function buildWhereClause(array $options): array
    {
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
        return [implode(' AND ', $where), $params];
    }

    /**
     * Génère le contenu CSV sous forme de chaîne (sans headers HTTP ni exit).
     *
     * Utilisé par exportCsv() et testable directement.
     *
     * @param array<string, mixed> $options Filtres optionnels ['form_id' => string, 'status' => string]
     */
    public function generateCsvString(array $options = []): string
    {
        [$where_sql, $params] = $this->buildWhereClause($options);

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

        $output = fopen('php://memory', 'r+');
        if ($output === false) {
            return '';
        }

        // BOM pour Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // En-tête fixe
        $headers = array_merge(['ID', 'Formulaire', 'Agent', 'Statut', 'Soumis le', 'Clôturé le'], $all_keys);
        fputcsv($output, $headers, ';', '"', '\\');

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
                    $line[] = $this->transformValue($data[$all_key] ?? '');
                }
                fputcsv($output, $line, ';', '"', '\\');
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
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

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_submissions_' . gmdate('Ymd_His') . '.csv"');

        echo $this->generateCsvString($options);
        exit;
    }
}
