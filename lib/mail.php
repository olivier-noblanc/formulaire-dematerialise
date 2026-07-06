<?php
declare(strict_types=1);

/**
 * Email sending via PHPMailer.
 *
 * send_mail()          — envoi via PHPMailer (SMTP configuré via settings).
 *                        Retourne bool (compatibilité descendante).
 *                        Tente aussi d'écrire dans la table mail_log (v23+).
 *
 * send_mail_detailed() — variante qui retourne un tableau détaillé :
 *                        ['success' => bool, 'error' => string, 'smtp_log' => string, 'status' => string]
 *                        Utilisée par le test SMTP dans monitoring.php et
 *                        admin_settings.php pour permettre le diagnostic.
 *
 * build_mail_html()         — corps HTML du mail de validation (token + bouton)
 * render_email_template()   — template HTML wrapper (header/footer)
 * get_recent_mail_logs()    — récupère les N dernières entrées de mail_log
 * log_mail_attempt()        — insère une ligne dans mail_log (helper interne)
 *
 * @package lib
 */

use PHPMailer\PHPMailer\PHPMailer;

// ── MAIL ─────────────────────────────────────────────────────

/**
 * Envoie un email via PHPMailer. Retourne true en cas de succès, false sinon.
 *
 * Compatibilité descendante : la signature reste (string, string, string) -> bool.
 * Le détail de l'erreur et la conversation SMTP sont journalisés dans
 * la table mail_log (v23+) et dans error_log().
 */
function send_mail(string $to, string $subject, string $body): bool {
    $result = send_mail_detailed($to, $subject, $body);
    return $result['success'];
}

/**
 * Variante détaillée de send_mail() qui retourne un tableau avec :
 *  - 'success'  (bool)   : true si l'envoi a réussi
 *  - 'error'    (string) : message d'erreur lisible (vide si succès)
 *  - 'smtp_log' (string) : conversation SMTP complète (debug PHPMailer)
 *  - 'status'   (string) : 'sent' | 'error' | 'blocked' | 'dry_run' | 'cli_blocked' | 'rate_limited'
 *
 * @return array{success:bool,error:string,smtp_log:string,status:string}
 */
function send_mail_detailed(string $to, string $subject, string $body): array {
    $result = ['success' => false, 'error' => '', 'smtp_log' => '', 'status' => 'error'];
    $to = strtolower(trim($to));
    $actor = get_auth_user();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

    // Sécurité : valider l'adresse du destinataire
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $msg = "Adresse destinataire invalide : $to";
        error_log("send_mail() BLOQUÉ — $msg");
        log_mail_attempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
        $result['error'] = $msg;
        $result['status'] = 'blocked';
        return $result;
    }

    // Sécurité : limiter le nombre d'emails envoyés par IP (anti-spam)
    if (!rate_limit_check('send_mail', 20, 60)) {
        $msg = "Rate limit atteint pour IP " . $ip;
        error_log("send_mail() BLOQUÉ — $msg");
        log_mail_attempt($to, $subject, 'rate_limited', $msg, '', $actor, $ip);
        $result['error'] = $msg;
        $result['status'] = 'rate_limited';
        return $result;
    }

    // Mode test : intercepter les mails sans les envoyer
    /** @phpstan-ignore-next-line if.alwaysTrue */
    if (TEST_MODE) {
        $GLOBALS['_test_mails'][] = [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'time'    => gmdate('Y-m-d H:i:s'),
        ];
        log_mail_attempt($to, $subject, 'dry_run', 'Mode test (TEST_MODE)', '', $actor, $ip);
        return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
    }

    // Mode dry-run : aucun email réel n'est envoyé, tout est journalisé
    $dry_run = get_setting('mail_dry_run', '0') === '1';
    if ($dry_run) {
        error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
        app_log('mail_dry_run', 'mail:' . $to, "Email intercepté (dry-run) — Sujet : $subject");
        log_mail_attempt($to, $subject, 'dry_run', 'Mode dry-run activé (mail_dry_run=1)', '', $actor, $ip);
        return ['success' => true, 'error' => '', 'smtp_log' => '', 'status' => 'dry_run'];
    }

    // Vérification de l'adresse email avant envoi
    $verify_mode = get_setting('email_verify_mode', 'none');
    if ($verify_mode !== 'none') {
        $verification = verify_email($to);
        if (!$verification['ok']) {
            $msg = "Email non vérifié : " . $verification['detail'];
            error_log("send_mail() BLOQUÉ — $msg — destinataire: $to");
            app_log('mail_blocked', 'mail:' . $to, "Email bloqué (vérification échouée) — " . $verification['detail'] . " — Sujet : $subject");
            log_mail_attempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
            $result['error'] = $msg;
            $result['status'] = 'blocked';
            return $result;
        }
    }

    // Sécurité CLI : ne jamais envoyer d'emails réels depuis un contexte CLI
    // (scripts de test, remind.php, alert_check.php utilisent un envoi explicite)
    if (php_sapi_name() === 'cli' && !defined('CLI_MAIL_ALLOWED')) {
        $msg = "Envoi bloqué en CLI sans CLI_MAIL_ALLOWED";
        error_log("send_mail() $msg (destinataire: $to)");
        log_mail_attempt($to, $subject, 'cli_blocked', $msg, '', $actor, $ip);
        $result['error'] = $msg;
        $result['status'] = 'cli_blocked';
        return $result;
    }

    // ── Configuration SMTP ──
    $smtp_host = get_setting('smtp_host');
    $smtp_port = (int) get_setting('smtp_port', '25');
    $smtp_auth = get_setting('smtp_auth', '0') === '1';
    $smtp_user = get_setting('smtp_user', '');
    $smtp_pass = get_setting('smtp_pass', '');
    $smtp_secure = get_setting('smtp_secure', '');
    $smtp_from = get_setting('smtp_from');
    $smtp_from_name = get_setting('smtp_from_name');

    if (empty($smtp_host)) {
        $msg = "Aucun hôte SMTP configuré (smtp_host vide)";
        error_log("send_mail() BLOQUÉ — $msg");
        log_mail_attempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
        $result['error'] = $msg;
        $result['status'] = 'blocked';
        return $result;
    }
    if (empty($smtp_from)) {
        $msg = "Aucune adresse From configurée (smtp_from vide)";
        error_log("send_mail() BLOQUÉ — $msg");
        log_mail_attempt($to, $subject, 'blocked', $msg, '', $actor, $ip);
        $result['error'] = $msg;
        $result['status'] = 'blocked';
        return $result;
    }

    // ── Capture de la conversation SMTP pour debug ──
    // On active SMTPDebug niveau 3 (CONNECTION + SERVER + CLIENT) et on
    // redirige vers un callback qui accumule les lignes dans $smtp_log_buf.
    $smtp_log_buf = [];
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->Port       = $smtp_port;
        $mail->SMTPAuth   = $smtp_auth;
        if ($mail->SMTPAuth) {
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
        }
        // Chiffrement explicite
        if ($smtp_secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtp_secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        // SMTPAutoTLS : true par défaut dans PHPMailer — on l'explicite pour
        // éviter les surprises si la valeur par défaut change.
        $mail->SMTPAutoTLS = ($smtp_secure === 'tls' || $smtp_secure === 'ssl');

        // Timeouts — éviter de bloquer indéfiniment si le serveur SMTP ne répond pas.
        // Timeout = timeout de connexion SMTP (en secondes), propriété de PHPMailer.
        // 30s est un compromis : assez long pour les SMTP distants saturés,
        // assez court pour ne pas bloquer l'UI pendant des minutes.
        $mail->Timeout = 30;
        // Timelimit = timeout de lecture SMTP (propriété de la classe SMTP, PAS PHPMailer).
        // En PHP 8.4, créer une propriété dynamique sur PHPMailer ($mail->Timelimit)
        // déclenche un warning de dépréciation. On accède donc via getSMTPInstance().
        $mail->getSMTPInstance()->Timelimit = 15;

        // Debug : capturer la conversation SMTP complète
        $mail->SMTPDebug  = 3; // 3 = CONNECTION + SERVER + CLIENT
        $mail->Debugoutput = function(string $str, int $level) use (&$smtp_log_buf): void {
            // PHPMailer appelle ce callback avec $str déjà formaté.
            $smtp_log_buf[] = '[' . $level . '] ' . rtrim($str);
        };

        $mail->CharSet  = 'UTF-8';
        $mail->setFrom($smtp_from, $smtp_from_name);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body;
        $mail->send();

        // Succès
        $smtp_log = implode("\n", $smtp_log_buf);
        app_log('mail_sent', 'mail:' . $to, "Email envoyé — Sujet : $subject");
        log_mail_attempt($to, $subject, 'sent', '', $smtp_log, $actor, $ip);
        return ['success' => true, 'error' => '', 'smtp_log' => $smtp_log, 'status' => 'sent'];
    } catch (Exception $e) {
        $smtp_log = implode("\n", $smtp_log_buf);
        $err = $mail->ErrorInfo;
        error_log('Mail error: ' . $err);
        app_log('mail_error', 'mail:' . $to, "Échec envoi — " . $err . " — Sujet : $subject");
        log_mail_attempt($to, $subject, 'error', $err, $smtp_log, $actor, $ip);
        return ['success' => false, 'error' => $err, 'smtp_log' => $smtp_log, 'status' => 'error'];
    }
}

/**
 * Insère une ligne dans la table mail_log (si elle existe).
 * Échoue silencieusement si la table n'existe pas encore (avant v23) ou si
 * la DB n'est pas disponible — ne doit jamais casser l'envoi de mail.
 */
function log_mail_attempt(string $to, string $subject, string $status, string $error, string $smtp_log, string $actor, string $ip): void {
    static $table_exists = null;
    try {
        $pdo = get_pdo();
        // Vérifier l'existence de la table une seule fois par requête
        if ($table_exists === null) {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
            );
            $cnt = $stmt !== false ? (int)$stmt->fetchColumn() : 0;
            $table_exists = ($cnt > 0);
        }
        if (!$table_exists) return;

        // Tronquer smtp_log si trop long (max ~32 Ko pour éviter de gorger la DB)
        if (strlen($smtp_log) > 32000) {
            $smtp_log = substr($smtp_log, 0, 32000) . "\n... (tronqué)";
        }
        // Tronquer aussi le sujet et l'erreur
        $subject = mb_substr($subject, 0, 500, 'UTF-8');
        $error = mb_substr($error, 0, 2000, 'UTF-8');

        $pdo->prepare("INSERT INTO mail_log (id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                generate_uuid(),
                gmdate('Y-m-d H:i:s'),
                $to,
                $subject,
                $status,
                $error,
                $smtp_log,
                $actor,
                $ip,
            ]);
    } catch (Exception $e) {
        // Ne jamais casser l'envoi de mail à cause d'un log qui échoue
        error_log('log_mail_attempt error: ' . $e->getMessage());
    }
}

/**
 * Récupère les N dernières entrées de mail_log pour affichage dans l'UI.
 * Retourne un tableau vide si la table n'existe pas.
 *
 * @return array<int, array<string, mixed>>
 */
function get_recent_mail_logs(int $limit = 30): array {
    static $table_exists = null;
    try {
        $pdo = get_pdo();
        if ($table_exists === null) {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
            );
            $cnt = $stmt !== false ? (int)$stmt->fetchColumn() : 0;
            $table_exists = ($cnt > 0);
        }
        if (!$table_exists) return [];

        $stmt = $pdo->prepare("SELECT * FROM mail_log ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('get_recent_mail_logs error: ' . $e->getMessage());
        return [];
    }
}

/** @param array<string, mixed> $submission */
function build_mail_html(array $submission, string $step_label, string $token): string {
    $data         = json_decode($submission['data'], true);
    // ATTENTION : ne PAS échapper $form_label ici avec h() — il est passé à
    // render_email_template() qui fait déjà h($title). Un double-échappement
    // transformerait l'apostrophe ' en &#039; puis en &amp;#039; → affiché
    // littéralement comme "&#039;" dans le mail reçu par l'utilisateur.
    $form_label   = (string)($submission['form_label'] ?? '');
    $validate_url = resolve_base_url() . '/index.php?p=validate&token=' . urlencode($token);

    $lignes = '';
    foreach ($data as $k => $v) {
        if (empty($v) || $k === 'validations') continue;
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        $label  = h(ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', (string)$k))));
        $valeur = $v === '1' ? '✓' : h((string)$v);
        $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
    }

    $body_html = '<p style="color:#555;margin-bottom:16px;">Étape : <strong>' . h($step_label) . '</strong></p>'
        . '<table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>'
        . '<a href="' . $validate_url . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">'
        . '✓ Marquer comme effectué</a>'
        . '<p style="font-size:12px;color:#999;margin-top:8px;">Lien à usage unique — ' . h(get_setting('smtp_from')) . '</p>';

    // $form_label est brut (non échappé) — render_email_template() fait h($title).
    return render_email_template($form_label . ' — Action requise', $body_html);
}

/**
 * Génère le HTML d'un email avec le template standard de l'application.
 * @param string $title Titre de l'email (dans le h2)
 * @param string $body_html Contenu HTML du corps de l'email
 * @return string HTML complet de l'email
 */
function render_email_template(string $title, string $body_html): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">' . h($title) . '</h2>
  ' . $body_html . '
  <p style="font-size:12px;color:#999;margin-top:24px;">' . h(get_app_name()) . ' — Ne pas répondre</p>
</body></html>';
}
