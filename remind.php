<?php
// remind.php — Windows Task Scheduler toutes les 12h
require_once __DIR__ . '/helpers.php';

$pdo  = get_pdo();
$now  = new DateTimeImmutable();
$nb   = 0;
$blocked = 0;

$relance_max = (int)get_setting('relance_max', '3');

$tokens = $pdo->query("
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

    if ($depuis < (int)get_setting('delai_relance_h', (string)DELAI_RELANCE_H)) continue;

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
