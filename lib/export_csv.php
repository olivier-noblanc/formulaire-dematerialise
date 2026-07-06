<?php
declare(strict_types=1);

/**
 * CSV export of submissions.
 *
 * export_csv() — stream CSV des soumissions avec filtres et headers HTTP
 *
 * @package lib
 */

// ── EXPORT CSV ───────────────────────────────────────────────

/**
 * Exporte les soumissions au format CSV et force le téléchargement.
 *
 * @param PDO   $pdo     Connexion base de données
 * @param array<string, mixed> $options Filtres optionnels ['form_id' => string, 'status' => string]
 */
function export_csv(PDO $pdo, array $options = []): void {
    // Sécurité : vérifier que l'utilisateur est admin avant d'exporter
    // A-09 : utiliser render_error_page() plutôt que die()/echo brut
    if (!is_admin_user() && !is_super_admin()) {
        render_error_page(403, 'Accès refusé', 'Vous n\'avez pas accès à l\'export CSV. Cette fonctionnalité est réservée aux administrateurs.');
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

    $stmt = $pdo->prepare("
        SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status,
               f.label as form_label, f.slug as form_slug
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE $where_sql
        ORDER BY s.submitted_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collecter toutes les clés de données pour les colonnes
    $all_keys = [];
    foreach ($rows as $row) {
        $data = json_decode($row['data'], true) ?: [];
        foreach (array_keys($data) as $k) {
            if ($k !== 'validations' && !in_array($k, $all_keys)) {
                $all_keys[] = $k;
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="export_submissions_' . gmdate('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    // BOM pour Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // En-tête fixe
    $headers = array_merge(['ID', 'Formulaire', 'Agent', 'Statut', 'Soumis le', 'Clôturé le'], $all_keys);
    fputcsv($out, $headers, ';', '"', '\\');

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
        foreach ($all_keys as $k) {
            $val = $data[$k] ?? '';
            if ($val === '1') $val = 'Oui';
            elseif ($val === '0') $val = 'Non';
            elseif (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $line[] = $val;
        }
        fputcsv($out, $line, ';', '"', '\\');
    }
    fclose($out);
    exit;
}
