<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur du tableau de bord administrateur (dashboard.php).
 *
 * Réserve l'accès aux administrateurs, gère les actions POST (régénération
 * de token, rappel manuel, annulation de soumission, export CSV) et affiche
 * la liste paginée des demandes avec filtres.
 */
final class DashboardController extends BaseController
{
    /**
     * Point d'entrée du contrôleur — reproduit à l'identique la logique
     * historique de dashboard.php (sécurité, POST handlers, filtres,
     * pagination, rendu).
     */
    public function handle(): void
    {
        // Chargement du module de rendu spécifique au dashboard
        // (déclare dashboard_page_css() et render_dashboard_content()).
        require_once __DIR__ . '/../../lib/render_dashboard.php';

        // Sécurité : le dashboard est réservé aux administrateurs
        App::auth()->requireAdmin();

        $pdo     = $this->db->getPdo();
        $filtre  = $_GET['statut'] ?? 'tous';
        $form_f  = $_GET['form']   ?? '';
        $search  = $_GET['search'] ?? '';
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $per_page = 25;

        // Sécurité (A-01) : valider les entrées utilisateur
        try {
            if ($filtre !== 'tous' && $filtre !== 'complet') {
                $filtre = validate_input($filtre, 'status');
            }
            if ($form_f) {
                $form_f = validate_input($form_f, 'slug', ['max_length' => 100]);
            }
            $page = validate_input($page, 'int', ['min' => 1, 'max' => 10000]);
        } catch (\InvalidArgumentException $e) {
            $filtre = 'tous';
            $form_f = '';
            $page   = 1;
        }
        // validate_input retourne string|int ; on force int pour les calculs ultérieurs
        $page   = (int) $page;
        // Normalisation : $_GET peut théoriquement contenir array|float|false
        $filtre = (string) $filtre;
        $form_f = is_scalar($form_f) ? (string) $form_f : '';
        $search = is_scalar($search) ? (string) $search : '';

        // Export CSV
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $options = [];
            if ($form_f) {
                $f_stmt = $pdo->prepare("SELECT id FROM forms WHERE slug = ?");
                $f_stmt->execute([$form_f]);
                $fid = $f_stmt->fetchColumn();
                if ($fid) {
                    $options['form_id'] = $fid;
                }
            }
            if ($filtre !== 'tous') {
                $options['status'] = $filtre === 'en_cours'
                    ? 'en_cours'
                    : ($filtre === 'valide'
                        ? 'valide'
                        : ($filtre === 'refuse' ? 'refuse' : ''));
            }
            \App\Core\App::audit()->log('export_csv', '', 'Export CSV des soumissions', '');
            export_csv($pdo, $options);
        }

        // Régénération de token (admin)
        $regen_msg = '';
        if (isset($_POST['action']) && $_POST['action'] === 'regenerate_token' && App::auth()->isAdmin()) {
            $this->security->requireCsrf();
            $token_id = trim($_POST['token_id'] ?? '');
            // Sécurité (S-07) : valider le format du token_id
            try {
                $token_id = validate_input($token_id, 'uuid');
            } catch (\InvalidArgumentException $e) {
                $regen_msg = 'Identifiant de token invalide.';
                $token_id  = '';
            }
            if ($token_id) {
                $result    = regenerate_token((string) $token_id);
                $regen_msg = $result['message'];
                /** @phpstan-ignore-next-line if.alwaysTrue */
                if (TEST_MODE) {
                    test_json_response(['action' => 'regenerate_token', 'result' => $result]);
                }
            }
        }

        // Rappel manuel (admin)
        $remind_msg = '';
        if (isset($_POST['action']) && $_POST['action'] === 'remind_one' && App::auth()->isAdmin()) {
            $this->security->requireCsrf();
            $token_id = trim($_POST['token_id'] ?? '');
            // Sécurité (S-07) : valider le format du token_id
            try {
                $token_id = validate_input($token_id, 'uuid');
            } catch (\InvalidArgumentException $e) {
                $remind_msg = 'Identifiant de token invalide.';
                $token_id   = '';
            }
            if ($token_id) {
                $result     = remind_one((string) $token_id);
                $remind_msg = $result['message'];
            }
        }

        // Annulation de soumission (admin ou agent)
        $cancel_msg = '';
        if (isset($_POST['action']) && $_POST['action'] === 'cancel_submission') {
            $this->security->requireCsrf();
            $sub_id = trim($_POST['submission_id'] ?? '');
            // Sécurité (S-07) : valider le format du submission_id
            try {
                $sub_id = validate_input($sub_id, 'uuid');
            } catch (\InvalidArgumentException $e) {
                $cancel_msg = 'Identifiant de soumission invalide.';
                $sub_id     = '';
            }
            if ($sub_id) {
                $confirmed = !empty($_POST['confirmed']);
                if (!$confirmed) {
                    header(
                        'Location: index.php?p=confirm_action&action=cancel_submission&submission_id='
                        . urlencode((string) $sub_id) . '&from=dashboard.phpfrom=index.php?p=dashboard'
                    );
                    exit;
                }
                $actor = $this->auth->getUser();
                // Vérifier que l'utilisateur est admin ou le propriétaire de la soumission
                $sub_stmt = $pdo->prepare("SELECT submitted_by FROM submissions WHERE id = ?");
                $sub_stmt->execute([$sub_id]);
                $sub_owner = $sub_stmt->fetchColumn();
                if (App::auth()->isAdmin() || $sub_owner === $actor) {
                    $result     = cancel_submission((string) $sub_id, $actor);
                    $cancel_msg = $result['message'];
                    /** @phpstan-ignore-next-line if.alwaysTrue */
                    if (TEST_MODE) {
                        test_json_response([
                            'action'         => 'cancel_submission',
                            'result'         => $result,
                            'submission_id'  => $sub_id,
                        ]);
                    }
                }
            }
        }

        // SQL Safety: $where[] conditions use only hardcoded column names and operators.
        // User input is always passed via prepared statement parameters (?).
        $where  = ['1=1'];
        $params = [];
        if ($filtre === 'en_cours') {
            $where[]  = 's.status = ?';
            $params[] = 'en_cours';
        }
        if ($filtre === 'valide') {
            $where[]  = 's.status = ?';
            $params[] = 'valide';
        }
        if ($filtre === 'refuse') {
            $where[]  = 's.status = ?';
            $params[] = 'refuse';
        }
        if ($filtre === 'complet') {
            $where[]  = 's.status != ?';
            $params[] = 'en_cours';
        }
        if ($form_f) {
            $where[]  = 'f.slug = ?';
            $params[] = $form_f;
        }
        if ($search) {
            $where[]  = '(s.submitted_by LIKE ? OR s.data LIKE ? OR f.label LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $where = implode(' AND ', $where);

        // Count total matching rows for pagination
        $count_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM submissions s JOIN forms f ON f.id = s.form_id WHERE $where"
        );
        $count_stmt->execute($params);
        $total_rows  = (int) $count_stmt->fetchColumn();
        $total_pages = max(1, (int) ceil($total_rows / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $stmt = $pdo->prepare(
            "SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM submissions s
             JOIN forms f ON f.id = s.form_id
             WHERE $where
             ORDER BY s.submitted_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // A-13: optimisé — était N+1 (1 requête get_tokens_for_submission() par ligne).
        // Batch fetch all tokens for all submissions on this page in one query,
        // indexed by submission_id.
        $tokens_by_submission = [];
        if (!empty($rows)) {
            $sub_ids      = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($sub_ids), '?'));
            $batch_stmt = $pdo->prepare(
                "SELECT t.submission_id, t.id, t.token, t.relance_count, t.expires_at,
                        t.email, t.done_at, t.sent_at, t.step_id,
                        st.label, st.label as step_label, st.ordre
                 FROM tokens t
                 JOIN steps st ON st.id = t.step_id
                 WHERE t.submission_id IN ($placeholders)
                 ORDER BY t.submission_id, st.ordre ASC, st.label ASC"
            );
            $batch_stmt->execute($sub_ids);
            foreach ($batch_stmt->fetchAll(\PDO::FETCH_ASSOC) as $trow) {
                $tokens_by_submission[$trow['submission_id']][] = $trow;
            }
        }

        // BACKLOG — Indicateur "Reste à traiter" : pour chaque soumission de la
        // page courante, détermine si des champs validator ne sont pas encore
        // remplis. Batch (2 requêtes SQL pour N soumissions) pour éviter le N+1.
        $rows_for_validator_status = [];
        foreach ($rows as $r) {
            $st = (string) ($r['status'] ?? 'en_cours');
            if ($st === 'en_cours' || $st === 'valide') {
                $rows_for_validator_status[] = $r;
            }
        }
        $validator_status_by_submission = get_validator_status_batch($pdo, $rows_for_validator_status);

        $forms  = _dbm_q($pdo, "SELECT * FROM forms WHERE actif=1 ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        $gstats = get_global_stats();
        $total  = $gstats['total'];
        $complet = $gstats['valide'] + $gstats['refuse'];
        $valide  = $gstats['valide'];
        $refuse  = $gstats['refuse'];

        // État du système — S5-B / Action 3
        $sys_smtp_host  = $this->settings->get('smtp_host', '');
        $sys_smtp_port  = (int) $this->settings->get('smtp_port', '25');
        $sys_smtp_ok    = false;
        $sys_smtp_label = 'Non configuré';
        if ($sys_smtp_host !== '') {
            /** @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue */
            if (defined('TEST_MODE') && TEST_MODE) {
                // Mode test : pas de live check (slow)
                $sys_smtp_ok    = true;
                $sys_smtp_label = 'OK';
            } else {
                // Test rapide : tentative de connexion TCP (timeout 1.5s)
                $sys_errno  = 0;
                $sys_errstr = '';
                $sys_fp = @fsockopen($sys_smtp_host, $sys_smtp_port, $sys_errno, $sys_errstr, 1.5);
                if ($sys_fp !== false) {
                    fclose($sys_fp);
                    $sys_smtp_ok    = true;
                    $sys_smtp_label = 'OK';
                } else {
                    $sys_smtp_label = 'Erreur';
                }
            }
        }

        // DB : OK par construction (la page s'est chargée → PDO fonctionne)
        $sys_db_ok = true;

        // Dernière sauvegarde : date du dernier backup_download/backup_restore
        $sys_last_backup = '—';
        try {
            $sys_bk_stmt = $pdo->prepare(
                "SELECT created_at FROM audit_log
                 WHERE action IN ('backup_download', 'backup_restore')
                 ORDER BY created_at DESC LIMIT 1"
            );
            $sys_bk_stmt->execute();
            $sys_bk_row = $sys_bk_stmt->fetchColumn();
            if ($sys_bk_row) {
                $sys_bk_ts = strtotime((string) $sys_bk_row);
                $sys_last_backup = $sys_bk_ts !== false ? date('d/m/Y', $sys_bk_ts) : '—';
            } else {
                // Fallback : date de dernière modification du fichier DB
                $sys_db_file = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../../db/workflow.db';
                if (file_exists($sys_db_file)) {
                    $sys_db_mtime = filemtime($sys_db_file);
                    $sys_last_backup = $sys_db_mtime !== false ? date('d/m/Y', $sys_db_mtime) : '—';
                }
            }
        } catch (\Exception $e) {
            $sys_last_backup = '—';
        }

        // ── RENDU ──────────────────────────────────────────────────────
        $sys = [
            'smtp_host'   => $sys_smtp_host,
            'smtp_port'   => $sys_smtp_port,
            'smtp_ok'     => $sys_smtp_ok,
            'smtp_label'  => $sys_smtp_label,
            'last_backup' => $sys_last_backup,
            'en_cours'    => $gstats['en_cours'] ?? 0,
        ];

        $stats = [
            'total'   => $total,
            'complet' => $complet,
            'valide'  => $valide,
            'refuse'  => $refuse,
        ];

        $filters = [
            'filtre'     => $filtre,
            'form'       => $form_f,
            'search'     => $search,
            'regen_msg'  => $regen_msg,
            'remind_msg' => $remind_msg,
            'cancel_msg' => $cancel_msg,
        ];

        $page_css = dashboard_page_css();
        $content  = render_dashboard_content(
            $sys,
            $stats,
            $filters,
            $forms,
            $rows,
            $tokens_by_submission,
            $validator_status_by_submission,
            $page,
            $total_pages
        );

        echo $this->renderPage(
            'Tableau de bord — Demandes en cours',
            'dashboard',
            $page_css,
            $content
        );
    }
}
