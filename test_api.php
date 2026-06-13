<?php
/**
 * test_api.php — API interne pour les tests automatisés
 * Accessible UNIQUEMENT en mode test (header X-Test-Mode: 1)
 *
 * Endpoints :
 *   ?action=mails        — Liste les mails interceptés
 *   ?action=tokens       — Liste les tokens d'une soumission (param: submission_id)
 *   ?action=submission   — Détail d'une soumission (param: submission_id)
 *   ?action=submissions  — Liste toutes les soumissions
 *   ?action=cleanup      — Vide la DB test
 *   ?action=seed         — Reseed la DB test avec des données de test
 *   ?action=forms        — Liste les formulaires
 *   ?action=steps        — Liste les étapes d'un formulaire (param: form_id)
 *   ?action=add_admin    — Ajoute un admin (param: email)
 *   ?action=add_recipient— Ajoute un destinataire à une étape (params: step_id, email)
 *   ?action=stats        — Stats globales
 */
require_once __DIR__ . '/helpers.php';

// Sécurité : accessible uniquement en mode test
if (!TEST_MODE) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Mode test non activé. Envoyez le header X-Test-Mode: 1']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$pdo = get_pdo();

switch ($action) {
    case 'mails':
        echo json_encode([
            'count' => count($GLOBALS['_test_mails']),
            'mails' => $GLOBALS['_test_mails'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'reset_mails':
        reset_test_mails();
        echo json_encode(['ok' => true, 'message' => 'Mails réinitialisés']);
        break;

    case 'tokens':
        $submission_id = (int)($_GET['submission_id'] ?? 0);
        if (!$submission_id) {
            echo json_encode(['error' => 'Paramètre submission_id requis']);
            break;
        }
        $stmt = $pdo->prepare("
            SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at, t.done_at, t.expires_at,
                   st.label as step_label, st.ordre
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            WHERE t.submission_id = ?
            ORDER BY st.ordre, st.id
        ");
        $stmt->execute([$submission_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'submission':
        $submission_id = (int)($_GET['submission_id'] ?? 0);
        if (!$submission_id) {
            echo json_encode(['error' => 'Paramètre submission_id requis']);
            break;
        }
        $stmt = $pdo->prepare("
            SELECT s.*, f.label as form_label, f.slug as form_slug
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE s.id = ?
        ");
        $stmt->execute([$submission_id]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($sub) {
            $sub['data_decoded'] = json_decode($sub['data'], true);
        }
        echo json_encode($sub ?: ['error' => 'Soumission introuvable'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'submissions':
        $stmt = $pdo->query("
            SELECT s.id, s.form_id, s.submitted_by, s.submitted_at, s.status, s.closed_at,
                   f.label as form_label, f.slug as form_slug
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            ORDER BY s.submitted_at DESC
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'cleanup':
        // Supprimer toutes les soumissions, tokens, logs de la DB test
        $pdo->exec("DELETE FROM tokens");
        $pdo->exec("DELETE FROM submissions");
        $pdo->exec("DELETE FROM audit_log");
        $pdo->exec("DELETE FROM alert_log");
        $pdo->exec("DELETE FROM admin_requests");
        reset_test_mails();
        echo json_encode(['ok' => true, 'message' => 'DB test nettoyée']);
        break;

    case 'full_cleanup':
        // Supprimer TOUT et recréer (y compris forms, steps, etc.)
        $pdo->exec("DELETE FROM tokens");
        $pdo->exec("DELETE FROM submissions");
        $pdo->exec("DELETE FROM form_fields");
        $pdo->exec("DELETE FROM step_recipients");
        $pdo->exec("DELETE FROM steps");
        $pdo->exec("DELETE FROM forms");
        $pdo->exec("DELETE FROM audit_log");
        $pdo->exec("DELETE FROM alert_log");
        $pdo->exec("DELETE FROM admin_requests");
        $pdo->exec("DELETE FROM settings");
        $pdo->exec("DELETE FROM admins");
        // Re-migrer pour recréer les seeds
        db_migrate($pdo);
        reset_test_mails();
        echo json_encode(['ok' => true, 'message' => 'DB test réinitialisée (full)']);
        break;

    case 'forms':
        $stmt = $pdo->query("SELECT * FROM forms ORDER BY label");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'steps':
        $form_id = (int)($_GET['form_id'] ?? 0);
        if (!$form_id) {
            echo json_encode(['error' => 'Paramètre form_id requis']);
            break;
        }
        $stmt = $pdo->prepare("
            SELECT st.*, GROUP_CONCAT(sr.email, '|') as recipients
            FROM steps st
            LEFT JOIN step_recipients sr ON sr.step_id = st.id
            WHERE st.form_id = ?
            GROUP BY st.id
            ORDER BY st.ordre, st.id
        ");
        $stmt->execute([$form_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'add_admin':
        $email = trim($_GET['email'] ?? '');
        if (!$email) {
            echo json_encode(['error' => 'Paramètre email requis']);
            break;
        }
        $pdo->prepare("INSERT OR IGNORE INTO admins (email, added_at) VALUES (?, datetime('now'))")
            ->execute([$email]);
        echo json_encode(['ok' => true, 'message' => "Admin $email ajouté"]);
        break;

    case 'remove_admin':
        $email = trim($_GET['email'] ?? '');
        if (!$email) {
            echo json_encode(['error' => 'Paramètre email requis']);
            break;
        }
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
        echo json_encode(['ok' => true, 'message' => "Admin $email supprimé"]);
        break;

    case 'add_recipient':
        $step_id = (int)($_GET['step_id'] ?? 0);
        $email = trim($_GET['email'] ?? '');
        if (!$step_id || !$email) {
            echo json_encode(['error' => 'Paramètres step_id et email requis']);
            break;
        }
        $pdo->prepare("INSERT INTO step_recipients (step_id, email) VALUES (?, ?)")
            ->execute([$step_id, $email]);
        echo json_encode(['ok' => true, 'message' => "Destinataire $email ajouté à l'étape $step_id"]);
        break;

    case 'stats':
        $stats = [
            'forms'       => (int)$pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn(),
            'submissions'  => (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn(),
            'en_cours'     => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'en_cours'")->fetchColumn(),
            'valide'       => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'valide'")->fetchColumn(),
            'refuse'       => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'refuse'")->fetchColumn(),
            'tokens'       => (int)$pdo->query("SELECT COUNT(*) FROM tokens")->fetchColumn(),
            'tokens_done'  => (int)$pdo->query("SELECT COUNT(*) FROM tokens WHERE done_at IS NOT NULL")->fetchColumn(),
            'tokens_pending' => (int)$pdo->query("SELECT COUNT(*) FROM tokens WHERE done_at IS NULL")->fetchColumn(),
            'admins'       => (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn(),
            'mails_sent'   => count($GLOBALS['_test_mails']),
            'test_mode'    => true,
        ];
        echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    default:
        echo json_encode([
            'error' => 'Action inconnue',
            'available_actions' => [
                'mails', 'reset_mails', 'tokens', 'submission', 'submissions',
                'cleanup', 'full_cleanup', 'forms', 'steps', 'add_admin',
                'remove_admin', 'add_recipient', 'stats'
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
