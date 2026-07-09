<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page de santé système (health.php).
 *
 * Point de contrôle de santé pour monitoring.
 * Accessible sans authentification (utilisé par les outils de supervision).
 * Retourne HTTP 200 si sain, HTTP 503 si problème détecté.
 */
final class HealthController extends BaseController
{
    public function handle(): void
    {
        $checks = [];
        $allHealthy = true;

        // 1. Base de données SQLite accessible
        $dbOk = false;
        $dbDetail = '';
        try {
            $pdo = $this->db->getPdo();
            $test = $pdo->prepare("SELECT 1");
            $test->execute();
            $dbOk = $test->fetchColumn() === '1';
            $dbDetail = 'Connexion SQLite OK';
        } catch (\Exception $e) {
            $dbDetail = 'Erreur : ' . $e->getMessage();
        }
        if (!$dbOk) $allHealthy = false;
        $checks[] = ['label' => 'Base de données SQLite', 'ok' => $dbOk, 'detail' => $dbDetail];

        // 2. Version PHP
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.0.0', '>=');
        $phpDetail = 'PHP ' . $phpVersion . ($phpOk ? '' : ' (minimum requis : 8.0)');
        if (!$phpOk) $allHealthy = false;
        $checks[] = ['label' => 'Version PHP', 'ok' => $phpOk, 'detail' => $phpDetail];

        // 3. Répertoire db/ accessible en écriture
        $dbPath = defined('DB_PATH') ? DB_PATH : dirname(__DIR__, 2) . '/db/workflow.db';
        $dbDir = dirname($dbPath);
        $dirWritable = is_writable($dbDir);
        $dirDetail = $dirWritable ? 'Répertoire ' . basename($dbDir) . '/ accessible en écriture' : 'Répertoire ' . basename($dbDir) . '/ non accessible en écriture';
        if (!$dirWritable) $allHealthy = false;
        $checks[] = ['label' => 'Répertoire de données', 'ok' => $dirWritable, 'detail' => $dirDetail];

        // 4. Schéma de base de données initialisé
        $schemaOk = false;
        $schemaDetail = '';
        try {
            $pdo = $this->db->getPdo();
            $tablesStmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            $tablesStmt->execute();
            $tables = $tablesStmt->fetchAll(\PDO::FETCH_COLUMN);
            $required = ['forms', 'submissions', 'tokens', 'settings', 'audit_log'];
            $missing = array_diff($required, $tables);
            if (empty($missing)) {
                $schemaOk = true;
                $schemaDetail = count($tables) . ' tables présentes';
            } else {
                $schemaDetail = 'Tables manquantes : ' . implode(', ', $missing);
            }
        } catch (\Exception $e) {
            $schemaDetail = 'Erreur : ' . $e->getMessage();
        }
        if (!$schemaOk) $allHealthy = false;
        $checks[] = ['label' => 'Schéma de base de données', 'ok' => $schemaOk, 'detail' => $schemaDetail];

        // 5. Configuration SMTP présente
        $smtpOk = false;
        $smtpDetail = '';
        try {
            $smtpHost = $this->settings->get('smtp_host', '');
            $smtpOk = !empty($smtpHost);
            $smtpDetail = $smtpOk ? 'Hôte SMTP configuré : ' . $smtpHost : 'Aucun hôte SMTP configuré';
        } catch (\Exception $e) {
            $smtpDetail = 'Erreur de lecture';
        }
        if (!$smtpOk) $allHealthy = false;
        $checks[] = ['label' => 'Configuration SMTP', 'ok' => $smtpOk, 'detail' => $smtpDetail];

        // 6. Extensions PHP requises
        $requiredExt = ['mbstring', 'pdo_sqlite', 'sqlite3', 'json', 'session', 'pcre'];
        $missingExt = array_filter($requiredExt, fn($ext) => !extension_loaded($ext));
        $extOk = empty($missingExt);
        $extDetail = $extOk
            ? 'Toutes les extensions requises sont présentes (' . count($requiredExt) . ')'
            : 'Extensions manquantes : ' . implode(', ', $missingExt);
        if (!$extOk) $allHealthy = false;
        $checks[] = ['label' => 'Extensions PHP', 'ok' => $extOk, 'detail' => $extDetail];

        // Set HTTP status
        $httpStatus = $allHealthy ? 200 : 503;
        http_response_code($httpStatus);

        // JSON output for monitoring tools
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => $allHealthy ? 'healthy' : 'unhealthy',
                'version' => App::cache()->getLatestVersion(),
                'timestamp' => date('c'),
                'checks' => array_map(function ($c) {
                    return ['label' => $c['label'], 'status' => $c['ok'] ? 'ok' : 'error', 'detail' => $c['detail']];
                }, $checks),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $pageCss = '';
        ob_start();
        ?>
  <h1>Santé du système</h1>

  <div class="status-banner <?= $allHealthy ? 'healthy' : 'unhealthy' ?>">
    <h2><?= $allHealthy ? '<span aria-hidden="true">✓</span> Système opérationnel' : '<span aria-hidden="true">⚠</span> Problème détecté' ?></h2>
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
        echo $this->renderPage('Santé système', 'health', $pageCss, $content);
    }
}