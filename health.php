<?php
// health.php — Point de contrôle de santé pour monitoring
// Accessible sans authentification (utilisé par les outils de supervision)
// Retourne HTTP 200 si sain, HTTP 503 si problème détecté
require_once __DIR__ . '/helpers.php';

$checks = [];
$all_healthy = true;

// 1. Base de données SQLite accessible
$db_ok = false;
$db_detail = '';
try {
    $pdo = get_pdo();
    $test = $pdo->query("SELECT 1")->fetchColumn();
    $db_ok = ($test === 1 || $test === '1');
    $db_detail = 'Connexion SQLite OK';
} catch (Exception $e) {
    $db_detail = 'Erreur : ' . $e->getMessage();
}
if (!$db_ok) $all_healthy = false;
$checks[] = ['label' => 'Base de données SQLite', 'ok' => $db_ok, 'detail' => $db_detail];

// 2. Version PHP
$php_version = PHP_VERSION;
$php_ok = version_compare($php_version, '8.0.0', '>=');
$php_detail = 'PHP ' . $php_version . ($php_ok ? '' : ' (minimum requis : 8.0)');
if (!$php_ok) $all_healthy = false;
$checks[] = ['label' => 'Version PHP', 'ok' => $php_ok, 'detail' => $php_detail];

// 3. Répertoire db/ accessible en écriture
$db_path = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db';
$db_dir = dirname($db_path);
$dir_writable = is_writable($db_dir);
$dir_detail = $dir_writable ? 'Répertoire ' . basename($db_dir) . '/ accessible en écriture' : 'Répertoire ' . basename($db_dir) . '/ non accessible en écriture';
if (!$dir_writable) $all_healthy = false;
$checks[] = ['label' => 'Répertoire de données', 'ok' => $dir_writable, 'detail' => $dir_detail];

// 4. Schéma de base de données initialisé
$schema_ok = false;
$schema_detail = '';
try {
    $pdo = get_pdo();
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['forms', 'submissions', 'tokens', 'settings', 'audit_log'];
    $missing = array_diff($required, $tables);
    if (empty($missing)) {
        $schema_ok = true;
        $schema_detail = count($tables) . ' tables présentes';
    } else {
        $schema_detail = 'Tables manquantes : ' . implode(', ', $missing);
    }
} catch (Exception $e) {
    $schema_detail = 'Erreur : ' . $e->getMessage();
}
if (!$schema_ok) $all_healthy = false;
$checks[] = ['label' => 'Schéma de base de données', 'ok' => $schema_ok, 'detail' => $schema_detail];

// 5. Configuration SMTP présente
$smtp_ok = false;
$smtp_detail = '';
try {
    $pdo = get_pdo();
    $smtp_host = get_setting('smtp_host', '');
    $smtp_ok = !empty($smtp_host);
    $smtp_detail = $smtp_ok ? 'Hôte SMTP configuré : ' . $smtp_host : 'Aucun hôte SMTP configuré';
} catch (Exception $e) {
    $smtp_detail = 'Erreur de lecture';
}
if (!$smtp_ok) $all_healthy = false;
$checks[] = ['label' => 'Configuration SMTP', 'ok' => $smtp_ok, 'detail' => $smtp_detail];

// Set HTTP status
$http_status = $all_healthy ? 200 : 503;
http_response_code($http_status);

// JSON output for monitoring tools
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $all_healthy ? 'healthy' : 'unhealthy',
        'version' => APP_VERSION,
        'timestamp' => date('c'),
        'checks' => array_map(function($c) {
            return ['label' => $c['label'], 'status' => $c['ok'] ? 'ok' : 'error', 'detail' => $c['detail']];
        }, $checks),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Santé système — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    body { padding: 2rem 1rem; }
    .container { max-width: 700px; padding: 0; }
    .check-item { display: flex; align-items: center; gap: 1rem; padding: .75rem 1rem; border-bottom: 1px solid #eee; }
    .check-item:last-child { border-bottom: none; }
    .check-icon { font-size: 1.5rem; flex-shrink: 0; }
    .check-content { flex: 1; }
    .check-label { font-weight: bold; font-size: .95rem; }
    .check-detail { font-size: .8rem; color: #595959; margin-top: .15rem; }
    .status-banner { padding: 1.5rem; text-align: center; border-radius: 6px; margin-bottom: 1.5rem; }
    .status-banner.healthy { background: #e8f5e9; border: 2px solid #1a6b3c; }
    .status-banner.unhealthy { background: #fde8e8; border: 2px solid #c0392b; }
    .status-banner h2 { border: none; padding: 0; margin: 0; font-size: 1.3rem; }
    .status-banner.healthy h2 { color: #1a6b3c; }
    .status-banner.unhealthy h2 { color: #c0392b; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('health') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Santé système']]) ?>
  <h1>Santé du système</h1>

  <div class="status-banner <?= $all_healthy ? 'healthy' : 'unhealthy' ?>">
    <h2><?= $all_healthy ? '<span aria-hidden="true">✓</span> Système opérationnel' : '<span aria-hidden="true">⚠</span> Problème détecté' ?></h2>
    <p style="margin-top:.5rem;color:#555;">v<?= h(APP_VERSION) ?> — <?= h(date('d/m/Y à H:i')) ?></p>
  </div>

  <div class="card" style="padding:0;overflow:hidden;">
    <?php foreach ($checks as $check): ?>
    <div class="check-item">
      <div class="check-icon" aria-label="<?= $check['ok'] ? 'Succès' : 'Échec' ?>"><?= $check['ok'] ? '✅' : '❌' ?></div>
      <div class="check-content">
        <div class="check-label"><?= h($check['label']) ?></div>
        <div class="check-detail"><?= h($check['detail']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p style="text-align:center;margin-top:1.5rem;font-size:.8rem;color:#595959;">
    Endpoint de monitoring : <code>health.php?format=json</code>
  </p>
</main>
</body>
</html>
