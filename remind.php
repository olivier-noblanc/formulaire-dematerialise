<?php
// remind.php — Windows Task Scheduler toutes les 12h
require_once __DIR__ . '/helpers.php';

$pdo  = get_pdo();
$now  = new DateTimeImmutable();
$nb   = 0;

$tokens = $pdo->query("
    SELECT t.*, st.label as step_label, f.label as form_label, s.data
    FROM tokens t
    JOIN steps st ON st.id = t.step_id
    JOIN submissions s ON s.id = t.submission_id
    JOIN forms f ON f.id = s.form_id
    WHERE t.done_at IS NULL AND s.closed_at IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tokens as $t) {
    $sent     = new DateTimeImmutable($t['sent_at']);
    $last_ref = $t['relance_at'] ? new DateTimeImmutable($t['relance_at']) : $sent;
    $depuis   = ($now->getTimestamp() - $last_ref->getTimestamp()) / 3600;

    if ($depuis < DELAI_RELANCE_H) continue;

    $subject = '[RELANCE] ' . $t['form_label'] . ' — ' . $t['step_label'];
    if (send_mail($t['email'], $subject, build_mail_html($t, $t['step_label'], $t['token']))) {
        $pdo->prepare("UPDATE tokens SET relance_at=? WHERE id=?")
            ->execute([$now->format('Y-m-d H:i:s'), $t['id']]);
        echo "[{$now->format('Y-m-d H:i:s')}] Relance → {$t['email']} ({$t['step_label']})\n";
        $nb++;
    }
}

echo "$nb relance(s) envoyée(s).\n";
