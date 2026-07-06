<?php
declare(strict_types=1);

/**
 * Rendu de la page de surveillance / monitoring (monitoring.php).
 *
 * Contient toutes les fonctions de rendu HTML de la page Surveillance :
 *  - monitoring_page_css() / monitoring_nav_extra()
 *  - render_monitoring_stats() / render_monitoring_repartition()
 *  - render_monitoring_smtp_card() / render_monitoring_scripts_card()
 *  - render_monitoring_active_alerts() / render_monitoring_recent_alerts()
 *  - render_monitoring_by_form() / render_monitoring_daily_activity()
 *  - render_monitoring_blocked_tokens() / render_monitoring_content()
 *
 * render_monitoring_audit_log() est dans lib/render_monitoring_audit.php
 * (extrait pour rester sous 600 lignes). Le fichier monitoring.php garde
 * toute la logique de data fetching / SQL ; ce module ne contient que du
 * rendu HTML pur (aucun accès DB).
 *
 * @package lib
 * @see /monitoring.php
 */

// ── CSS SPÉCIFIQUE PAGE SURVEILLANCE ──────────────────────────

/**
 * CSS propre à la page Surveillance (overrides container/grid-2, alertes
 * urgentes, responsive mobile). Chargé depuis lib/monitoring_page.css
 * pour rester sous 600 lignes. Identique à l'ancien heredoc <<<'CSS'.
 */
function monitoring_page_css(): string
{
    static $css = null;
    if ($css === null) {
        $css = (string)file_get_contents(__DIR__ . '/monitoring_page.css');
    }
    return $css;
}

// ── NAVIGATION LATÉRALE ───────────────────────────────────────

/**
 * Entrées de navigation latérale spécifiques à la page Surveillance.
 *
 * @return array<string, array{href: string, label: string, icon: string}>
 */
function monitoring_nav_extra(): array
{
    return [
        'monitoring'=> ['href' => 'index.php?p=monitoring',   'label' => 'Surveillance', 'icon' => '🖥'],
        'alerts'    => ['href' => 'index.php?p=admin_alerts', 'label' => 'Alertes', 'icon' => '🔔'],
        'stats'     => ['href' => 'index.php?p=stats',         'label' => 'Statistiques', 'icon' => '📈'],
        'backup'    => ['href' => 'index.php?p=backup',        'label' => 'Sauvegarde', 'icon' => '💾'],
        'health'    => ['href' => 'index.php?p=health',        'label' => 'Santé', 'icon' => '🏥'],
    ];
}

// ── STATS GLOBALES ────────────────────────────────────────────

/**
 * Bandeau de 6 cartes stats : soumissions totales, taux validation,
 * temps moyen traitement, en cours, tokens bloqués, alertes actives.
 *
 * @param array<string, mixed> $ctx Contexte (total_sub, taux_validation,
 *   avg_days, avg_hours, en_cours_sub, tokens_bloques, active_alerts)
 */
function render_monitoring_stats(array $ctx): string
{
    $total_sub       = (int)($ctx['total_sub'] ?? 0);
    $taux_validation = $ctx['taux_validation'] ?? 0;
    $avg_days        = (float)($ctx['avg_days'] ?? 0);
    $avg_hours       = (float)($ctx['avg_hours'] ?? 0);
    $en_cours_sub    = (int)($ctx['en_cours_sub'] ?? 0);
    $tokens_bloques  = $ctx['tokens_bloques'] ?? [];
    $active_alerts   = $ctx['active_alerts'] ?? [];

    $avg_label = $avg_days > 0 ? $avg_days . ' j' : $avg_hours . ' h';
    $alert_cls = !empty($active_alerts) ? 'danger' : 'success';

    $nb_tokens_bloques = count($tokens_bloques);
    $nb_active_alerts  = count($active_alerts);

    return <<<HTML
  <!-- Stats globales -->
  <div class="grid-3">
    <div class="stat-card">
      <div class="stat-value">{$total_sub}</div>
      <div class="stat-label">Soumissions totales</div>
    </div>
    <div class="stat-card success">
      <div class="stat-value">{$taux_validation}%</div>
      <div class="stat-label">Taux de validation</div>
    </div>
    <div class="stat-card">
      <div class="stat-value">{$avg_label}</div>
      <div class="stat-label">Temps moyen de traitement</div>
    </div>
    <div class="stat-card warning">
      <div class="stat-value">{$en_cours_sub}</div>
      <div class="stat-label">En cours</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-value">{$nb_tokens_bloques}</div>
      <div class="stat-label">Tokens bloqués</div>
    </div>
    <div class="stat-card {$alert_cls}">
      <div class="stat-value">{$nb_active_alerts}</div>
      <div class="stat-label">Alertes actives</div>
    </div>
  </div>
HTML;
}

// ── GRAPHIQUE RÉPARTITION ─────────────────────────────────────

/**
 * Carte avec graphique donut des statuts de soumission.
 *
 * @param int $total_sub    Total soumissions
 * @param int $valide_sub   Validées
 * @param int $en_cours_sub En cours
 * @param int $refuse_sub   Refusées
 */
function render_monitoring_repartition(int $total_sub, int $valide_sub, int $en_cours_sub, int $refuse_sub): string
{
    $donut = render_donut_chart($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
    return <<<HTML
  <!-- Graphique de répartition des statuts -->
  <div class="card">
    <h2><span aria-hidden="true">📊</span> Répartition des soumissions</h2>
    {$donut}
  </div>
HTML;
}

// ── CARTE SANTÉ SMTP ──────────────────────────────────────────

/**
 * Carte santé SMTP : statut (ok/erreur/non testé), config SMTP, bouton test.
 * Si un test SMTP vient d'être effectué, affiche aussi :
 *  - Le message d'erreur détaillé (en cas d'échec)
 *  - La conversation SMTP complète (debug PHPMailer niveau 3) dans un <details>
 *
 * @param string $smtp_status     Statut ('inconnu'|'ok'|'erreur')
 * @param string $smtp_detail     Détail du test SMTP (sera échappé via h())
 * @param string $smtp_debug_log  Conversation SMTP brute (debug PHPMailer)
 */
function render_monitoring_smtp_card(string $smtp_status, string $smtp_detail, string $smtp_debug_log = ''): string
{
    $smtp_host    = h(get_setting('smtp_host'));
    $smtp_port    = h(get_setting('smtp_port'));
    $smtp_secure  = h(get_setting('smtp_secure', '') ?: 'Aucun');
    $mail_dry_run = get_setting('mail_dry_run', '0') === '1';

    if ($smtp_status === 'ok') {
        $dot         = '<span class="health-dot health-ok"></span>';
        $badge       = '<span class="badge badge-ok">Fonctionnel</span>';
        $detail_html = h($smtp_detail);
    } elseif ($smtp_status === 'erreur') {
        $dot         = '<span class="health-dot health-err"></span>';
        $badge       = '<span class="badge badge-err">Erreur</span>';
        $detail_html = h($smtp_detail);
    } else {
        $dot         = '<span class="health-dot health-unknown"></span>';
        $badge       = '<span class="badge badge-info">Non testé</span>';
        $detail_html = 'Cliquez sur le bouton pour tester la connexion SMTP.';
    }

    // Bloc dry-run (avertissement si activé — les mails ne partiront jamais)
    $dryrun_html = $mail_dry_run
        ? '<div class="warning-box" style="margin-bottom:.75rem;font-size:.85rem;"><strong>⚠ Mode Dry-Run actif</strong> — Aucun email réel n\'est envoyé. Tous les envois sont journalisés mais ne quittent pas le serveur. Désactivez le Dry-Run dans <a href="index.php?p=admin_settings#section-email-verify">Paramètres → Sécurité email</a> pour activer l\'envoi réel.</div>'
        : '';

    // Bloc debug SMTP (uniquement si un test vient d'être fait ET qu'on a un log)
    $debug_html = '';
    if ($smtp_debug_log !== '') {
        $debug_html = '<details style="margin-top:1rem;border:1px solid #ddd;border-radius:4px;padding:.75rem;background:#fafafa;">'
            . '<summary style="cursor:pointer;font-size:.85rem;font-weight:bold;color:#003189;">📋 Conversation SMTP (debug)</summary>'
            . '<pre style="margin-top:.75rem;max-height:300px;overflow:auto;font-size:.75rem;background:#222;color:#eee;padding:.75rem;border-radius:3px;white-space:pre-wrap;word-break:break-all;">' . h($smtp_debug_log) . '</pre>'
            . '</details>';
    }

    return <<<HTML
    <!-- Santé SMTP -->
    <div class="card">
      <h2><span aria-hidden="true">📧</span> Santé SMTP</h2>
      {$dryrun_html}
      <p style="margin-bottom:1rem;">
        {$dot}
        {$badge}
        {$detail_html}
      </p>
      <p style="font-size:.85rem;color:#595959;margin-bottom:1rem;">
        Hôte : <strong>{$smtp_host}</strong> |
        Port : <strong>{$smtp_port}</strong> |
        Chiffrement : <strong>{$smtp_secure}</strong>
      </p>
      <a href="index.php?p=monitoring&test_smtp=1" class="btn btn-primary">Tester SMTP</a>
      {$debug_html}
    </div>
HTML;
}

// ── CARTE SCRIPTS AUTOMATISÉS ─────────────────────────────────

/**
 * Carte scripts automatisés : relance (remind.php) + alerte (alert_check.php).
 * Affiche l'âge de la dernière exécution + badges OK/warning.
 *
 * @param string $last_remind       Date dernière exécution remind (Y-m-d H:i:s)
 * @param string $last_alert_check  Date dernière exécution alert_check (Y-m-d H:i:s)
 */
function render_monitoring_scripts_card(string $last_remind, string $last_alert_check): string
{
    // ── Script de relance ──
    $remind_html = '';
    if ($last_remind) {
        $remind_ts  = strtotime($last_remind);
        $remind_age = ($remind_ts !== false) ? (time() - $remind_ts) : 999999;
        $remind_ok  = $remind_age < 86400;
        $remind_dot_cls = $remind_ok ? 'health-ok' : 'health-warn';
        $remind_date    = h(date('d/m/Y à H:i', $remind_ts !== false ? $remind_ts : 0));
        $remind_badge   = $remind_ok
            ? '<br><span class="badge badge-ok" style="margin-top:.25rem;"><span aria-hidden="true">✓</span> Actif</span>'
            : '<br><span class="badge badge-warn" style="margin-top:.25rem;"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>';
        $remind_html = <<<HTML
          <span class="health-dot {$remind_dot_cls}" style="margin-top:.5rem;"></span>
          Dernière exécution : <strong>{$remind_date}</strong>
          {$remind_badge}
HTML;
    } else {
        $remind_html = '<span class="health-dot health-unknown"></span><span class="badge badge-info">Jamais exécuté</span>';
    }

    // ── Script d'alerte ──
    $alert_html = '';
    if ($last_alert_check) {
        $alert_ts  = strtotime($last_alert_check);
        $alert_age = ($alert_ts !== false) ? (time() - $alert_ts) : 999999;
        $alert_ok  = $alert_age < 86400;
        $alert_dot_cls = $alert_ok ? 'health-ok' : 'health-warn';
        $alert_date    = h(date('d/m/Y à H:i', $alert_ts !== false ? $alert_ts : 0));
        $alert_badge   = $alert_ok
            ? '<br><span class="badge badge-ok" style="margin-top:.25rem;"><span aria-hidden="true">✓</span> Actif</span>'
            : '<br><span class="badge badge-warn" style="margin-top:.25rem;"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>';
        $alert_html = <<<HTML
          <span class="health-dot {$alert_dot_cls}" style="margin-top:.5rem;"></span>
          Dernière exécution : <strong>{$alert_date}</strong>
          {$alert_badge}
HTML;
    } else {
        $alert_html = '<span class="health-dot health-unknown"></span><span class="badge badge-info">Jamais exécuté</span>';
    }

    $delai_relance    = h(get_setting('delai_relance_h', '48'));
    $relance_max      = h(get_setting('relance_max', '3'));
    $token_expire_days = h(get_setting('token_expire_days', '30'));

    return <<<HTML
    <!-- Scripts automatises -->
    <div class="card">
      <h2><span aria-hidden="true">🤖</span> Scripts automatisés</h2>
      <!-- Script de relance -->
      <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #eee;">
        <strong style="font-size:.9rem;"><span aria-hidden="true">🔄</span> Script de relance (remind.php)</strong><br>
        {$remind_html}
      </div>
      <!-- Script d'alerte -->
      <div>
        <strong style="font-size:.9rem;"><span aria-hidden="true">🔔</span> Script d'alerte (alert_check.php)</strong><br>
        {$alert_html}
        <p style="font-size:.8rem;color:#595959;margin-top:.5rem;">
          Délai relance : <strong>{$delai_relance}h</strong> |
          Max relances : <strong>{$relance_max}</strong> |
          Expiration tokens : <strong>{$token_expire_days}j</strong>
        </p>
      </div>
    </div>
HTML;
}

// ── ALERTES ACTIVES ───────────────────────────────────────────

/**
 * Carte alertes actives : soumissions en cours proches de la deadline.
 *
 * @param array<int, array<string, mixed>> $active_alerts
 */
function render_monitoring_active_alerts(array $active_alerts): string
{
    if (empty($active_alerts)) {
        return '';
    }

    $rows = '';
    foreach ($active_alerts as $aa) {
        $days = (int)($aa['days_remaining'] ?? 0);
        $row_cls = $days < 0 ? 'urgent' : ($days <= 2 ? 'urgent' : ($days <= 5 ? 'warning' : 'ok'));
        $days_cls = $days < 0 ? 'overdue' : ($days <= 2 ? 'critical' : ($days <= 5 ? 'warning' : 'ok'));
        $days_text = $days < 0 ? 'J+' . abs($days) : ($days === 0 ? "Jour J" : 'J-' . $days);

        $form_label    = h((string)($aa['form_label'] ?? ''));
        $nom_agent     = h((string)($aa['nom_agent'] ?? ''));
        $deadline_fmt  = h((string)($aa['deadline_formatted'] ?? ''));
        $pending_steps = (int)($aa['pending_steps'] ?? 0);

        $rows .= <<<HTML
        <tr class="alert-row {$row_cls}">
          <td><span class="days-remaining {$days_cls}">{$days_text}</span></td>
          <td><strong>{$form_label}</strong></td>
          <td>{$nom_agent}</td>
          <td style="white-space:nowrap;">{$deadline_fmt}</td>
          <td><span class="days-remaining {$days_cls}">{$days_text}</span></td>
          <td><span class="badge badge-warn">{$pending_steps} en attente</span></td>
        </tr>
HTML;
    }

    return <<<HTML
  <!-- Alertes actives : soumissions proches de la deadline -->
  <div class="card">
    <h2><span aria-hidden="true">🔔</span> Alertes actives — Soumissions proches de la date cible</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Les soumissions suivantes sont en cours et approchent ou dépassent leur date cible avec des étapes non complétées.
    </p>
    <table>
      <thead>
        <tr><th>Urgence</th><th>Formulaire</th><th>Agent</th><th>Date cible</th><th>Jours restants</th><th>Étapes en attente</th></tr>
      </thead>
      <tbody>
      {$rows}
      </tbody>
    </table>
    <p style="margin-top:1rem;">
      <a href="index.php?p=admin_alerts" class="btn btn-secondary" style="font-size:.85rem;"><span aria-hidden="true">⚙</span> Configurer les règles d'alerte</a>
    </p>
  </div>
HTML;
}

// ── DERNIÈRES ALERTES ENVOYÉES ────────────────────────────────

/**
 * Carte dernières alertes envoyées (tableau des 20 dernières alertes loggées).
 *
 * @param array<int, array<string, mixed>> $recent_alerts
 */
function render_monitoring_recent_alerts(array $recent_alerts): string
{
    if (empty($recent_alerts)) {
        return '';
    }

    $rows = '';
    foreach ($recent_alerts as $ra) {
        $date      = h(date('d/m/Y H:i', strtotime((string)($ra['sent_at'] ?? 'now'))));
        $rule_lbl  = h((string)($ra['rule_label'] ?? 'Règle supprimée'));
        $form_lbl  = h((string)($ra['form_label'] ?? ''));
        $message   = h((string)($ra['message'] ?? ''));

        $rows .= <<<HTML
        <tr>
          <td style="white-space:nowrap;font-size:.8rem;">{$date}</td>
          <td><span class="badge badge-info">{$rule_lbl}</span></td>
          <td style="font-size:.85rem;">{$form_lbl}</td>
          <td style="font-size:.8rem;">{$message}</td>
        </tr>
HTML;
    }

    return <<<HTML
  <!-- Dernieres alertes envoyees -->
  <div class="card">
    <h2><span aria-hidden="true">📬</span> Dernières alertes envoyées</h2>
    <table>
      <thead>
        <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>
      </thead>
      <tbody>
      {$rows}
      </tbody>
    </table>
  </div>
HTML;
}

// ── SOUMISSIONS PAR FORMULAIRE ────────────────────────────────

/**
 * Carte soumissions par formulaire avec taux de validation par formulaire.
 *
 * @param array<int, array<string, mixed>> $by_form_stats
 */
function render_monitoring_by_form(array $by_form_stats): string
{
    if (empty($by_form_stats) || (count($by_form_stats) === 1 && (int)$by_form_stats[0]['total'] == 0)) {
        $body = '<p class="empty-state">Aucune soumission enregistrée.</p>';
    } else {
        $rows = '';
        foreach ($by_form_stats as $bf) {
            $bf_total  = (int)$bf['total'];
            $bf_valide = (int)$bf['valide'];
            $bf_rate   = $bf_total > 0 ? round(($bf_valide / $bf_total) * 100, 1) : 0;
            $label     = h((string)$bf['label']);
            $en_cours  = (int)$bf['en_cours'];
            $refuse    = (int)$bf['refuse'];

            $rows .= <<<HTML
          <tr>
            <td><strong>{$label}</strong></td>
            <td>{$bf_total}</td>
            <td><span class="badge badge-warn">{$en_cours}</span></td>
            <td><span class="badge badge-ok">{$bf_valide}</span></td>
            <td><span class="badge badge-err">{$refuse}</span></td>
            <td><strong>{$bf_rate}%</strong></td>
          </tr>
HTML;
        }
        $body = <<<HTML
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux validation</th></tr>
        </thead>
        <tbody>
        {$rows}
        </tbody>
      </table>
HTML;
    }

    return <<<HTML
  <!-- Soumissions par formulaire -->
  <div class="card">
    <h2><span aria-hidden="true">📊</span> Soumissions par formulaire</h2>
    {$body}
  </div>
HTML;
}

// ── ACTIVITÉ 7 JOURS ──────────────────────────────────────────

/**
 * Carte activité des 7 derniers jours : barres horizontales proportionnelles.
 *
 * @param array<int, array<string, mixed>> $daily_stats
 */
function render_monitoring_daily_activity(array $daily_stats): string
{
    if (empty($daily_stats)) {
        $body = '<p class="empty-state">Aucune soumission ces 7 derniers jours.</p>';
    } else {
        $max_daily = max(array_column($daily_stats, 'cnt'));
        $rows = '';
        foreach ($daily_stats as $ds) {
            $cnt = (int)$ds['cnt'];
            $pct = $max_daily > 0 ? round(($cnt / $max_daily) * 100) : 0;
            $date = h(date('d/m/Y', strtotime((string)$ds['day'])));
            $rows .= <<<HTML
          <tr>
            <td style="white-space:nowrap;">{$date}</td>
            <td><strong>{$cnt}</strong></td>
            <td style="width:60%;"><div style="background:#003189;height:20px;border-radius:2px;width:{$pct}%;min-width:4px;"></div></td>
          </tr>
HTML;
        }
        $body = <<<HTML
      <table>
        <thead><tr><th>Date</th><th>Soumissions</th><th>Barre</th></tr></thead>
        <tbody>
        {$rows}
        </tbody>
      </table>
HTML;
    }

    return <<<HTML
  <!-- Activité récente (7 jours) -->
  <div class="card">
    <h2><span aria-hidden="true">📈</span> Activité des 7 derniers jours</h2>
    {$body}
  </div>
HTML;
}

// ── TOKENS BLOQUÉS ────────────────────────────────────────────

/**
 * Carte tokens bloqués : validateurs en attente depuis trop longtemps.
 *
 * @param array<int, array<string, mixed>> $tokens_bloques
 * @param int                              $bloque_hours   Seuil en heures
 */
function render_monitoring_blocked_tokens(array $tokens_bloques, int $bloque_hours): string
{
    if (empty($tokens_bloques)) {
        $body = '<p class="empty-state">Aucun token bloqué — tout est fluide !</p>';
    } else {
        $rows = '';
        foreach ($tokens_bloques as $tb) {
            $form_label   = h((string)$tb['form_label']);
            $ordre        = (int)$tb['ordre'];
            $step_label   = h((string)$tb['step_label']);
            $email        = h((string)$tb['email']);
            $sent_at      = h(date('d/m/Y H:i', strtotime((string)$tb['sent_at'])));
            $relance      = (int)$tb['relance_count'];
            $expires      = !empty($tb['expires_at'])
                ? h(date('d/m/Y', strtotime((string)$tb['expires_at'])))
                : '—';
            $submitted_by = h((string)$tb['submitted_by']);

            $rows .= <<<HTML
          <tr>
            <td>{$form_label}</td>
            <td><span class="badge badge-info">Étape {$ordre} — {$step_label}</span></td>
            <td>{$email}</td>
            <td style="white-space:nowrap;">{$sent_at}</td>
            <td>{$relance}</td>
            <td style="white-space:nowrap;">{$expires}</td>
            <td>{$submitted_by}</td>
          </tr>
HTML;
        }
        $body = <<<HTML
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Étape</th><th>Validateur</th><th>Envoyé le</th><th>Relances</th><th>Expire le</th><th>Agent</th></tr>
        </thead>
        <tbody>
        {$rows}
        </tbody>
      </table>
HTML;
    }

    return <<<HTML
  <!-- Tokens bloqués -->
  <div class="card">
    <h2><span aria-hidden="true">🚨</span> Tokens bloqués (en attente depuis + de {$bloque_hours}h)</h2>
    {$body}
  </div>
HTML;
}

// ── JOURNAL D'AUDIT ───────────────────────────────────────────
// La fonction render_monitoring_audit_log() est dans lib/render_monitoring_audit.php
// (extrait pour garder ce fichier sous 600 lignes).

// ── JOURNAL DES ENVOIS EMAIL ──────────────────────────────────

/**
 * Carte "Journal des emails" : liste les 20 derniers envois tentés via
 * send_mail() avec leur statut (sent / error / blocked / dry_run / cli_blocked).
 *
 * Permet à l'admin de voir en un coup d'œil :
 *  - Les derniers emails partis avec succès
 *  - Les échecs avec le message d'erreur PHPMailer
 *  - Les blocages (rate limit, dry-run, etc.)
 *
 * Chaque ligne est repliable (<details>) pour afficher la conversation
 * SMTP complète (debug PHPMailer) si elle a été capturée.
 *
 * @param array<int, array<string, mixed>> $mail_logs Entrées de mail_log
 */
function render_monitoring_mail_logs(array $mail_logs): string
{
    if (empty($mail_logs)) {
        // Soit la table mail_log n'existe pas encore (avant migration v23),
        // soit aucune tentative d'envoi n'a été enregistrée.
        return <<<HTML
  <!-- Journal des emails (vide) -->
  <div class="card">
    <h2><span aria-hidden="true">📬</span> Journal des emails</h2>
    <p class="empty-state">Aucune tentative d'envoi d'email journalisée pour le moment.
    Cliquez sur « Tester SMTP » ci-dessus pour générer une première entrée.</p>
  </div>
HTML;
    }

    $rows = '';
    foreach ($mail_logs as $ml) {
        $created_at = h((string)($ml['created_at'] ?? ''));
        $recipient  = h((string)($ml['recipient'] ?? ''));
        $subject    = h(mb_strimwidth((string)($ml['subject'] ?? ''), 0, 60, '…', 'UTF-8'));
        $status     = (string)($ml['status'] ?? 'unknown');
        $error      = h((string)($ml['error_message'] ?? ''));
        $smtp_log   = (string)($ml['smtp_log'] ?? '');
        $actor      = h((string)($ml['actor'] ?? ''));
        $ip         = h((string)($ml['ip'] ?? ''));

        // Badge selon le statut
        $status_labels = [
            'sent'         => ['label' => 'Envoyé',     'cls' => 'badge-ok'],
            'error'        => ['label' => 'Échec',      'cls' => 'badge-err'],
            'blocked'      => ['label' => 'Bloqué',     'cls' => 'badge-warn'],
            'rate_limited' => ['label' => 'Rate-limit', 'cls' => 'badge-warn'],
            'cli_blocked'  => ['label' => 'CLI bloqué', 'cls' => 'badge-warn'],
            'dry_run'      => ['label' => 'Dry-run',    'cls' => 'badge-info'],
        ];
        $badge_info = $status_labels[$status] ?? ['label' => $status, 'cls' => 'badge-info'];
        $badge_html = '<span class="badge ' . $badge_info['cls'] . '">' . $badge_info['label'] . '</span>';

        // Message d'erreur (si échec)
        $err_html = $error !== '' ? '<br><span style="color:#c0392b;font-size:.78rem;">' . $error . '</span>' : '';

        // Bloc debug SMTP repliable (si présent)
        $debug_html = '';
        if ($smtp_log !== '') {
            $debug_html = '<details style="margin-top:.4rem;">'
                . '<summary style="cursor:pointer;font-size:.75rem;color:#003189;">Voir la conversation SMTP</summary>'
                . '<pre style="margin-top:.4rem;max-height:200px;overflow:auto;font-size:.7rem;background:#222;color:#eee;padding:.5rem;border-radius:3px;white-space:pre-wrap;word-break:break-all;">' . h($smtp_log) . '</pre>'
                . '</details>';
        }

        // Formatage de la date
        $date_fmt = '';
        $ts = strtotime($created_at);
        if ($ts !== false) {
            $date_fmt = h(date('d/m/Y H:i:s', $ts));
        } else {
            $date_fmt = $created_at;
        }

        $rows .= <<<HTML
        <tr>
          <td style="white-space:nowrap;font-size:.78rem;">{$date_fmt}</td>
          <td style="font-size:.82rem;">{$recipient}</td>
          <td style="font-size:.82rem;">{$subject}</td>
          <td>{$badge_html}{$err_html}{$debug_html}</td>
          <td style="font-size:.75rem;color:#595959;">{$actor}<br><span style="color:#999;">{$ip}</span></td>
        </tr>
HTML;
    }

    return <<<HTML
  <!-- Journal des emails -->
  <div class="card">
    <h2><span aria-hidden="true">📬</span> Journal des emails (20 derniers)</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.85rem;">
      Toutes les tentatives d'envoi d'email (succès, échecs, blocages) sont journalisées ici.
      Cliquez sur « Voir la conversation SMTP » pour diagnostiquer les erreurs.
    </p>
    <table>
      <thead>
        <tr><th>Date</th><th>Destinataire</th><th>Sujet</th><th>Statut</th><th>Acteur / IP</th></tr>
      </thead>
      <tbody>
      {$rows}
      </tbody>
    </table>
  </div>
HTML;
}

// ── COMPOSITION DU CONTENU ────────────────────────────────────

/**
 * Compose l'ensemble du contenu HTML de la page Surveillance.
 *
 * @param array<string, mixed> $ctx Données préparées par monitoring.php :
 *   total_sub, valide_sub, en_cours_sub, refuse_sub, taux_validation,
 *   avg_days, avg_hours, tokens_bloques, bloque_hours, active_alerts,
 *   recent_alerts, by_form_stats, daily_stats, smtp_status, smtp_detail,
 *   smtp_debug_log, mail_logs, last_remind, last_alert_check, audit_filters,
 *   audit_total, audit_total_pages, audit_page, audit_logs, action_types,
 *   audit_base_url, audit_base_qs.
 */
function render_monitoring_content(array $ctx): string
{
    $total_sub       = (int)($ctx['total_sub'] ?? 0);
    $valide_sub      = (int)($ctx['valide_sub'] ?? 0);
    $en_cours_sub    = (int)($ctx['en_cours_sub'] ?? 0);
    $refuse_sub      = (int)($ctx['refuse_sub'] ?? 0);
    $bloque_hours    = (int)($ctx['bloque_hours'] ?? 0);
    $tokens_bloques  = $ctx['tokens_bloques'] ?? [];
    $active_alerts   = $ctx['active_alerts'] ?? [];
    $recent_alerts   = $ctx['recent_alerts'] ?? [];
    $by_form_stats   = $ctx['by_form_stats'] ?? [];
    $daily_stats     = $ctx['daily_stats'] ?? [];
    $smtp_status     = (string)($ctx['smtp_status'] ?? 'inconnu');
    $smtp_detail     = (string)($ctx['smtp_detail'] ?? '');
    $smtp_debug_log  = (string)($ctx['smtp_debug_log'] ?? '');
    $mail_logs       = $ctx['mail_logs'] ?? [];
    $last_remind     = (string)($ctx['last_remind'] ?? '');
    $last_alert_check = (string)($ctx['last_alert_check'] ?? '');

    $stats_html         = render_monitoring_stats($ctx);
    $repartition_html   = render_monitoring_repartition($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
    $smtp_html          = render_monitoring_smtp_card($smtp_status, $smtp_detail, $smtp_debug_log);
    $scripts_html       = render_monitoring_scripts_card($last_remind, $last_alert_check);
    $active_alerts_html = render_monitoring_active_alerts($active_alerts);
    $recent_alerts_html = render_monitoring_recent_alerts($recent_alerts);
    $by_form_html       = render_monitoring_by_form($by_form_stats);
    $daily_html         = render_monitoring_daily_activity($daily_stats);
    $blocked_html       = render_monitoring_blocked_tokens($tokens_bloques, $bloque_hours);
    $mail_logs_html     = render_monitoring_mail_logs($mail_logs);
    $audit_html         = render_monitoring_audit_log($ctx);

    return <<<HTML
  <h1><span aria-hidden="true">🖥</span> Surveillance et diagnostic</h1>

{$stats_html}

{$repartition_html}

  <!-- Santé système -->
  <div class="grid-2">
{$smtp_html}

{$scripts_html}
  </div>

{$active_alerts_html}

{$recent_alerts_html}

{$by_form_html}

{$daily_html}

{$blocked_html}

{$mail_logs_html}

{$audit_html}
HTML;
}
