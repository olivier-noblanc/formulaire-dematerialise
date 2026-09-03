<?php

declare(strict_types=1);

namespace App\Export;

use App\Auth\AuthService;
use App\Core\App;
use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;

/**
 * Service d'export CSV des soumissions.
 *
 * Extrait de lib/export_csv.php (fichier supprimé depuis) — export streamé
 * avec filtres et headers HTTP. Point d'entrée production : exportCsv()
 * (appelé par DashboardController), flux produit par csvChunks().
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
     * - checkbox : '1' → 'Oui', '0'/'' → 'Non'
     * - tableaux → json_encode
     * - Neutralise l'injection CSV (formules Excel) — toujours active
     *
     * B-FIX4 (2026-09-01) : la conversion Oui/Non ne s'applique qu'aux champs
     * checkbox — les autres types de champs peuvent légitimement valoir '1'
     * ou '0' (texte, option de select) et doivent rester tels quels.
     */
    public function transformValue(mixed $val, bool $isCheckbox = false): mixed
    {
        if ($isCheckbox) {
            if ($val === '1') {
                return 'Oui';
            }
            if ($val === '0' || $val === '') {
                return 'Non';
            }
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
     * @param array{form_id?: string, status?: string} $options
     * @return array{0: string, 1: list<string>}
     */
    public function buildWhereClause(array $options): array
    {
        $where = ['1=1'];
        $params = [];
        $formId = (string) ($options['form_id'] ?? '');
        if ($formId !== '') {
            $where[] = 's.form_id = ?';
            $params[] = $formId;
        }
        $statusFilter = (string) ($options['status'] ?? '');
        if ($statusFilter !== '') {
            $where[] = 's.status = ?';
            $params[] = $statusFilter;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Génère les morceaux CSV (BOM, en-tête, lignes par batch de 500) —
     * B-FIX5 (2026-09-01) : streaming réel, la sortie est consommée chunk
     * par chunk sans accumulation de l'ensemble des soumissions en mémoire.
     *
     * Public : c'est l'API de l'export — consommée par exportCsv()
     * (streaming HTTP) et par les tests (aucune accumulation en mémoire).
     *
     * @param array{form_id?: string, status?: string} $options
     * @return \Generator<int, string>
     */
    public function csvChunks(array $options = []): \Generator
    {
        [$where_sql, $params] = $this->buildWhereClause($options);

        // Récupérer les colonnes JSON distinctes via json_each (une seule requête légère)
        $all_keys = $this->submissionRepository->findDistinctJsonKeys($where_sql, $params);

        // B-FIX4 : noms des champs checkbox — la conversion Oui/Non ne s'applique qu'à eux
        $checkbox_names = App::getInstance()->get(FormRepository::class)
            ->getCheckboxFieldNames(
                (($options['form_id'] ?? '') !== '') ? (string) $options['form_id'] : null
            );

        // Flux temporaire de travail pour encoder chaque ligne via fputcsv
        $tmp = fopen('php://temp', 'r+');
        if ($tmp === false) {
            return;
        }
        $csvLine = static function (array $line) use ($tmp): string {
            fputcsv($tmp, $line, ';', '"', '\\');
            rewind($tmp);
            $chunk = (string) stream_get_contents($tmp);
            ftruncate($tmp, 0);
            rewind($tmp);
            return $chunk;
        };

        // BOM pour Excel
        yield chr(0xEF) . chr(0xBB) . chr(0xBF);

        // En-tête fixe
        yield $csvLine(array_merge(['ID', 'Formulaire', 'Agent', 'Statut', 'Soumis le', 'Clôturé le'], $all_keys));

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
                    $line[] = $this->transformValue($data[$all_key] ?? '', in_array($all_key, $checkbox_names, true));
                }
                yield $csvLine($line);
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);

        fclose($tmp);
    }

    /**
     * Exporte les soumissions au format CSV et force le téléchargement.
     *
     * @param array{form_id?: string, status?: string} $options Filtres optionnels ['form_id' => string, 'status' => string]
     */
    public function exportCsv(array $options = []): void
    {
        if (!$this->authService->isAdmin()) {
            new \App\Render\ErrorRenderer()->errorPage(403, 'Accès refusé', 'Vous n\'avez pas accès à l\'export CSV. Cette fonctionnalité est réservée aux administrateurs.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_submissions_' . gmdate('Ymd_His') . '.csv"');

        // B-FIX5 : streaming réel — chaque batch est envoyé au client au fur
        // et à mesure (aucune string complète en mémoire)
        foreach ($this->csvChunks($options) as $chunk) {
            echo $chunk;
        }
        exit;
    }
}
