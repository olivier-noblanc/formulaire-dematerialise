<?php

declare(strict_types=1);

namespace App\Export;

use App\Auth\AuthService;
use App\Core\App;
use App\Repository\SubmissionRepository;

/**
 * Service d'export CSV des soumissions.
 *
 * Extrait de lib/export_csv.php — export streamé avec filtres et headers HTTP.
 * Les fonctions globales dans lib/export_csv.php délèguent maintenant ici.
 *
 * Tout accès DB passe par le SubmissionRepository injecté (ou résolu via App).
 */
final readonly class ExportService
{
    public SubmissionRepository $submissionRepository;

    public function __construct(
        private AuthService $authService,
        ?SubmissionRepository $submissionRepository = null
    ) {
        $app = App::getInstance();
        $this->submissionRepository = $submissionRepository ?? $app->get(SubmissionRepository::class);
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

        // Récupérer les colonnes JSON distinctes via json_each (une seule requête légère)
        $all_keys = $this->submissionRepository->findDistinctJsonKeys($where_sql, $params);

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
            $rows = $this->submissionRepository->findForExportWithForm($where_sql, $params, $batch_size, $offset);

            foreach ($rows as $row) {
                $data = json_decode($row['data'], true) ?? [];
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
            new \App\Render\ErrorRenderer()->errorPage(403, 'Accès refusé', 'Vous n\'avez pas accès à l\'export CSV. Cette fonctionnalité est réservée aux administrateurs.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_submissions_' . gmdate('Ymd_His') . '.csv"');

        echo $this->generateCsvString($options);
        exit;
    }
}
