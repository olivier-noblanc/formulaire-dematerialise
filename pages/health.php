<?php
// health.php — Point de contrôle de santé pour monitoring
// Accessible sans authentification (utilisé par les outils de supervision)
// Retourne HTTP 200 si sain, HTTP 503 si problème détecté
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

$checks = [];
$all_healthy = true;

// 1. Base de données SQLite accessible
$db_ok = false;
$db_detail = '';
try {
    $pdo = App::db()->getPdo();
    $test = _dbm_q($pdo, "SELECT 1")->fetchColumn();
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
$db_path = defined('DB_PATH') ? DB_PATH : dirname(__DIR__) . '/db/workflow.db';
$db_dir = dirname($db_path);
$dir_writable = is_writable($db_dir);
$dir_detail = $dir_writable ? 'Répertoire ' . basename($db_dir) . '/ accessible en écriture' : 'Répertoire ' . basename($db_dir) . '/ non accessible en écriture';
if (!$dir_writable) $all_healthy = false;
$checks[] = ['label' => 'Répertoire de données', 'ok' => $dir_writable, 'detail' => $dir_detail];

// 4. Schéma de base de données initialisé
$schema_ok = false;
$schema_detail = '';
try {
    $pdo = App::db()->getPdo();
    $tables = _dbm_q($pdo, "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
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
    $pdo = App::db()->getPdo();
    $smtp_host = \App\Core\App::settings()->get('smtp_host', '');
    $smtp_ok = !empty($smtp_host);
    $smtp_detail = $smtp_ok ? 'Hôte SMTP configuré : ' . $smtp_host : 'Aucun hôte SMTP configuré';
} catch (Exception $e) {
    $smtp_detail = 'Erreur de lecture';
}
if (!$smtp_ok) $all_healthy = false;
$checks[] = ['label' => 'Configuration SMTP', 'ok' => $smtp_ok, 'detail' => $smtp_detail];

// 6. Extensions PHP requises
$required_ext = ['mbstring', 'pdo_sqlite', 'sqlite3', 'json', 'session', 'pcre'];
$missing_ext = array_filter($required_ext, fn($ext) => !extension_loaded($ext));
$ext_ok = empty($missing_ext);
$ext_detail = $ext_ok
    ? 'Toutes les extensions requises sont présentes (' . count($required_ext) . ')'
    : 'Extensions manquantes : ' . implode(', ', $missing_ext);
if (!$ext_ok) $all_healthy = false;
$checks[] = ['label' => 'Extensions PHP', 'ok' => $ext_ok, 'detail' => $ext_detail];

// Set HTTP status
$http_status = $all_healthy ? 200 : 503;
http_response_code($http_status);

// JSON output for monitoring tools
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $all_healthy ? 'healthy' : 'unhealthy',
        'version' => App::cache()->getLatestVersion(),
        'timestamp' => date('c'),
        'checks' => array_map(function($c) {
            return ['label' => $c['label'], 'status' => $c['ok'] ? 'ok' : 'error', 'detail' => $c['detail']];
        }, $checks),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
<?php
$page_css = '';
ob_start();
?>
  <h1>Santé du système</h1>

  <div class="status-banner <?= $all_healthy ? 'healthy' : 'unhealthy' ?>">
    <h2><?= $all_healthy ? '<span aria-hidden="true">✓</span> Système opérationnel' : '<span aria-hidden="true">⚠</span> Problème détecté' ?></h2>
    <p style="margin-top:.5rem;color:#555;">v<?= h(App::cache()->getLatestVersion()) ?> — <?= h(date('d/m/Y à H:i')) ?></p>
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
<?php
$content = (string)ob_get_clean();
echo render_page('Santé système', 'health', $page_css, $content);
