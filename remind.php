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
// UTC explicite : sent_at/relance_at viennent de SQLite datetime('now') (toujours UTC).
// Sans fuseau explicite, DateTimeImmutable($string) interprète la chaîne selon le
// fuseau par défaut du serveur (Europe/Paris en prod) — même bug que #12 (alert_check.php).
$now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nb   = 0;
$blocked = 0;
$relance_max = 3; // défaut si aucun token traité

// Récupérer les IDs des tokens à traiter (pas de fetchAll complet pour éviter les stale reads).
// P0-3/P0-4 : exclusions déportées dans TokenRepository::findRemindableTokenIds() —
// tokens invalidés (délégation/régénération/RGPD) et tokens expirés (lien mort)
// ne doivent pas recevoir de relance.
$nowUtc = $now->format('Y-m-d H:i:s');
$pendingIds = App\Core\App::getInstance()
    ->get(App\Repository\TokenRepository::class)
    ->findRemindableTokenIds($nowUtc);

foreach ($pendingIds as $tokenId) {
    // Transaction par token : SELECT + vérification atomique avant envoi
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT t.*, st.label as step_label, f.label as form_label, s.data,
                   f.relance_delai_h, f.relance_max
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.id = ? AND t.done_at IS NULL AND t.invalidated_at IS NULL
              AND (t.expires_at IS NULL OR t.expires_at > ?) AND s.closed_at IS NULL
        ");
        $stmt->execute([$tokenId, $nowUtc]);
        /** @var array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, invalidated_at: string|null, action: string|null, step_label: string, form_label: string, data: string, relance_delai_h: int|string|null, relance_max: int|string|null}|false $tok */
        $tok = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tok === false) {
            $pdo->rollBack();
            continue;
        }

        // Config de relance par formulaire (colonnes forms.relance_* pouvant être NULL → fallback 48/3)
        $relance_max = (int) ($tok['relance_max'] ?? 3);
        $relance_delai_h = (int) ($tok['relance_delai_h'] ?? 48);

        // Vérifier le plafond de relances
        $relance_count = (int)($tok['relance_count'] ?? 0);
        if ($relance_count >= $relance_max) {
            $pdo->rollBack();
            error_log("Max relances atteint pour token {$tok['token']} ({$relance_count}/{$relance_max})");
            $blocked++;
            continue;
        }

        $sent     = new DateTimeImmutable($tok['sent_at'], new DateTimeZone('UTC'));
        $last_ref = $tok['relance_at'] !== null ? new DateTimeImmutable($tok['relance_at'], new DateTimeZone('UTC')) : $sent;
        $depuis   = ($now->getTimestamp() - $last_ref->getTimestamp()) / 3600;

        if ($depuis < $relance_delai_h) {
            $pdo->rollBack();
            continue;
        }

        $subject = '[RELANCE] ' . $tok['form_label'] . ' — ' . $tok['step_label'];
        if (send_mail($tok['email'], $subject, build_mail_html($tok, $tok['step_label'], $tok['token']))) {
            $new_count = $relance_count + 1;
            $upd = $pdo->prepare("UPDATE tokens SET relance_at=?, relance_count=? WHERE id=? AND done_at IS NULL AND invalidated_at IS NULL");
            $upd->execute([$now->format('Y-m-d H:i:s'), $new_count, $tokenId]);
            if ($upd->rowCount() === 0) {
                // Token validé pendant l'envoi du mail — ne pas compter comme envoyé
                $pdo->rollBack();
                continue;
            }
            $pdo->commit();
            echo "[{$now->format('Y-m-d H:i:s')}] Relance {$new_count}/{$relance_max} → {$tok['email']} ({$tok['step_label']})\n";
            $nb++;
        } else {
            $pdo->rollBack();
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur relance token {$tokenId}: " . $e->getMessage());
    }
}

echo "$nb relance(s) envoyée(s).";
if ($blocked > 0) {
    echo " $blocked token(s) bloqué(s) : plafond de relances atteint (max={$relance_max}).";
}
echo "\n";

// Tracer la derniere execution pour le monitoring
\App\Core\App::settings()->set('last_remind_run', date('Y-m-d H:i:s'), 'remind.php');
\App\Core\App::audit()->log('remind_run', 'remind', "{$nb} relance(s) envoyée(s), {$blocked} bloquée(s)", 'remind.php');
