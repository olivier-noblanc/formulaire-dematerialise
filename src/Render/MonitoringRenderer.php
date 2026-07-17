<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Rendu de la page de surveillance / monitoring (monitoring.php).
 *
 * Chaque fonction render_monitoring_*() historique devient une méthode statique.
 * Les wrappers globaux dans lib/render_monitoring.php assurent la rétrocompatibilité.
 */
final class MonitoringRenderer
{
    /**
     * CSS propre à la page Surveillance (chargé depuis lib/monitoring_page.css).
     */
    public static function pageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(__DIR__ . '/../../lib/monitoring_page.css');
        }
        return $css;
    }

    /**
     * Entrées de navigation latérale spécifiques à la page Surveillance.
     *
     * @return array<string, array{href: string, label: string, icon: string}>
     */
    public static function navExtra(): array
    {
        return [
            'monitoring' => ['href' => 'index.php?p=monitoring',   'label' => 'Surveillance', 'icon' => '🖥'],
            'alerts'    => ['href' => 'index.php?p=admin_alerts', 'label' => 'Alertes', 'icon' => '🔔'],
            'stats'     => ['href' => 'index.php?p=stats',         'label' => 'Statistiques', 'icon' => '📈'],
            'backup'    => ['href' => 'index.php?p=backup',        'label' => 'Sauvegarde', 'icon' => '💾'],
            'health'    => ['href' => 'index.php?p=health',        'label' => 'Santé', 'icon' => '🏥'],
        ];
    }

    /**
     * Bandeau de 6 cartes stats.
     *
     * @param array<string, mixed> $ctx
     */
    public static function stats(array $ctx): string
    {
        $total_sub       = (int) ($ctx['total_sub'] ?? 0);
        $taux_validation = $ctx['taux_validation'] ?? 0;
        $avg_days        = (float) ($ctx['avg_days'] ?? 0);
        $avg_hours       = (float) ($ctx['avg_hours'] ?? 0);
        $en_cours_sub    = (int) ($ctx['en_cours_sub'] ?? 0);
        $tokens_bloques  = $ctx['tokens_bloques'] ?? [];
        $active_alerts   = $ctx['active_alerts'] ?? [];

        $avg_label = $avg_days > 0 ? $avg_days . ' j' : $avg_hours . ' h';
        $alert_cls = empty($active_alerts) ? 'success' : 'danger';

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

    /**
     * Carte avec graphique donut des statuts de soumission.
     */
    public static function repartition(int $total_sub, int $valide_sub, int $en_cours_sub, int $refuse_sub): string
    {
        $donut = App::html()->renderDonutChart($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
        return <<<HTML
              <!-- Graphique de répartition des statuts -->
              <div class="card">
                <h2><span aria-hidden="true">📊</span> Répartition des soumissions</h2>
                {$donut}
              </div>
            HTML;
    }

    /**
     * Carte santé SMTP.
     */
    public static function smtpCard(string $smtp_status, string $smtp_detail, string $smtp_debug_log = ''): string
    {
        $smtp_host    = \App\Core\App::html()->escape(App::settings()->get('smtp_host'));
        $smtp_port    = \App\Core\App::html()->escape(App::settings()->get('smtp_port'));
        $smtp_secure  = \App\Core\App::html()->escape(App::settings()->get('smtp_secure', '') ?: 'Aucun');
        $mail_dry_run = App::settings()->get('mail_dry_run', '0') === '1';

        if ($smtp_status === 'ok') {
            $dot         = '<span class="health-dot health-ok"></span>';
            $badge       = '<span class="badge badge-ok">Fonctionnel</span>';
            $detail_html = \App\Core\App::html()->escape($smtp_detail);
        } elseif ($smtp_status === 'erreur') {
            $dot         = '<span class="health-dot health-err"></span>';
            $badge       = '<span class="badge badge-err">Erreur</span>';
            $detail_html = \App\Core\App::html()->escape($smtp_detail);
        } else {
            $dot         = '<span class="health-dot health-unknown"></span>';
            $badge       = '<span class="badge badge-info">Non testé</span>';
            $detail_html = 'Cliquez sur le bouton pour tester la connexion SMTP.';
        }

        $dryrun_html = $mail_dry_run
            ? '<div class="warning-box" style="margin-bottom:.75rem;font-size:.85rem;"><strong>⚠ Mode Dry-Run actif</strong> — Aucun email réel n\'est envoyé. Tous les envois sont journalisés mais ne quittent pas le serveur. Désactivez le Dry-Run dans <a href="index.php?p=admin_settings#section-email-verify">Paramètres → Sécurité email</a> pour activer l\'envoi réel.</div>'
            : '';

        $debug_html = '';
        if ($smtp_debug_log !== '') {
            $debug_html = '<details style="margin-top:1rem;border:1px solid #ddd;border-radius:4px;padding:.75rem;background:#fafafa;">'
                . '<summary style="cursor:pointer;font-size:.85rem;font-weight:bold;color:#003189;">📋 Conversation SMTP (debug)</summary>'
                . '<pre style="margin-top:.75rem;max-height:300px;overflow:auto;font-size:.75rem;background:#222;color:#eee;padding:.75rem;border-radius:3px;white-space:pre-wrap;word-break:break-all;">' . \App\Core\App::html()->escape($smtp_debug_log) . '</pre>'
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

    /**
     * Carte scripts automatisés.
     */
    public static function scriptsCard(string $last_remind, string $last_alert_check): string
    {
        $remind_html = '';
        if ($last_remind !== '' && $last_remind !== '0') {
            $remind_ts  = strtotime($last_remind);
            $remind_age = ($remind_ts !== false) ? (time() - $remind_ts) : 999999;
            $remind_ok  = $remind_age < 86400;
            $remind_dot_cls = $remind_ok ? 'health-ok' : 'health-warn';
            $remind_date    = \App\Core\App::html()->escape(date('d/m/Y à H:i', $remind_ts !== false ? $remind_ts : 0));
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

        $alert_html = '';
        if ($last_alert_check !== '' && $last_alert_check !== '0') {
            $alert_ts  = strtotime($last_alert_check);
            $alert_age = ($alert_ts !== false) ? (time() - $alert_ts) : 999999;
            $alert_ok  = $alert_age < 86400;
            $alert_dot_cls = $alert_ok ? 'health-ok' : 'health-warn';
            $alert_date    = \App\Core\App::html()->escape(date('d/m/Y à H:i', $alert_ts !== false ? $alert_ts : 0));
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

        $delai_relance    = \App\Core\App::html()->escape(App::settings()->get('delai_relance_h', '48'));
        $relance_max      = \App\Core\App::html()->escape(App::settings()->get('relance_max', '3'));
        $token_expire_days = \App\Core\App::html()->escape(App::settings()->get('token_expire_days', '30'));

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

    /**
     * Carte alertes actives.
     *
     * @param array<int, array{days_remaining: int, form_label: string, nom_agent: string, deadline_formatted: string, pending_steps: int}> $active_alerts
     */
    public static function activeAlerts(array $active_alerts): string
    {
        if ($active_alerts === []) {
            return '';
        }

        $rows = '';
        foreach ($active_alerts as $active_alert) {
            $days = (int) ($active_alert['days_remaining'] ?? 0);
            $row_cls = $days < 0 ? 'urgent' : ($days <= 2 ? 'urgent' : ($days <= 5 ? 'warning' : 'ok'));
            $days_cls = $days < 0 ? 'overdue' : ($days <= 2 ? 'critical' : ($days <= 5 ? 'warning' : 'ok'));
            $days_text = $days < 0 ? 'J+' . abs($days) : ($days === 0 ? 'Jour J' : 'J-' . $days);

            $form_label    = \App\Core\App::html()->escape((string) ($active_alert['form_label'] ?? ''));
            $nom_agent     = \App\Core\App::html()->escape((string) ($active_alert['nom_agent'] ?? ''));
            $deadline_fmt  = \App\Core\App::html()->escape((string) ($active_alert['deadline_formatted'] ?? ''));
            $pending_steps = (int) ($active_alert['pending_steps'] ?? 0);

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

    /**
     * Carte dernières alertes envoyées.
     *
     * @param array<int, array{sent_at: string, rule_label: string, form_label: string, message: string}> $recent_alerts
     */
    public static function recentAlerts(array $recent_alerts): string
    {
        if ($recent_alerts === []) {
            return '';
        }

        $rows = '';
        foreach ($recent_alerts as $recent_alert) {
            $date      = \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime((string) ($recent_alert['sent_at'] ?? 'now'))));
            $rule_lbl  = \App\Core\App::html()->escape((string) ($recent_alert['rule_label'] ?? 'Règle supprimée'));
            $form_lbl  = \App\Core\App::html()->escape((string) ($recent_alert['form_label'] ?? ''));
            $message   = \App\Core\App::html()->escape((string) ($recent_alert['message'] ?? ''));

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

    /**
     * Carte soumissions par formulaire.
     *
     * @param array<int, array{total: int, valide: int, label: string, en_cours: int, refuse: int}> $by_form_stats
     */
    public static function byForm(array $by_form_stats): string
    {
        if ($by_form_stats === [] || (count($by_form_stats) === 1 && (int) $by_form_stats[0]['total'] === 0)) {
            $body = '<p class="empty-state">Aucune soumission enregistrée.</p>';
        } else {
            $rows = '';
            foreach ($by_form_stats as $by_form_stat) {
                $bf_total  = (int) $by_form_stat['total'];
                $bf_valide = (int) $by_form_stat['valide'];
                $bf_rate   = $bf_total > 0 ? round(($bf_valide / $bf_total) * 100, 1) : 0;
                $label     = \App\Core\App::html()->escape((string) $by_form_stat['label']);
                $en_cours  = (int) $by_form_stat['en_cours'];
                $refuse    = (int) $by_form_stat['refuse'];

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

    /**
     * Carte activité des 7 derniers jours.
     *
     * @param array<int, array{cnt: int, day: string}> $daily_stats
     */
    public static function dailyActivity(array $daily_stats): string
    {
        if ($daily_stats === []) {
            $body = '<p class="empty-state">Aucune soumission ces 7 derniers jours.</p>';
        } else {
            $column = array_column($daily_stats, 'cnt');
            $max_daily = $column !== [] ? max($column) : 0;
            $rows = '';
            foreach ($daily_stats as $daily_stat) {
                $cnt = (int) $daily_stat['cnt'];
                $pct = $max_daily > 0 ? round(($cnt / $max_daily) * 100) : 0;
                $date = \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime((string) $daily_stat['day'])));
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

    /**
     * Carte tokens bloqués.
     *
     * @param array<int, array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}> $tokens_bloques
     */
    public static function blockedTokens(array $tokens_bloques, int $bloque_hours): string
    {
        if ($tokens_bloques === []) {
            $body = '<p class="empty-state">Aucun token bloqué — tout est fluide !</p>';
        } else {
            $rows = '';
            foreach ($tokens_bloques as $token_bloque) {
                $form_label   = \App\Core\App::html()->escape((string) $token_bloque['form_label']);
                $ordre        = (int) $token_bloque['ordre'];
                $step_label   = \App\Core\App::html()->escape((string) $token_bloque['step_label']);
                $email        = \App\Core\App::html()->escape((string) $token_bloque['email']);
                $sent_at      = \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime((string) $token_bloque['sent_at'])));
                $relance      = (int) $token_bloque['relance_count'];
                $expires      = empty($token_bloque['expires_at'])
                    ? '—'
                    : \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime((string) $token_bloque['expires_at'])));
                $submitted_by = \App\Core\App::html()->escape((string) $token_bloque['submitted_by']);

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

    /**
     * Carte "Journal des emails".
     *
     * @param array<int, array{created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> $mail_logs
     */
    public static function mailLogs(array $mail_logs): string
    {
        if ($mail_logs === []) {
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
        foreach ($mail_logs as $mail_log) {
            $created_at = \App\Core\App::html()->escape((string) ($mail_log['created_at'] ?? ''));
            $recipient  = \App\Core\App::html()->escape((string) ($mail_log['recipient'] ?? ''));
            $subject    = \App\Core\App::html()->escape(mb_strimwidth((string) ($mail_log['subject'] ?? ''), 0, 60, '…', 'UTF-8'));
            $status     = (string) ($mail_log['status'] ?? 'unknown');
            $error      = \App\Core\App::html()->escape((string) ($mail_log['error_message'] ?? ''));
            $smtp_log   = (string) ($mail_log['smtp_log'] ?? '');
            $actor      = \App\Core\App::html()->escape((string) ($mail_log['actor'] ?? ''));
            $ip         = \App\Core\App::html()->escape((string) ($mail_log['ip'] ?? ''));

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

            $err_html = $error !== '' ? '<br><span style="color:#c0392b;font-size:.78rem;">' . $error . '</span>' : '';

            $debug_html = '';
            if ($smtp_log !== '') {
                $debug_html = '<details style="margin-top:.4rem;">'
                    . '<summary style="cursor:pointer;font-size:.75rem;color:#003189;">Voir la conversation SMTP</summary>'
                    . '<pre style="margin-top:.4rem;max-height:200px;overflow:auto;font-size:.7rem;background:#222;color:#eee;padding:.5rem;border-radius:3px;white-space:pre-wrap;word-break:break-all;">' . \App\Core\App::html()->escape($smtp_log) . '</pre>'
                    . '</details>';
            }

            $date_fmt = '';
            $ts = strtotime($created_at);
            $date_fmt = $ts !== false ? \App\Core\App::html()->escape(date('d/m/Y H:i:s', $ts)) : $created_at;

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

    /**
     * Compose l'ensemble du contenu HTML de la page Surveillance.
     *
     * @param array<string, mixed> $ctx
     */
    public static function content(array $ctx): string
    {
        $total_sub       = (int) ($ctx['total_sub'] ?? 0);
        $valide_sub      = (int) ($ctx['valide_sub'] ?? 0);
        $en_cours_sub    = (int) ($ctx['en_cours_sub'] ?? 0);
        $refuse_sub      = (int) ($ctx['refuse_sub'] ?? 0);
        $bloque_hours    = (int) ($ctx['bloque_hours'] ?? 0);
        $tokens_bloques  = $ctx['tokens_bloques'] ?? [];
        $active_alerts   = $ctx['active_alerts'] ?? [];
        $recent_alerts   = $ctx['recent_alerts'] ?? [];
        $by_form_stats   = $ctx['by_form_stats'] ?? [];
        $daily_stats     = $ctx['daily_stats'] ?? [];
        $smtp_status     = (string) ($ctx['smtp_status'] ?? 'inconnu');
        $smtp_detail     = (string) ($ctx['smtp_detail'] ?? '');
        $smtp_debug_log  = (string) ($ctx['smtp_debug_log'] ?? '');
        $mail_logs       = $ctx['mail_logs'] ?? [];
        $last_remind     = (string) ($ctx['last_remind'] ?? '');
        $last_alert_check = (string) ($ctx['last_alert_check'] ?? '');

        $stats_html         = self::stats($ctx);
        $repartition_html   = self::repartition($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
        $smtp_html          = self::smtpCard($smtp_status, $smtp_detail, $smtp_debug_log);
        $scripts_html       = self::scriptsCard($last_remind, $last_alert_check);
        $active_alerts_html = self::activeAlerts($active_alerts);
        $recent_alerts_html = self::recentAlerts($recent_alerts);
        $by_form_html       = self::byForm($by_form_stats);
        $daily_html         = self::dailyActivity($daily_stats);
        $blocked_html       = self::blockedTokens($tokens_bloques, $bloque_hours);
        $mail_logs_html     = self::mailLogs($mail_logs);
        $audit_html         = self::auditLog($ctx);

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

    /**
     * Carte journal d'audit (S5-B / Action 1).
     *
     * @param array<string, mixed> $ctx
     */
    public static function auditLog(array $ctx): string
    {
        $audit_filters     = $ctx['audit_filters'] ?? [];
        $audit_total       = (int) ($ctx['audit_total'] ?? 0);
        $audit_total_pages = (int) ($ctx['audit_total_pages'] ?? 1);
        $audit_page        = (int) ($ctx['audit_page'] ?? 1);
        $audit_logs        = $ctx['audit_logs'] ?? [];
        $action_types      = $ctx['action_types'] ?? [];
        $audit_base_url    = (string) ($ctx['audit_base_url'] ?? 'index.php?p=monitoring');
        $audit_base_qs     = (string) ($ctx['audit_base_qs'] ?? '');

        $action_options = '<option value="">Toutes les actions</option>';
        foreach ($action_types as $action_type) {
            $at_h = \App\Core\App::html()->escape((string) $action_type);
            $sel  = ($audit_filters['log_action'] ?? '') === $action_type ? 'selected' : '';
            $action_options .= "<option value=\"{$at_h}\" {$sel}>{$at_h}</option>";
        }

        $log_date_debut = \App\Core\App::html()->escape((string) ($audit_filters['log_date_debut'] ?? ''));
        $log_date_fin   = \App\Core\App::html()->escape((string) ($audit_filters['log_date_fin'] ?? ''));
        $log_actor_v    = \App\Core\App::html()->escape((string) ($audit_filters['log_actor'] ?? ''));
        $log_target_v   = \App\Core\App::html()->escape((string) ($audit_filters['log_target'] ?? ''));

        $export_sep = $audit_base_qs !== '' && $audit_base_qs !== '0' ? '&' : '?';
        $export_url = \App\Core\App::html()->escape($audit_base_url . $export_sep . 'export_audit=1');
        $s_suffix = $audit_total > 1 ? 's' : '';

        $export_link = '';
        if ($audit_total > 0) {
            $export_link = "· <a href=\"{$export_url}\" class=\"btn btn-secondary\" style=\"font-size:.75rem;padding:.3rem .6rem;text-decoration:none;\"><span aria-hidden=\"true\">📥</span> Export CSV</a>";
        }

        if (empty($audit_logs)) {
            $table_html = '<p class="empty-state">Aucune entrée dans le journal d\'audit pour ces filtres.</p>';
        } else {
            $rows = '';
            foreach ($audit_logs as $audit_log) {
                $date   = \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime((string) ($audit_log['created_at'] ?? 'now'))));
                $action = \App\Core\App::html()->escape((string) ($audit_log['action'] ?? ''));
                $actor  = \App\Core\App::html()->escape((string) ($audit_log['actor'] ?? ''));
                $target = \App\Core\App::html()->escape((string) ($audit_log['target'] ?? ''));
                $detail = \App\Core\App::html()->escape((string) ($audit_log['detail'] ?? ''));
                $ip     = \App\Core\App::html()->escape((string) ($audit_log['ip'] ?? ''));
                $rows .= <<<HTML
                              <tr>
                                <td style="white-space:nowrap;font-size:.8rem;">{$date}</td>
                                <td><span class="badge badge-info">{$action}</span></td>
                                <td style="font-size:.8rem;">{$actor}</td>
                                <td style="font-size:.8rem;">{$target}</td>
                                <td style="font-size:.8rem;">{$detail}</td>
                                <td style="font-size:.8rem;color:#595959;">{$ip}</td>
                              </tr>
                    HTML;
            }
            $table_html = <<<HTML
                      <table>
                        <thead>
                          <tr><th>Date</th><th>Action</th><th>Acteur</th><th>Cible</th><th>Détail</th><th>IP</th></tr>
                        </thead>
                        <tbody>
                        {$rows}
                        </tbody>
                      </table>
                HTML;
        }

        $pagination = '';
        if ($audit_total_pages > 1) {
            $prev_link = '';
            $next_link = '';
            if ($audit_page > 1) {
                $prev_url = \App\Core\App::html()->escape($audit_base_url) . '&log_page=' . ($audit_page - 1);
                $prev_link = "<a href=\"{$prev_url}\" class=\"btn btn-secondary\" style=\"font-size:.8rem;padding:.3rem .75rem;\">← Précédent</a>";
            }
            $page_info = "<span style=\"font-size:.85rem;color:#555;\">Page {$audit_page} / {$audit_total_pages}</span>";
            if ($audit_page < $audit_total_pages) {
                $next_url = \App\Core\App::html()->escape($audit_base_url) . '&log_page=' . ($audit_page + 1);
                $next_link = "<a href=\"{$next_url}\" class=\"btn btn-secondary\" style=\"font-size:.8rem;padding:.3rem .75rem;\">Suivant →</a>";
            }
            $pagination = <<<HTML
                        <div class="pagination" style="display:flex;gap:.5rem;align-items:center;margin:1.5rem 0;flex-wrap:wrap;">
                          {$prev_link}
                          {$page_info}
                          {$next_link}
                        </div>
                HTML;
        }

        return <<<HTML
              <!-- Journal d'audit — S5-B / Action 1 : filtres avancés + pagination + export CSV -->
              <div class="card">
                <h2><span aria-hidden="true">📝</span> Journal d'audit</h2>
                <p style="margin-bottom:1rem;color:#555;font-size:.85rem;">
                  Traçabilité complète des actions du système. Filtrez par date, action, acteur ou cible, puis exportez en CSV pour archivage.
                </p>
                <form method="GET" class="audit-filters" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1rem;padding:1rem;background:#f5f5fe;border-radius:6px;align-items:end;">
                  <div>
                    <label for="log_date_debut" style="font-size:.8rem;font-weight:bold;display:block;margin-bottom:.25rem;">Date de début</label>
                    <input type="date" id="log_date_debut" name="log_date_debut" value="{$log_date_debut}" style="width:100%;">
                  </div>
                  <div>
                    <label for="log_date_fin" style="font-size:.8rem;font-weight:bold;display:block;margin-bottom:.25rem;">Date de fin</label>
                    <input type="date" id="log_date_fin" name="log_date_fin" value="{$log_date_fin}" style="width:100%;">
                  </div>
                  <div>
                    <label for="log_action" style="font-size:.8rem;font-weight:bold;display:block;margin-bottom:.25rem;">Action</label>
                    <select id="log_action" name="log_action" style="width:100%;">
                      {$action_options}
                    </select>
                  </div>
                  <div>
                    <label for="log_actor" style="font-size:.8rem;font-weight:bold;display:block;margin-bottom:.25rem;">Acteur (email)</label>
                    <input type="text" id="log_actor" name="log_actor" value="{$log_actor_v}" placeholder="agent@exemple.invalid" style="width:100%;">
                  </div>
                  <div>
                    <label for="log_target" style="font-size:.8rem;font-weight:bold;display:block;margin-bottom:.25rem;">Cible</label>
                    <input type="text" id="log_target" name="log_target" value="{$log_target_v}" placeholder="token, settings..." style="width:100%;">
                  </div>
                  <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .8rem;"><span aria-hidden="true">🔍</span> Filtrer</button>
                    <a href="index.php?p=monitoring" class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .8rem;">Réinitialiser</a>
                  </div>
                </form>
                <p style="margin-bottom:.75rem;font-size:.85rem;color:#555;">
                  <strong>{$audit_total}</strong> entrée{$s_suffix} trouvée{$s_suffix}
                  {$export_link}
                </p>
                {$table_html}
                {$pagination}
              </div>
            HTML;
    }
}
