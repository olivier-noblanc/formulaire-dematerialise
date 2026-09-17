<?php
declare(strict_types=1);

/**
 * Sonde cross cron+outbox (BUG1) — exécutée en sous-processus HORS TEST_MODE
 * (voir tests/PHPUnit/Regression/RemindOutboxClaimPathTest.php). En TEST_MODE,
 * MailService::sendDetailed() court-circuite la logique réelle : ni le
 * write-ahead ni la tentative SMTP ne sont exerçables depuis un TestCase.
 *
 * Déroulé :
 *   1. exécute le vrai remind.php sur une base scratch, SMTP injoignable
 *      (mail_dry_run=0) → la relance est revendiquée puis l'envoi échoue en
 *      échec RÉESSAYABLE (ligne mail_log `error` écrite en write-ahead) ;
 *   2. simule l'écoulement du backoff (next_retry_at ramené dans le passé,
 *      comme un tick de cron ultérieur) puis exécute MailService::replayOutbox()
 *      sur la même base (le worker rejeu) ;
 *   3. écrit en JSON l'état du token (relance_count) et de la ligne mail_log
 *      AVANT et APRÈS rejeu.
 *
 * Invariant BUG1 : le claim de relance est CONSERVÉ sur un échec réessayable —
 * sinon le rejeu d'outbox renverrait l'email avec relance_count rabattu, ce qui
 * contourne relance_max (relance fantôme).
 *
 * Usage : php _remind_outbox_probe.php <scratchDb> <resultJson>
 */

$scratchDb  = $argv[1] ?? (sys_get_temp_dir() . '/remind_outbox_probe_' . getmypid() . '.db');
$resultFile = $argv[2] ?? ($scratchDb . '.result.json');

foreach ([$scratchDb, $scratchDb . '-wal', $scratchDb . '-shm'] as $f) {
    @unlink($f);
}
@unlink($resultFile);

define('DEFAULT_DB_PATH', $scratchDb);
define('DB_PATH', $scratchDb);
define('BASE_URL', 'http://localhost');
define('SETTINGS_DEFAULTS', [
    'smtp_host' => '', 'smtp_port' => '25',
    'smtp_from' => 'noreply@example.com', 'smtp_from_name' => 'CircuitDemat',
    'admin_email' => 'admin@localhost', 'email_domain' => 'localhost',
    'app_name' => 'CircuitDémat',
]);

require __DIR__ . '/../../helpers.php';

try {
    $pdo = get_pdo();

    // Neutraliser le cron différé exécuté au shutdown : sans cela, le worker
    // mail_outbox pourrait rejouer/altérer la ligne après l'écriture du JSON.
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO lazy_cron (task_key, last_run, run_count) VALUES (?, ?, 1)');
    foreach (['remind', 'alert_check', 'rgpd_purge', 'mail_outbox'] as $taskKey) {
        $stmt->execute([$taskKey, gmdate('Y-m-d H:i:s')]);
    }
    $stmt = null;

    // Envoi réel + SMTP injoignable (connexion refusée immédiatement).
    \App\Core\App::settings()->set('mail_dry_run', '0', 'probe');
    \App\Core\App::settings()->set('smtp_host', '127.0.0.1', 'probe');
    \App\Core\App::settings()->set('smtp_port', '1', 'probe');
    \App\Core\App::settings()->set('smtp_from', 'noreply@example.com', 'probe');
    \App\Core\App::settings()->set('smtp_from_name', 'CircuitDemat', 'probe');

    // ── Fixtures : form/step/submission/token, relance due (sent_at -49h) ──
    $formId  = bin2hex(random_bytes(8));
    $stepId  = bin2hex(random_bytes(8));
    $subId   = bin2hex(random_bytes(8));
    $fixtureTokenId = bin2hex(random_bytes(8));
    $fixtureEmail   = 'remind-outbox-probe@test.local';

    $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, relance_delai_h, relance_max) VALUES (?, ?, 'Probe Test', '', 1, datetime('now'), 48, 3)")
        ->execute([$formId, 'remind-outbox-probe-' . $formId]);
    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
        ->execute([$stepId, $formId]);
    $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'), NULL)")
        ->execute([$subId, $formId, $fixtureEmail]);
    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, relance_count, invalidated_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 0, NULL, ?)")
        ->execute([
            $fixtureTokenId,
            $subId,
            $stepId,
            $fixtureEmail,
            bin2hex(random_bytes(16)),
            gmdate('Y-m-d H:i:s', strtotime('-49 hours')),
            gmdate('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

    $readTokenState = static function () use ($pdo, $fixtureTokenId): array {
        $stmt = $pdo->prepare('SELECT relance_count, relance_at FROM tokens WHERE id = ?');
        $stmt->execute([$fixtureTokenId]);
        /** @var array{relance_count: int|string|null, relance_at: string|null} $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt = null;
        return $row;
    };
    $readMailRow = static function () use ($pdo, $fixtureEmail): ?array {
        $stmt = $pdo->prepare(
            "SELECT status, body_html, attempts, next_retry_at FROM mail_log
             WHERE recipient = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$fixtureEmail]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt = null;
        if ($row === false) {
            return null;
        }
        /** @var array{status: string, body_html: string|null, attempts: int|string, next_retry_at: string|null} $row */
        return $row;
    };

    // ── 1. Exécution du vrai remind.php (relance due + échec SMTP réessayable) ──
    require __DIR__ . '/../../remind.php';

    $tokenAfterRemind = $readTokenState();
    $rowAfterRemind   = $readMailRow();

    // ── 2. Rejeu outbox (worker) sur la même base ──
    // Simule le tick de cron ultérieur : le backoff de 15 min posé par l'échec
    // initial est écoulé, la ligne redevient revendicable par le worker.
    $stmt = $pdo->prepare("UPDATE mail_log SET next_retry_at = ? WHERE recipient = ? AND status = ?");
    $stmt->execute([
        gmdate('Y-m-d H:i:s', time() - 60),
        $fixtureEmail,
        \App\Enum\MailStatus::Error->value,
    ]);
    $stmt = null;

    $replayStats = \App\Core\App::mail()->replayOutbox();

    $tokenAfterReplay = $readTokenState();
    $rowAfterReplay   = $readMailRow();

    file_put_contents($resultFile, (string) json_encode([
        'relance_count_after_remind' => (int) ($tokenAfterRemind['relance_count'] ?? -1),
        'relance_at_after_remind'    => $tokenAfterRemind['relance_at'] ?? null,
        'row_after_remind'           => $rowAfterRemind,
        'replay_stats'               => $replayStats,
        'relance_count_after_replay' => (int) ($tokenAfterReplay['relance_count'] ?? -1),
        'row_after_replay'           => $rowAfterReplay,
    ], JSON_PRETTY_PRINT));
} catch (\Throwable $e) {
    file_put_contents($resultFile, (string) json_encode([
        'probe_error' => $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
    ], JSON_PRETTY_PRINT));
    exit(3);
}