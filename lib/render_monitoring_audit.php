<?php
declare(strict_types=1);

/**
 * Rendu de la section « Journal d'audit » de la page Surveillance.
 *
 * Extrait dans un fichier dédié pour garder lib/render_monitoring.php
 * sous 600 lignes (refactor « all-under-600 »). Comportement strictement
 * identique au rendu historique de monitoring.php.
 *
 *  - render_monitoring_audit_log() : filtres avancés (date/action/acteur/cible),
 *                                    pagination 50/page, export CSV (S5-B / Action 1)
 *
 * @package lib
 * @see /monitoring.php
 * @see /lib/render_monitoring.php
 */

// ── JOURNAL D'AUDIT ───────────────────────────────────────────

/**
 * Carte journal d'audit : filtres avancés (date/action/acteur/cible),
 * pagination 50/page, export CSV (S5-B / Action 1).
 *
 * @param array<string, mixed> $ctx {
 *       array<string, string> $audit_filters     Filtres actifs
 *       int                   $audit_total       Nombre total d'entrées filtrées
 *       int                   $audit_total_pages Nombre total de pages
 *       int                   $audit_page        Page courante (1-based)
 *       array<int, array<string, mixed>> $audit_logs   Entrées paginées
 *       array<int, string>   $action_types      Types d'action distincts (filtre)
 *       string                $audit_base_url    URL de base pagination (sans log_page)
 *       string                $audit_base_qs     Query string de base (sans log_page)
 * }
 */
function render_monitoring_audit_log(array $ctx): string
{
    $audit_filters     = $ctx['audit_filters'] ?? [];
    $audit_total       = (int)($ctx['audit_total'] ?? 0);
    $audit_total_pages = (int)($ctx['audit_total_pages'] ?? 1);
    $audit_page        = (int)($ctx['audit_page'] ?? 1);
    $audit_logs        = $ctx['audit_logs'] ?? [];
    $action_types      = $ctx['action_types'] ?? [];
    $audit_base_url    = (string)($ctx['audit_base_url'] ?? 'index.php?p=monitoring');
    $audit_base_qs     = (string)($ctx['audit_base_qs'] ?? '');

    // ── Filtres : options du select « action » ──
    $action_options = '<option value="">Toutes les actions</option>';
    foreach ($action_types as $at) {
        $at_h = h((string)$at);
        $sel  = ($audit_filters['log_action'] ?? '') === $at ? 'selected' : '';
        $action_options .= "<option value=\"{$at_h}\" {$sel}>{$at_h}</option>";
    }

    $log_date_debut = h((string)($audit_filters['log_date_debut'] ?? ''));
    $log_date_fin   = h((string)($audit_filters['log_date_fin'] ?? ''));
    $log_action_v   = h((string)($audit_filters['log_action'] ?? ''));
    $log_actor_v    = h((string)($audit_filters['log_actor'] ?? ''));
    $log_target_v   = h((string)($audit_filters['log_target'] ?? ''));

    $export_sep = $audit_base_qs ? '&' : '?';
    $export_url = h($audit_base_url . $export_sep . 'export_audit=1');
    $s_suffix = $audit_total > 1 ? 's' : '';

    // ── Export CSV (lien) ──
    $export_link = '';
    if ($audit_total > 0) {
        $export_link = "· <a href=\"{$export_url}\" class=\"btn btn-secondary\" style=\"font-size:.75rem;padding:.3rem .6rem;text-decoration:none;\"><span aria-hidden=\"true\">📥</span> Export CSV</a>";
    }

    // ── Tableau des entrées ──
    if (empty($audit_logs)) {
        $table_html = '<p class="empty-state">Aucune entrée dans le journal d\'audit pour ces filtres.</p>';
    } else {
        $rows = '';
        foreach ($audit_logs as $al) {
            $date   = h(date('d/m/Y H:i', strtotime((string)($al['created_at'] ?? 'now'))));
            $action = h((string)($al['action'] ?? ''));
            $actor  = h((string)($al['actor'] ?? ''));
            $target = h((string)($al['target'] ?? ''));
            $detail = h((string)($al['detail'] ?? ''));
            $ip     = h((string)($al['ip'] ?? ''));
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

    // ── Pagination ──
    $pagination = '';
    if ($audit_total_pages > 1) {
        $prev_link = '';
        $next_link = '';
        if ($audit_page > 1) {
            $prev_url = h($audit_base_url) . '&log_page=' . ($audit_page - 1);
            $prev_link = "<a href=\"{$prev_url}\" class=\"btn btn-secondary\" style=\"font-size:.8rem;padding:.3rem .75rem;\">← Précédent</a>";
        }
        $page_info = "<span style=\"font-size:.85rem;color:#555;\">Page {$audit_page} / {$audit_total_pages}</span>";
        if ($audit_page < $audit_total_pages) {
            $next_url = h($audit_base_url) . '&log_page=' . ($audit_page + 1);
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
        <input type="text" id="log_actor" name="log_actor" value="{$log_actor_v}" placeholder="agent@dreets.gouv.fr" style="width:100%;">
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
