<?php
// alert_check.php — Script CLI : verification des alertes parametrables
// A executer via Task Scheduler (ex: toutes les 6h)
// Verifie si des soumissions en cours sont proches de leur date limite
// et envoie des alertes si les etapes ne sont pas toutes completees
defined('CLI_MAIL_ALLOWED') || define('CLI_MAIL_ALLOWED', true);
require_once __DIR__ . '/helpers.php';

$pdo = get_pdo();
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
$nb_alerts = 0;
$nb_skipped = 0;

// Recuperer toutes les regles d'alerte actives
$rules = $pdo->query("
    SELECT ar.*, f.slug as form_slug, f.label as form_label, f.deadline_field
    FROM alert_rules ar
    JOIN forms f ON f.id = ar.form_id
    WHERE ar.actif = 1
    ORDER BY ar.days_before DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($rules)) {
    echo "[{$now->format('Y-m-d H:i:s')}] Aucune regle d'alerte active.\n";
    exit(0);
}

foreach ($rules as $rule) {
    $deadline_field = $rule['deadline_field'] ?? '';
    if (empty($deadline_field)) {
        echo "[{$now->format('Y-m-d H:i:s')}] Formulaire '{$rule['form_label']}' : aucun champ deadline defini (deadline_field vide). Ignore.\n";
        continue;
    }

    // Recuperer les soumissions en cours pour ce formulaire
    $subs = $pdo->prepare("
        SELECT s.*, f.label as form_label
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE s.form_id = ? AND s.status = 'en_cours'
    ");
    $subs->execute([$rule['form_id']]);
    $submissions = $subs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($submissions as $sub) {
        $data = json_decode($sub['data'], true) ?: [];
        $deadline_str = $data[$deadline_field] ?? '';

        if (empty($deadline_str)) {
            // Pas de date limite dans les donnees du formulaire
            continue;
        }

        // Parser la date limite (format YYYY-MM-DD ou DD/MM/YYYY)
        $deadline = parse_date($deadline_str);
        if (!$deadline) {
            echo "[{$now->format('Y-m-d H:i:s')}] Date invalide '{$deadline_str}' pour soumission #{$sub['id']}. Ignore.\n";
            continue;
        }

        // Calculer la date d'alerte = deadline - days_before
        $alert_date = $deadline->modify("-{$rule['days_before']} days");

        // Verifier si on est dans la fenetre d'alerte (alert_date <= now <= deadline)
        if ($now < $alert_date) {
            // Trop tot, pas encore dans la fenetre d'alerte
            continue;
        }

        // Verifier la condition : etapes incompletes ?
        if ($rule['condition_type'] === 'steps_incomplete') {
            $incomplete = has_incomplete_steps($pdo, $sub['id']);
            if (!$incomplete) {
                // Toutes les etapes sont completees, pas besoin d'alerte
                $nb_skipped++;
                continue;
            }
        }

        // Verifier si une alerte a deja ete envoyee pour cette regle + soumission aujourd'hui
        $already = $pdo->prepare("
            SELECT COUNT(*) FROM alert_log
            WHERE rule_id = ? AND submission_id = ?
              AND DATE(sent_at) = DATE(?)
        ");
        $already->execute([$rule['id'], $sub['id'], $now->format('Y-m-d H:i:s')]);
        if ($already->fetchColumn() > 0) {
            // Alerte deja envoyee aujourd'hui pour cette regle + soumission
            $nb_skipped++;
            continue;
        }

        // Calculer les infos pour l'email
        $days_remaining = (int)$now->diff($deadline)->format('%r%a');
        $nom_agent = ($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '');
        $deadline_formatted = $deadline->format('d/m/Y');

        // Determiner les destinataires
        $recipients = resolve_recipients($pdo, $rule['notify_who'], $sub);

        // Construire et envoyer l'email d'alerte
        foreach ($recipients as $recipient) {
            $subject = '[ALERTE] ' . $rule['form_label'] . ' — J-' . abs($days_remaining) . ' avant la date cible';
            $body = build_alert_html($sub, $nom_agent, $deadline_formatted, $days_remaining, $rule, $data, $pdo);
            $sent = send_mail($recipient, $subject, $body);

            if ($sent) {
                // Logger l'alerte
                $message = "Alerte J-{$rule['days_before']} envoyee a {$recipient} pour {$nom_agent}";
                $pdo->prepare("INSERT INTO alert_log (id, rule_id, submission_id, sent_at, message) VALUES (generate_uuid(), ?, ?, datetime('now'), ?)")
                    ->execute([$rule['id'], $sub['id'], $message]);
                $nb_alerts++;
                echo "[{$now->format('Y-m-d H:i:s')}] Alerte J-{$rule['days_before']} -> {$recipient} | {$nom_agent} | Deadline: {$deadline_formatted}\n";
            } else {
                echo "[{$now->format('Y-m-d H:i:s')}] ERREUR envoi alerte a {$recipient} pour soumission #{$sub['id']}\n";
            }
        }
    }
}

echo "$nb_alerts alerte(s) envoyee(s).";
if ($nb_skipped > 0) {
    echo " $nb_skipped ignoree(s) (deja alertees ou etapes completes).";
}
echo "\n";

// Tracer l'execution
set_setting('last_alert_check', date('Y-m-d H:i:s'), 'alert_check.php');
app_log('alert_check', 'alert', "{$nb_alerts} alerte(s) envoyee(s), {$nb_skipped} ignoree(s)", 'alert_check.php');

// ── Fonctions utilitaires ──────────────────────────────────────

/**
 * Parse une date en format YYYY-MM-DD ou DD/MM/YYYY
 */
function parse_date(string $date_str): ?DateTimeImmutable {
    $date_str = trim($date_str);
    // Format YYYY-MM-DD (HTML date input)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
        try {
            return new DateTimeImmutable($date_str . ' 00:00:00', new DateTimeZone('Europe/Paris'));
        } catch (Exception $e) {
            return null;
        }
    }
    // Format DD/MM/YYYY
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_str, $m)) {
        try {
            return new DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} 00:00:00", new DateTimeZone('Europe/Paris'));
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

/**
 * Verifie si une soumission a des etapes incompletes
 */
function has_incomplete_steps(PDO $pdo, string $submission_id): bool {
    // Compter les tokens non traites
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tokens
        WHERE submission_id = ? AND done_at IS NULL
    ");
    $stmt->execute([$submission_id]);
    $pending = (int)$stmt->fetchColumn();

    // Verifier s'il reste des etapes sans tokens (pas encore demarrees)
    $sub_stmt = $pdo->prepare("
        SELECT s.form_id FROM submissions s WHERE s.id = ?
    ");
    $sub_stmt->execute([$submission_id]);
    $form_id = $sub_stmt->fetchColumn();

    $total_steps = $pdo->prepare("SELECT COUNT(DISTINCT ordre) FROM steps WHERE form_id = ? AND actif = 1");
    $total_steps->execute([$form_id]);
    $nb_ordres = (int)$total_steps->fetchColumn();

    $started_ordres = $pdo->prepare("
        SELECT COUNT(DISTINCT st.ordre) FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
    ");
    $started_ordres->execute([$submission_id]);
    $nb_started = (int)$started_ordres->fetchColumn();

    return $pending > 0 || $nb_started < $nb_ordres;
}

/**
 * Determine les destinataires d'une alerte
 */
function resolve_recipients(PDO $pdo, string $notify_who, array $submission): array {
    $recipients = [];

    switch ($notify_who) {
        case 'admin':
            // Tous les admins
            $admins = $pdo->query("SELECT email FROM admins")->fetchAll(PDO::FETCH_COLUMN);
            $recipients = array_merge($recipients, $admins);
            break;

        case 'submitter':
            // L'agent qui a soumis le formulaire
            if (!empty($submission['submitted_by'])) {
                $recipients[] = $submission['submitted_by'];
            }
            break;

        case 'validators':
            // Les validateurs ayant des tokens en cours
            $stmt = $pdo->prepare("SELECT DISTINCT email FROM tokens WHERE submission_id = ? AND done_at IS NULL");
            $stmt->execute([$submission['id']]);
            $validators = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $recipients = array_merge($recipients, $validators);
            break;

        case 'admin+submitter':
            // Admin + agent
            $admins = $pdo->query("SELECT email FROM admins")->fetchAll(PDO::FETCH_COLUMN);
            $recipients = array_merge($recipients, $admins);
            if (!empty($submission['submitted_by'])) {
                $recipients[] = $submission['submitted_by'];
            }
            break;

        case 'admin+validators':
            // Admin + validateurs en cours
            $admins = $pdo->query("SELECT email FROM admins")->fetchAll(PDO::FETCH_COLUMN);
            $recipients = array_merge($recipients, $admins);
            $stmt = $pdo->prepare("SELECT DISTINCT email FROM tokens WHERE submission_id = ? AND done_at IS NULL");
            $stmt->execute([$submission['id']]);
            $validators = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $recipients = array_merge($recipients, $validators);
            break;

        default:
            // Adresse email specifique
            if (filter_var($notify_who, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $notify_who;
            }
            break;
    }

    // Dedoublonner et filtrer les emails invalides
    $recipients = array_unique(array_filter($recipients, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    return $recipients;
}

/**
 * Construit le HTML de l'email d'alerte
 */
function build_alert_html(array $sub, string $nom_agent, string $deadline_formatted, int $days_remaining, array $rule, array $data, PDO $pdo): string {
    // Recuperer les etapes et leur statut
    $tokens = $pdo->prepare("
        SELECT t.email, t.done_at, st.label as step_label, st.ordre
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY st.ordre, st.label
    ");
    $tokens->execute([$sub['id']]);
    $all_tokens = $tokens->fetchAll(PDO::FETCH_ASSOC);

    $steps_html = '';
    $pending_count = 0;
    $done_count = 0;
    foreach ($all_tokens as $t) {
        $status_icon = $t['done_at'] ? '&#10003;' : '&#10007;';
        $status_color = $t['done_at'] ? '#1a6b3c' : '#c0392b';
        $status_text = $t['done_at'] ? 'Valid&eacute;' : 'En attente';
        $steps_html .= "<tr>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$t['step_label']}</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$t['email']}</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;color:{$status_color};font-weight:bold;'>{$status_icon} {$status_text}</td>
        </tr>";
        if ($t['done_at']) {
            $done_count++;
        } else {
            $pending_count++;
        }
    }

    $alert_color = $days_remaining <= 2 ? '#c0392b' : ($days_remaining <= 5 ? '#b45309' : '#003189');
    $urgency = $days_remaining <= 0 ? "DATE D&Eacute;PAS&Eacute;E" : "J-{$days_remaining}";
    $dashboard_url = BASE_URL . '/dashboard.php';

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:650px;margin:auto;padding:20px;color:#222;">
  <div style="background:' . $alert_color . ';color:#fff;padding:15px 20px;border-radius:4px 4px 0 0;text-align:center;">
    <h1 style="margin:0;font-size:1.3rem;">&#9888; Alerte Workflow — ' . $urgency . '</h1>
  </div>
  <div style="border:1px solid #ddd;border-top:none;padding:20px;border-radius:0 0 4px 4px;">
    <h2 style="color:' . $alert_color . ';font-size:1.1rem;margin-top:0;">' . h($rule['form_label']) . '</h2>
    <table style="width:100%;margin-bottom:16px;border-collapse:collapse;">
      <tr><td style="padding:5px 0;font-weight:bold;color:#555;width:40%;">Agent :</td><td style="padding:5px 0;">' . h($nom_agent) . '</td></tr>
      <tr><td style="padding:5px 0;font-weight:bold;color:#555;">Date cible :</td><td style="padding:5px 0;"><strong>' . $deadline_formatted . '</strong></td></tr>
      <tr><td style="padding:5px 0;font-weight:bold;color:#555;">Jours restants :</td><td style="padding:5px 0;color:' . $alert_color . ';font-weight:bold;">' . $urgency . '</td></tr>
      <tr><td style="padding:5px 0;font-weight:bold;color:#555;">Avancement :</td><td style="padding:5px 0;">' . $done_count . ' valid&eacute;(s) / ' . ($done_count + $pending_count) . ' total</td></tr>
    </table>

    <h3 style="color:#003189;font-size:.95rem;margin-bottom:8px;">D&eacute;tail des &eacute;tapes</h3>
    <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:16px;">
      <thead><tr style="background:#003189;color:#fff;">
        <th style="padding:8px 10px;text-align:left;">&Eacute;tape</th>
        <th style="padding:8px 10px;text-align:left;">Validateur</th>
        <th style="padding:8px 10px;text-align:left;">Statut</th>
      </tr></thead>
      <tbody>' . $steps_html . '</tbody>
    </table>

    <a href="' . $dashboard_url . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">
      Voir le tableau de bord
    </a>
  </div>
  <p style="font-size:12px;color:#999;margin-top:16px;">' . h(get_app_name()) . ' — Alerte automatique (regle : ' . h($rule['label']) . ')</p>
</body></html>';
}
