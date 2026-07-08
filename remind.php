<?php
// remind.php — Windows Task Scheduler toutes les 12h
defined('CLI_MAIL_ALLOWED') || define('CLI_MAIL_ALLOWED', true);
require_once __DIR__ . '/helpers.php';

// Sécurité (S-09) : vérifier que ce script est exécuté en mode CLI
// Exception : autorisé si appelé via lazy_cron (flag global positionné par run_lazy_cron)
// Bug v5.25.2 : sans cette exception, le lazy_cron web affichait "Ce script ne peut..."
// directement dans la page utilisateur (exit() contourne ob_start/ob_end_clean).
/** @phpstan-ignore-next-line */
if (php_sapi_name() !== 'cli' && !TEST_MODE && empty($GLOBALS['_lazy_cron_running'])) {
    http_response_code(403);
    exit('Ce script ne peut être exécuté qu\'en ligne de commande.');
}

$pdo  = get_pdo();
$now  = new DateTimeImmutable();
$nb   = 0;
$blocked = 0;

$relance_max = (int)\App\Core\App::settings()->get('relance_max', '3');

$tokens = _dbm_q($pdo, "
    SELECT t.*, st.label as step_label, f.label as form_label, s.data
    FROM tokens t
    JOIN steps st ON st.id = t.step_id
    JOIN submissions s ON s.id = t.submission_id
    JOIN forms f ON f.id = s.form_id
    WHERE t.done_at IS NULL AND s.closed_at IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tokens as $t) {
    // Vérifier le plafond de relances
    $relance_count = (int)($t['relance_count'] ?? 0);
    if ($relance_count >= $relance_max) {
        error_log("Max relances atteint pour token {$t['token']} ({$relance_count}/{$relance_max})");
        $blocked++;
        continue;
    }

    $sent     = new DateTimeImmutable($t['sent_at']);
    $last_ref = $t['relance_at'] ? new DateTimeImmutable($t['relance_at']) : $sent;
    $depuis   = ($now->getTimestamp() - $last_ref->getTimestamp()) / 3600;

    if ($depuis < (int)\App\Core\App::settings()->get('delai_relance_h')) continue;

    $subject = '[RELANCE] ' . $t['form_label'] . ' — ' . $t['step_label'];
    if (send_mail($t['email'], $subject, build_mail_html($t, $t['step_label'], $t['token']))) {
        $new_count = $relance_count + 1;
        $pdo->prepare("UPDATE tokens SET relance_at=?, relance_count=? WHERE id=?")
            ->execute([$now->format('Y-m-d H:i:s'), $new_count, $t['id']]);
        echo "[{$now->format('Y-m-d H:i:s')}] Relance {$new_count}/{$relance_max} → {$t['email']} ({$t['step_label']})\n";
        $nb++;
    }
}

echo "$nb relance(s) envoyée(s).";
if ($blocked > 0) {
    echo " $blocked token(s) bloqué(s) : plafond de relances atteint (max={$relance_max}).";
}
echo "\n";

// Tracer la derniere execution pour le monitoring
\App\Core\App::settings()->set('last_remind_run', date('Y-m-d H:i:s'), 'remind.php');
app_log('remind_run', 'remind', "{$nb} relance(s) envoyée(s), {$blocked} bloquée(s)", 'remind.php');
