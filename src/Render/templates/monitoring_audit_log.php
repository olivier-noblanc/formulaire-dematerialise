<?php
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
    $export_link = "· <a href=\"{$export_url}\" class=\"btn btn-secondary u-fs-xxs-p-xs-td-none\"><span aria-hidden=\"true\">📥</span> Export CSV</a>";
}

if (in_array($audit_logs, ['', null, '0'], true)) {
    $table_html = '<p class="empty-state">Aucune entrée dans le journal d\'audit pour ces filtres.</p>';
} else {
    $rows = '';
    foreach ($audit_logs as $audit_log) {
        $date   = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) ($audit_log['created_at'] ?? 'now')));
        $action = \App\Core\App::html()->escape((string) ($audit_log['action'] ?? ''));
        $actor  = \App\Core\App::html()->escape((string) ($audit_log['actor'] ?? ''));
        $target = \App\Core\App::html()->escape((string) ($audit_log['target'] ?? ''));
        $detail = \App\Core\App::html()->escape((string) ($audit_log['detail'] ?? ''));
        $ip     = \App\Core\App::html()->escape((string) ($audit_log['ip'] ?? ''));
        $rows .= <<<HTML
                      <tr>
                        <td class="u-fon-whi">{$date}</td>
                        <td><span class="badge badge-info">{$action}</span></td>
                        <td class="u-fon">{$actor}</td>
                        <td class="u-fon">{$target}</td>
                        <td class="u-fon">{$detail}</td>
                        <td class="u-col-fon-3">{$ip}</td>
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
        $prev_link = "<a href=\"{$prev_url}\" class=\"btn btn-secondary u-fs-xs-p-075\">← Précédent</a>";
    }
    $page_info = "<span class=\"u-c-muted-fs-sm\">Page {$audit_page} / {$audit_total_pages}</span>";
    if ($audit_page < $audit_total_pages) {
        $next_url = \App\Core\App::html()->escape($audit_base_url) . '&log_page=' . ($audit_page + 1);
        $next_link = "<a href=\"{$next_url}\" class=\"btn btn-secondary u-fs-xs-p-075\">Suivant →</a>";
    }
    $pagination = <<<HTML
                    <div class="pagination flex-gap5-5">
                      {$prev_link}
                      {$page_info}
                      {$next_link}
                    </div>
        HTML;
}
?>
<!-- Journal d'audit — S5-B / Action 1 : filtres avancés + pagination + export CSV -->
<div class="card">
  <h2><span aria-hidden="true">📝</span> Journal d'audit</h2>
  <p class="caption-10">
    Traçabilité complète des actions du système. Filtrez par date, action, acteur ou cible, puis exportez en CSV pour archivage.
  </p>
  <form method="GET" class="audit-filters info-box">
    <div>
      <label for="log_date_debut" class="u-dis-fon-fon-mar">Date de début</label>
      <input type="date" id="log_date_debut" name="log_date_debut" value="<?= $log_date_debut ?>" class="w-100">
    </div>
    <div>
      <label for="log_date_fin" class="u-dis-fon-fon-mar">Date de fin</label>
      <input type="date" id="log_date_fin" name="log_date_fin" value="<?= $log_date_fin ?>" class="w-100">
    </div>
    <div>
      <label for="log_action" class="u-dis-fon-fon-mar">Action</label>
      <select id="log_action" name="log_action" class="w-100">
        <?= $action_options ?>
      </select>
    </div>
    <div>
      <label for="log_actor" class="u-dis-fon-fon-mar">Acteur (email)</label>
      <input type="text" id="log_actor" name="log_actor" value="<?= $log_actor_v ?>" placeholder="agent@exemple.invalid" class="w-100">
    </div>
    <div>
      <label for="log_target" class="u-dis-fon-fon-mar">Cible</label>
      <input type="text" id="log_target" name="log_target" value="<?= $log_target_v ?>" placeholder="token, settings..." class="w-100">
    </div>
    <div class="flex-gap5-7">
      <button type="submit" class="btn btn-primary btn-sm-6"><span aria-hidden="true">🔍</span> Filtrer</button>
      <a href="index.php?p=monitoring" class="btn btn-secondary btn-sm-6">Réinitialiser</a>
    </div>
  </form>
  <p class="caption-7">
    <strong><?= $audit_total ?></strong> entrée<?= $s_suffix ?> trouvée<?= $s_suffix ?>
    <?= $export_link ?>
  </p>
  <?= $table_html ?>
  <?= $pagination ?>
</div>
