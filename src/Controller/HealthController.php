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
 *
 * Sécurité : cet endpoint est public → les `detail` ne doivent JAMAIS contenir
 * de données internes sensibles (chemin de base, message d'exception brut,
 * hôte SMTP, destinataire/erreur d'un email). Les diagnostics détaillés restent
 * dans error_log() ; l'exposition publique se limite à un libellé générique.
 */
final class HealthController extends BaseController
{
    public function handle(): void
    {
        $result = $this->evaluate();
        $checks = $result['checks'];
        $allHealthy = $result['healthy'];

        // Set HTTP status
        http_response_code($result['http_status']);

        // JSON output for monitoring tools
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => $allHealthy ? 'healthy' : 'unhealthy',
                'version' => App::cache()->getLatestVersion(),
                'timestamp' => date('c'),
                'checks' => array_map(fn(array $c): array => ['label' => $c['label'], 'status' => $c['ok'] ? 'ok' : 'error', 'detail' => $c['detail']], $checks),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $pageCss = '';
        ob_start();
        ?>
  <h1>Santé du système</h1>

  <div class="status-banner <?= $allHealthy ? 'healthy' : 'unhealthy' ?>">
    <h2><?= $allHealthy ? '<span aria-hidden="true">✓</span> Système opérationnel' : '<span aria-hidden="true">⚠</span> Problème détecté' ?></h2>
    <p class="u-c-muted-mt-05">v<?= \App\Core\App::html()->escape(App::cache()->getLatestVersion()) ?> — <?= \App\Core\App::html()->escape(date('d/m/Y à H:i')) ?></p>
  </div>

  <div class="card u-p-0-ov-hidden">
    <?php foreach ($checks as $check): ?>
    <div class="check-item">
      <div class="check-icon" aria-label="<?= $check['ok'] ? 'Succès' : 'Échec' ?>"><?= $check['ok'] ? '✅' : '❌' ?></div>
      <div class="check-content">
        <div class="check-label"><?= \App\Core\App::html()->escape($check['label']) ?></div>
        <div class="check-detail"><?= \App\Core\App::html()->escape($check['detail']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="u-c-muted-fs-xs-mt-15-ta-center">
    Endpoint de monitoring : <code>health.php?format=json</code>
  </p>
<?php
        $content = (string) ob_get_clean();
        echo $this->renderPage('Santé système', 'health', $pageCss, $content);
    }

    /**
     * Évalue tous les contrôles de santé.
     *
     * Extrait de handle() pour être testable sans capturer la sortie HTTP.
     * Les `detail` sont volontairement génériques (pas de fuite interne) :
     * tout diagnostic verbeux est routé vers error_log().
     *
     * @return array{healthy: bool, http_status: int, checks: list<array{label: string, ok: bool, detail: string}>}
     */
    public function evaluate(): array
    {
        $checks = [];
        $allHealthy = true;

        // 1. Base de données SQLite accessible
        $dbOk = false;
        $dbDetail = '';
        try {
            $dbOk = $this->settingsRepo->testConnection();
            $dbDetail = 'Connexion SQLite OK';
        } catch (\Exception $e) {
            // @silent-ok: log-only for health check read
            error_log('[AUDIT] health.db.connection: ' . $e->getMessage());
            $dbDetail = 'Connexion à la base impossible';
        }
        if (!$dbOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Base de données SQLite', 'ok' => $dbOk, 'detail' => $dbDetail];

        // 2. Version PHP
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.5.0', '>=');
        $phpDetail = 'PHP ' . $phpVersion . ($phpOk ? '' : ' (minimum requis : 8.5)');
        if (!$phpOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Version PHP', 'ok' => $phpOk, 'detail' => $phpDetail];

        // 3. Répertoire db/ accessible en écriture
        $dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
        $dbDir = dirname((string) $dbPath);
        $dirWritable = is_writable($dbDir);
        $dirDetail = $dirWritable ? 'Répertoire ' . basename($dbDir) . '/ accessible en écriture' : 'Répertoire ' . basename($dbDir) . '/ non accessible en écriture';
        if (!$dirWritable) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Répertoire de données', 'ok' => $dirWritable, 'detail' => $dirDetail];

        // 4. Schéma de base de données initialisé
        $schemaOk = false;
        $schemaDetail = '';
        try {
            $tables = $this->settingsRepo->getTableNames();
            $required = ['forms', 'submissions', 'tokens', 'settings', 'audit_log'];
            $missing = array_diff($required, $tables);
            if ($missing === []) {
                $schemaOk = true;
                $schemaDetail = count($tables) . ' tables présentes';
            } else {
                $schemaDetail = 'Tables requises manquantes';
            }
        } catch (\Exception $e) {
            // @silent-ok: log-only for health check read
            error_log('[AUDIT] health.db.schema: ' . $e->getMessage());
            $schemaDetail = 'Lecture du schéma impossible';
        }
        if (!$schemaOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Schéma de base de données', 'ok' => $schemaOk, 'detail' => $schemaDetail];

        // 5. Configuration SMTP présente
        $smtpOk = false;
        $smtpDetail = '';
        try {
            $smtpHost = $this->settings->get('smtp_host', '');
            $smtpOk = $smtpHost !== '' && $smtpHost !== '0';
            // L'hôte SMTP n'est pas exposé (endpoint public).
            $smtpDetail = $smtpOk ? 'Hôte SMTP configuré' : 'Aucun hôte SMTP configuré';
        } catch (\Exception $e) {
            // @silent-ok: log-only for health check read
            error_log('[AUDIT] health.smtp.read: ' . $e->getMessage());
            $smtpDetail = 'Erreur de lecture';
        }
        if (!$smtpOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Configuration SMTP', 'ok' => $smtpOk, 'detail' => $smtpDetail];

        // 6. Extensions PHP requises
        $requiredExt = ['mbstring', 'pdo_sqlite', 'json', 'session', 'pcre'];
        $missingExt = array_filter($requiredExt, fn(string $ext): bool => !extension_loaded($ext));
        $extOk = $missingExt === [];
        $extDetail = $extOk
            ? 'Toutes les extensions requises sont présentes (' . count($requiredExt) . ')'
            : 'Extensions manquantes : ' . implode(', ', $missingExt);
        if (!$extOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'Extensions PHP', 'ok' => $extOk, 'detail' => $extDetail];

        // 7. Outbox SMTP — échec définitif d'envoi (A4)
        // Détail = nombre seulement : ni destinataire, ni sujet, ni erreur SMTP
        // (l'endpoint est public, la confidentialité prime).
        $outboxOk = true;
        $outboxDetail = 'Aucun échec d\'envoi définitif';
        $failed = App::mail()->getOutboxFailureCount();
        if ($failed > 0) {
            $outboxOk = false;
            $outboxDetail = $failed . ' email(s) en échec définitif — intervention requise';
            error_log('[AUDIT] health.outbox: ' . $failed . ' mail(s) failed');
        }
        if (!$outboxOk) {
            $allHealthy = false;
        }
        $checks[] = ['label' => 'File d\'envoi des emails', 'ok' => $outboxOk, 'detail' => $outboxDetail];

        return [
            'healthy' => $allHealthy,
            'http_status' => $allHealthy ? 200 : 503,
            'checks' => $checks,
        ];
    }
}