<?php
require_once __DIR__ . '/config.php';
// Tentative d'inclusion de vendor/autoload.php, mais ignorée si non présente
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

// ── UTILITAIRES ──────────────────────────────────────────────
function get_auth_user(): string {
    $auth_user = $_SERVER['AUTH_USER'] ?? '';
    if (empty($auth_user)) {
        return 'Utilisateur inconnu';
    }
    
    // Si l'utilisateur est au format DOMAINE\login, on extrait le login et on le transforme en email
    if (strpos($auth_user, '\\') !== false) {
        $parts = explode('\\', $auth_user);
        $login = end($parts);
        return strtolower($login) . '@dreets.gouv.fr';
    }
    
    // Sinon, on suppose que c'est déjà un email
    return strtolower($auth_user);
}

// Vérifie si l'utilisateur est administrateur
function is_admin_user(): bool {
    $auth_user = get_auth_user();
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
    $stmt->execute([$auth_user]);
    return $stmt->fetch() !== false;
}

// Vérifie si l'utilisateur est l'admin principal
function is_super_admin(): bool {
    $auth_user = get_auth_user();
    return $auth_user === ADMIN_EMAIL;
}

// ── PDO ──────────────────────────────────────────────────────
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
    }
    return $pdo;
}

function generate_token(): string {
    return bin2hex(random_bytes(32));
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// ── MAIL ─────────────────────────────────────────────────────
function send_mail(string $to, string $subject, string $body): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = SMTP_HOST;
        $mail->Port     = SMTP_PORT;
        $mail->SMTPAuth = false;
        $mail->CharSet  = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

function build_mail_html(array $submission, string $step_label, string $token): string {
    $data         = json_decode($submission['data'], true);
    $nom          = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $form_label   = h($submission['form_label'] ?? '');
    $validate_url = BASE_URL . '/validate.php?token=' . urlencode($token);

    $lignes = '';
    foreach ($data as $k => $v) {
        if (empty($v) || $v === '0') continue;
        $label  = ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)));
        $valeur = $v === '1' ? '✓' : h((string)$v);
        $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
    }

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">' . $form_label . ' — Action requise</h2>
  <p style="color:#555;margin-bottom:16px;">Étape : <strong>' . h($step_label) . '</strong></p>
  <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>
  <a href="' . $validate_url . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">
    ✓ Marquer comme effectué
  </a>
  <p style="font-size:12px;color:#999;margin-top:24px;">Lien à usage unique — ' . SMTP_FROM . '</p>
</body></html>';
}

// ── MOTEUR WORKFLOW ───────────────────────────────────────────

/**
 * Déclenche la prochaine étape d'une soumission.
 * Appelé à la création ET après chaque validation.
 *
 * Logique :
 *  - Récupère toutes les étapes du formulaire triées par ordre
 *  - Trouve le plus petit ordre sans tokens générés = prochaine étape
 *  - Si ordre précédent non terminé (séquentiel) → on attend
 *  - Génère les tokens pour tous les destinataires de l'étape courante
 *  - Si plus aucune étape → soumission close
 */
function advance_workflow(int $submission_id): void {
    $pdo = get_pdo();

    $sub = $pdo->prepare("
        SELECT s.*, f.label as form_label
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE s.id = ?
    ");
    $sub->execute([$submission_id]);
    $submission = $sub->fetch(PDO::FETCH_ASSOC);
    if (!$submission || $submission['closed_at']) return;

    // Toutes les étapes actives du formulaire
    $steps = $pdo->prepare("
        SELECT st.*, GROUP_CONCAT(sr.email, '|') as emails
        FROM steps st
        JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $steps->execute([$submission['form_id']]);
    $all_steps = $steps->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_steps)) return;

    // Tokens déjà générés pour cette soumission
    $existing = $pdo->prepare("SELECT step_id, done_at FROM tokens WHERE submission_id = ?");
    $existing->execute([$submission_id]);
    $tokens_by_step = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tokens_by_step[$t['step_id']][] = $t['done_at'];
    }

    // Groupe les étapes par ordre
    $by_ordre = [];
    foreach ($all_steps as $step) {
        $by_ordre[$step['ordre']][] = $step;
    }
    ksort($by_ordre);

    foreach ($by_ordre as $ordre => $groupe) {
        $step_ids    = array_column($groupe, 'id');
        $all_started = count(array_intersect($step_ids, array_keys($tokens_by_step))) === count($step_ids);
        $all_done    = $all_started && array_reduce($step_ids, function($carry, $sid) use ($tokens_by_step) {
            if (!$carry) return false;
            if (!isset($tokens_by_step[$sid])) return false;
            foreach ($tokens_by_step[$sid] as $done) {
                if (empty($done)) return false;
            }
            return true;
        }, true);

        if (!$all_started) {
            // Cette étape n'a pas encore de tokens → on la démarre (parallèle dans le groupe)
            $now = date('Y-m-d H:i:s');
            foreach ($groupe as $step) {
                $emails = explode('|', $step['emails']);
                foreach ($emails as $email) {
                    $token = generate_token();
                    $pdo->prepare("INSERT INTO tokens (submission_id, step_id, email, token, sent_at) VALUES (?,?,?,?,?)")
                        ->execute([$submission_id, $step['id'], $email, $token, $now]);
                    $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['label'];
                    send_mail($email, $subject, build_mail_html($submission, $step['label'], $token));
                }
            }
            return; // On attend que cette étape soit terminée avant de passer à la suivante
        }

        if (!$all_done) {
            return; // Étape en cours, on attend
        }

        // Étape terminée → on continue la boucle vers l'ordre suivant
    }

    // Toutes les étapes sont terminées → on close
    $pdo->prepare("UPDATE submissions SET closed_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $submission_id]);
}

/**
 * Appelé par validate.php quand un token est validé.
 * Met à jour done_at puis avance le workflow.
 */
function validate_token(string $token): array {
    $pdo = get_pdo();

    $row = $pdo->prepare("
        SELECT t.*, st.label as step_label, s.form_id,
               f.label as form_label, s.data, s.closed_at
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.token = ?
    ");
    $row->execute([$token]);
    $t = $row->fetch(PDO::FETCH_ASSOC);

    if (!$t)             return ['status' => 'invalid'];
    if ($t['done_at'])   return ['status' => 'already_done', 'data' => $t];
    if ($t['closed_at']) return ['status' => 'closed',       'data' => $t];

    $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ?")
        ->execute([date('Y-m-d H:i:s'), $token]);

    advance_workflow($t['submission_id']);

    $t['done_at'] = date('Y-m-d H:i:s');
    return ['status' => 'ok', 'data' => $t];
}

// ── ADMIN FUNCTIONS ───────────────────────────────────────────

/**
 * Traite une demande d'accès admin
 */
function process_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    // Vérifie si l'utilisateur est déjà admin
    if (is_admin_user()) {
        return true;
    }
    
    // Vérifie si une demande est déjà en attente
    $stmt = $pdo->prepare("SELECT 1 FROM admin_requests WHERE email = ? AND status = 'pending'");
    $stmt->execute([$email]);
    if ($stmt->fetch() !== false) {
        return false;
    }
    
    // Génère un token pour la demande
    $token = bin2hex(random_bytes(32));
    
    // Insère la demande dans la base de données
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_requests (email, requested_at, status, token) VALUES (?, ?, 'pending', ?)");
        $stmt->execute([$email, date('Y-m-d H:i:s'), $token]);
        
        // Envoie un email à l'admin principal pour approbation
        $approve_url = BASE_URL . '/admin_access.php?action=approve&token=' . $token;
        $reject_url = BASE_URL . '/admin_access.php?action=reject&token=' . $token;
        $subject = 'Demande d\'accès admin - Workflow DREETS';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin</h2>
    <p>Un utilisateur a demandé l\'accès admin au back office du workflow :</p>
    <p><strong>Utilisateur :</strong> ' . h($email) . '</p>
    <p><strong>Date :</strong> ' . date('d/m/Y H:i:s') . '</p>
    <p><a href="' . $approve_url . '" style="background:#1a6b3c;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px;">Approuver</a>
    <a href="' . $reject_url . '" style="background:#c0392b;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;">Refuser</a></p>
</body>
</html>';
        
        send_mail(ADMIN_EMAIL, $subject, $body);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de la demande d\'accès admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Approve an admin request
 */
function approve_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'approved' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Ajoute l'utilisateur comme administrateur
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO admins (email, added_at) VALUES (?, ?)");
        $stmt->execute([$email, date('Y-m-d H:i:s')]);
        
        // Envoie un email de confirmation
        $subject = 'Accès admin approuvé - Workflow DREETS';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Accès admin approuvé</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été approuvée.</p>
    <p>Vous pouvez maintenant accéder au back office en cliquant sur le lien ci-dessous :</p>
    <p><a href="' . BASE_URL . '/admin_access.php">Accéder au back office</a></p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de l\'approbation de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Reject an admin request
 */
function reject_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'rejected' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Envoie un email de refus
        $subject = 'Demande d\'accès admin refusée - Workflow DREETS';
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin refusée</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été refusée.</p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors du refus de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove an admin
 */
function remove_admin(string $email): bool {
    $pdo = get_pdo();
    
    // Ne peut pas supprimer l'admin principal
    if ($email === ADMIN_EMAIL) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
        return false;
    }
}
