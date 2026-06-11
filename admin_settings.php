<?php
// admin_settings.php — Page de configuration SMTP et paramètres
require_once __DIR__ . '/helpers.php';

// Vérification des droits d'accès
if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$success_msg = '';
$error_msg = '';
$test_msg = '';

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verify_csrf()) {
        die('Token CSRF invalide. Veuillez réessayer.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $updated_by = get_auth_user();
        $settings = [
            'smtp_host'        => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'        => trim($_POST['smtp_port'] ?? '25'),
            'smtp_auth'        => isset($_POST['smtp_auth']) ? '1' : '0',
            'smtp_secure'      => trim($_POST['smtp_secure'] ?? ''),
            'smtp_user'        => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'        => trim($_POST['smtp_pass'] ?? ''),
            'smtp_from'        => trim($_POST['smtp_from'] ?? ''),
            'smtp_from_name'   => trim($_POST['smtp_from_name'] ?? ''),
            'delai_relance_h'  => trim($_POST['delai_relance_h'] ?? '48'),
            'token_expire_days'=> trim($_POST['token_expire_days'] ?? '30'),
            'relance_max'      => trim($_POST['relance_max'] ?? '3'),
        ];

        // Conserver l'ancien mot de passe si le champ est vide
        if (empty($settings['smtp_pass'])) {
            $settings['smtp_pass'] = get_setting('smtp_pass', '');
        }

        try {
            foreach ($settings as $key => $value) {
                set_setting($key, $value, $updated_by);
            }
            $success_msg = 'Paramètres enregistrés avec succès.';
        } catch (Exception $e) {
            $error_msg = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
        }
    }
    elseif ($action === 'test_email') {
        $to = get_auth_user();
        $subject = 'Test email — Workflow DREETS';
        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis la page de paramètres du workflow.</p>
  <p>Date : ' . h(date('d/m/Y H:i:s')) . '</p>
</body></html>';
        if (send_mail($to, $subject, $body)) {
            $test_msg = 'Email de test envoyé avec succès à ' . h($to);
        } else {
            $test_msg = 'Échec de l\'envoi de l\'email de test. Vérifiez la configuration SMTP et les logs.';
        }
    }
}

// Lecture des paramètres actuels
$smtp_host        = get_setting('smtp_host', SMTP_HOST);
$smtp_port        = get_setting('smtp_port', (string)SMTP_PORT);
$smtp_auth        = get_setting('smtp_auth', '0');
$smtp_secure      = get_setting('smtp_secure', '');
$smtp_user        = get_setting('smtp_user', '');
$smtp_pass        = get_setting('smtp_pass', '');
$smtp_from        = get_setting('smtp_from', SMTP_FROM);
$smtp_from_name   = get_setting('smtp_from_name', SMTP_FROM_NAME);
$delai_relance_h  = get_setting('delai_relance_h', (string)DELAI_RELANCE_H);
$token_expire_days= get_setting('token_expire_days', '30');
$relance_max      = get_setting('relance_max', '3');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paramètres — DREETS Workflow</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .container { max-width: 800px; margin: 0 auto; padding: 0 1rem 2rem; }
        h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; }
        .btn { padding: .6rem 1.2rem; border: none; border-radius: 3px; font-size: .9rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #003189; color: #fff; }
        .btn-primary:hover { background: #002270; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-test { background: #27ae60; color: #fff; }
        .btn-test:hover { background: #219a52; }
        .msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
        .msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
        .msg-info { background: #e3f2fd; border: 1px solid #1976d2; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1565c0; }
        .field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
        label { font-size: .85rem; font-weight: bold; color: #444; }
        .hint { font-size: .78rem; color: #888; font-weight: normal; }
        input[type="text"], input[type="number"], input[type="password"], select {
            padding: .6rem; border: 1px solid #aaa; border-radius: 3px;
            font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
        }
        input:focus, select:focus { outline: 2px solid #003189; border-color: #003189; }
        .checkbox-label { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
        .checkbox-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: #003189; }
        .form-actions { display: flex; gap: .5rem; margin-top: 1rem; }
    </style>
</head>
<body>
<div class="bandeau">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
    <span><a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a> <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a></span>
</div>
<div class="container">
    <h1>⚙ Paramètres</h1>

    <?php if ($success_msg): ?>
        <div class="msg-success"><?= h($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="msg-error"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($test_msg): ?>
        <div class="msg-info"><?= h($test_msg) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">

        <!-- Paramètres SMTP -->
        <div class="card">
            <h2>Configuration SMTP</h2>

            <div class="field">
                <label>SMTP Hôte</label>
                <input type="text" name="smtp_host" value="<?= h($smtp_host) ?>" placeholder="smtp.example.fr">
            </div>

            <div class="field">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= h($smtp_port) ?>" min="1" max="65535">
            </div>

            <div class="field">
                <label class="checkbox-label">
                    <input type="checkbox" name="smtp_auth" <?= $smtp_auth === '1' ? 'checked' : '' ?>>
                    Authentification SMTP
                </label>
            </div>

            <div class="field">
                <label>Chiffrement</label>
                <select name="smtp_secure">
                    <option value="" <?= $smtp_secure === '' ? 'selected' : '' ?>>Aucun</option>
                    <option value="tls" <?= $smtp_secure === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= $smtp_secure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                </select>
            </div>

            <div class="field">
                <label>Utilisateur SMTP <span class="hint">(utilisé uniquement si l'authentification est activée)</span></label>
                <input type="text" name="smtp_user" value="<?= h($smtp_user) ?>" placeholder="utilisateur@exemple.fr">
            </div>

            <div class="field">
                <label>Mot de passe SMTP <span class="hint">(laisser vide pour conserver l'actuel)</span></label>
                <input type="password" name="smtp_pass" placeholder="<?= $smtp_pass ? '••••••••' : '' ?>">
            </div>

            <div class="field">
                <label>Email expéditeur</label>
                <input type="text" name="smtp_from" value="<?= h($smtp_from) ?>" placeholder="workflow@dreets.gouv.fr">
            </div>

            <div class="field">
                <label>Nom expéditeur</label>
                <input type="text" name="smtp_from_name" value="<?= h($smtp_from_name) ?>" placeholder="Workflow DREETS">
            </div>
        </div>

        <!-- Paramètres du workflow -->
        <div class="card">
            <h2>Paramètres du workflow</h2>

            <div class="field">
                <label>Délai de relance en heures</label>
                <input type="number" name="delai_relance_h" value="<?= h($delai_relance_h) ?>" min="1">
            </div>

            <div class="field">
                <label>Expiration des tokens en jours</label>
                <input type="number" name="token_expire_days" value="<?= h($token_expire_days) ?>" min="1">
            </div>

            <div class="field">
                <label>Nombre maximum de relances par token <span class="hint">(0 = illimité)</span></label>
                <input type="number" name="relance_max" value="<?= h($relance_max) ?>" min="0">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
            <a href="dashboard.php" class="btn btn-secondary">Retour au tableau de bord</a>
        </div>
    </form>

    <!-- Test email -->
    <div class="card" style="margin-top:1.5rem;">
        <h2>Test d'envoi d'email</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">Envoyer un email de test à votre adresse (<?= h(get_auth_user()) ?>) pour vérifier la configuration SMTP.</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_email">
            <button type="submit" class="btn btn-test">Envoyer un email de test</button>
        </form>
    </div>
</div>
</body>
</html>
