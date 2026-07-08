<?php
// admin_alerts.php — Configuration des regles d'alerte parametrables
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

App::auth()->requireAdmin();

$pdo = App::db()->getPdo();
$success_msg = '';
$error_msg = '';

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::security()->requireCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_rule') {
        $form_id = trim($_POST['form_id'] ?? '');
        $days_before = (int)($_POST['days_before'] ?? 5);
        $condition_type = trim($_POST['condition_type'] ?? 'steps_incomplete');
        $notify_who = trim($_POST['notify_who'] ?? 'admin');
        $label = trim($_POST['label'] ?? '');
        $custom_email = trim($_POST['custom_email'] ?? '');

        if (empty($form_id)) {
            $error_msg = 'Veuillez sélectionner un formulaire.';
        } elseif ($days_before < 0) {
            $error_msg = 'Le nombre de jours doit être positif ou zéro.';
        } elseif (empty($label)) {
            $error_msg = 'Le libellé de la règle est obligatoire.';
        } else {
            // Si notify_who = email personnalise, utiliser custom_email
            if ($notify_who === 'custom' && !empty($custom_email)) {
                if (!filter_var($custom_email, FILTER_VALIDATE_EMAIL)) {
                    $error_msg = 'L\'adresse email personnalisée est invalide.';
                } else {
                    $notify_who = $custom_email;
                }
            }

            if (empty($error_msg)) {
                try {
                    // T-01/P-01/O-02 : générer l'UUID côté PHP (generate_uuid est une
                    // fonction PHP, pas SQLite). Binding via paramètre ?.
                    $rule_id = generate_uuid();
                    $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)")
                        ->execute([$rule_id, $form_id, $days_before, $condition_type, $notify_who, $label]);
                    App::audit()->log('alert_rule_create', 'form:' . $form_id, 'Règle d\'alerte créée : ' . $label);
                    $success_msg = 'Règle d\'alerte créée avec succès.';
                } catch (Exception $e) {
                    $error_msg = 'Erreur lors de la création : ' . $e->getMessage();
                }
            }
        }
    }
    elseif ($action === 'update_rule') {
        $rule_id = trim($_POST['rule_id'] ?? '');
        $days_before = (int)($_POST['days_before'] ?? 5);
        $condition_type = trim($_POST['condition_type'] ?? 'steps_incomplete');
        $notify_who = trim($_POST['notify_who'] ?? 'admin');
        $label = trim($_POST['label'] ?? '');
        $custom_email = trim($_POST['custom_email'] ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;

        if ($days_before < 0) {
            $error_msg = 'Le nombre de jours doit être positif ou zéro.';
        } elseif (empty($label)) {
            $error_msg = 'Le libellé de la règle est obligatoire.';
        } else {
            if ($notify_who === 'custom' && !empty($custom_email)) {
                if (!filter_var($custom_email, FILTER_VALIDATE_EMAIL)) {
                    $error_msg = 'L\'adresse email personnalisée est invalide.';
                } else {
                    $notify_who = $custom_email;
                }
            }

            if (empty($error_msg)) {
                try {
                    $pdo->prepare("UPDATE alert_rules SET days_before=?, condition_type=?, notify_who=?, label=?, actif=? WHERE id=?")
                        ->execute([$days_before, $condition_type, $notify_who, $label, $actif, $rule_id]);
                    App::audit()->log('alert_rule_update', 'rule:' . $rule_id, 'Règle d\'alerte modifiée : ' . $label);
                    $success_msg = 'Règle d\'alerte modifiée avec succès.';
                } catch (Exception $e) {
                    $error_msg = 'Erreur lors de la modification : ' . $e->getMessage();
                }
            }
        }
    }
    elseif ($action === 'delete_rule') {
        $rule_id = trim($_POST['rule_id'] ?? '');
        try {
            $pdo->prepare("DELETE FROM alert_rules WHERE id = ?")->execute([$rule_id]);
            App::audit()->log('alert_rule_delete', 'rule:' . $rule_id, 'Règle d\'alerte supprimée');
            $success_msg = 'Règle d\'alerte supprimée.';
        } catch (Exception $e) {
            $error_msg = 'Erreur lors de la suppression : ' . $e->getMessage();
        }
    }
    elseif ($action === 'update_deadline_field') {
        $form_id = trim($_POST['form_id'] ?? '');
        $deadline_field = trim($_POST['deadline_field'] ?? '');

        if (!empty($form_id)) {
            try {
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")
                    ->execute([$deadline_field, $form_id]);
                App::audit()->log('deadline_field_update', 'form:' . $form_id, 'Champ deadline mis à jour : ' . ($deadline_field ?: '(aucun)'));
                $success_msg = 'Champ date limite mis à jour pour le formulaire.';
            } catch (Exception $e) {
                $error_msg = 'Erreur : ' . $e->getMessage();
            }
        }
    }
    elseif ($action === 'delete_alert_log') {
        // A-14 (W4-3) : durée de rétention configurable via setting
        $retention_days = (int)\App\Core\App::settings()->get('alert_log_retention_days', '90');
        // Purger les anciens logs d'alerte (> $retention_days jours)
        try {
            $pdo->prepare("DELETE FROM alert_log WHERE sent_at < datetime('now', ?)")->execute(["-{$retention_days} days"]);
            App::audit()->log('alert_log_purge', 'alert_log', "Purge des logs d'alerte > {$retention_days} jours");
            $success_msg = "Anciens logs d'alerte purgés (plus de {$retention_days} jours).";
        } catch (Exception $e) {
            $error_msg = 'Erreur : ' . $e->getMessage();
        }
    }
}

// Regle en cours de modification (via GET param)
$edit_rule_id = trim($_GET['edit_rule'] ?? '');

// Recuperer les donnees
$forms = $pdo->query("SELECT id, slug, label, deadline_field FROM forms WHERE actif = 1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

$rules = $pdo->query("
    SELECT ar.*, f.label as form_label, f.slug as form_slug, f.deadline_field
    FROM alert_rules ar
    JOIN forms f ON f.id = ar.form_id
    ORDER BY f.label, ar.days_before DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Historique des alertes (50 dernieres)
$alert_logs = $pdo->query("
    SELECT al.*, f.label as form_label, ar.label as rule_label
    FROM alert_log al
    JOIN submissions s ON s.id = al.submission_id
    JOIN forms f ON f.id = s.form_id
    LEFT JOIN alert_rules ar ON ar.id = al.rule_id
    ORDER BY al.sent_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Derniere execution du script
$last_alert_check = \App\Core\App::settings()->get('last_alert_check', '');

// Champs de type date disponibles par formulaire
$date_fields_by_form = [];
foreach ($forms as $f) {
    $stmt = $pdo->prepare("SELECT field_name, label FROM form_fields WHERE form_id = ? AND field_type = 'date' ORDER BY ordre");
    $stmt->execute([$f['id']]);
    $date_fields_by_form[$f['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Libelle lisible pour notify_who
function notify_who_label(string $val): string {
    $map = [
        'admin' => 'Administrateurs',
        'submitter' => 'Agent (demandeur)',
        'validators' => 'Validateurs en cours',
        'admin+submitter' => 'Admins + Agent',
        'admin+validators' => 'Admins + Validateurs',
    ];
    if (isset($map[$val])) return $map[$val];
    if (filter_var($val, FILTER_VALIDATE_EMAIL)) return 'Courriel : ' . $val;
    return $val;
}
?>
<?php
$page_css = '';
$nav_extra = [
    'alerts'    => ['href' => 'index.php?p=admin_alerts', 'label' => 'Alertes', 'icon' => '🔔'],
    'monitoring'=> ['href' => 'index.php?p=monitoring',   'label' => 'Surveillance', 'icon' => '🖥'],
    'stats'     => ['href' => 'index.php?p=stats',         'label' => 'Statistiques', 'icon' => '📈'],
    'rgpd'      => ['href' => 'index.php?p=rgpd',          'label' => 'RGPD', 'icon' => '🔐'],
];
ob_start();
?>
  <h1><span aria-hidden="true">🔔</span> Alertes paramétrables</h1>

  <?= render_messages(['success'=>$success_msg, 'error'=>$error_msg]) ?>

  <!-- Statut du script d'alerte -->
  <div class="card">
    <h2>Script de vérification des alertes</h2>
    <?php if ($last_alert_check): ?>
      <?php
        $check_ts = strtotime($last_alert_check);
        $check_age = ($check_ts !== false) ? (time() - $check_ts) : 999999;
        $check_ok = $check_age < 86400;
      ?>
      <div class="script-status">
        <span class="health-dot <?= $check_ok ? 'health-ok' : 'health-warn' ?>"></span>
        Dernière exécution : <strong><?= h(date('d/m/Y à H:i', $check_ts !== false ? $check_ts : 0)) ?></strong>
        <?php if (!$check_ok): ?>
          <span class="badge badge-warn" style="margin-left:.5rem;"><span aria-hidden="true">⚠</span> Dernière exécution il y a plus de 24h</span>
        <?php else: ?>
          <span class="badge badge-ok" style="margin-left:.5rem;"><span aria-hidden="true">✓</span> Script actif</span>
        <?php endif; ?>
      </div>
      <p style="font-size:.85rem;color:#595959;">
        Script : <strong>alert_check.php</strong> — À planifier via Task Scheduler (ex: toutes les 6h).
        <br>Le script vérifie les soumissions en cours et envoie des alertes si les étapes ne sont pas complétées à l'approche de la date cible.
      </p>
    <?php else: ?>
      <div class="script-status">
        <span class="health-dot health-unknown"></span>
        <span class="badge badge-info">Jamais exécuté</span>
        Le script <strong>alert_check.php</strong> n'a jamais été lancé.
      </div>
      <p style="font-size:.85rem;color:#595959;">
        Planifiez-le via Windows Task Scheduler (ex: toutes les 6h) :<br>
        <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.8rem;">php <?= h(realpath(dirname(__DIR__) . '/alert_check.php') ?: '') ?></code>
      </p>
    <?php endif; ?>
  </div>

  <!-- Configuration du champ date limite par formulaire -->
  <div class="card">
    <h2><span aria-hidden="true">📋</span> Champ date limite par formulaire</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Pour chaque formulaire, indiquez quel champ de type <strong>date</strong> représente la date cible (deadline).
      C'est cette date qui sera utilisée pour déclencher les alertes.
    </p>

    <?php foreach ($forms as $f):
      $date_fields = $date_fields_by_form[$f['id']] ?? [];
    ?>
      <div class="deadline-config">
        <form method="POST" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
          <?= App::security()->csrfField() ?>
          <input type="hidden" name="action" value="update_deadline_field">
          <input type="hidden" name="form_id" value="<?= h($f['id']) ?>">
          <strong style="min-width:150px;"><?= h($f['label']) ?></strong>
          <select name="deadline_field" style="flex:1;">
            <option value="">— Aucun champ date —</option>
            <?php foreach ($date_fields as $df): ?>
              <option value="<?= h($df['field_name']) ?>" <?= ($f['deadline_field'] ?? '') === $df['field_name'] ? 'selected' : '' ?>>
                <?= h($df['label']) ?> (<?= h($df['field_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .8rem;">Enregistrer</button>
        </form>
        <?php if (!empty($f['deadline_field'])): ?>
          <p style="font-size:.8rem;color:#1a6b3c;margin-top:.5rem;">
            <span aria-hidden="true">✓</span> Champ date limite : <strong><?= h($f['deadline_field']) ?></strong>
          </p>
        <?php else: ?>
          <p style="font-size:.8rem;color:#c0392b;margin-top:.5rem;">
            <span aria-hidden="true">⚠</span> Aucun champ date limite configuré — les alertes ne se déclencheront pas pour ce formulaire.
          </p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Regles d'alerte existantes -->
  <div class="card">
    <h2>📏 Règles d'alerte (<?= count($rules) ?>)</h2>

    <?php if (empty($rules)): ?>
      <p class="empty-state">Aucune règle d'alerte configurée. Ajoutez-en une ci-dessous.</p>
    <?php else: ?>
      <?php foreach ($rules as $r):
        $is_inactive = empty($r['actif']);
        $days_cls = $r['days_before'] <= 2 ? 'urgent' : ($r['days_before'] == 0 ? 'passed' : '');
      ?>
        <div class="rule-card <?= $is_inactive ? 'inactive' : '' ?>">
          <div class="rule-header">
            <h3>
              <span style="font-size:.8rem;color:#595959;"><?= h($r['form_label']) ?></span> —
              <?= h($r['label']) ?>
            </h3>
            <div class="rule-actions">
              <a href="index.php?p=admin_alerts&edit_rule=<?= urlencode($r['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;text-decoration:none;">Modifier</a>
              <form method="POST" style="display:inline;">
                <?= App::security()->csrfField() ?>
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="rule_id" value="<?= h($r['id']) ?>">
                <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.3rem .6rem;" onclick="return confirm('Supprimer cette règle d\\'alerte ?');">Supprimer</button>
              </form>
            </div>
          </div>
          <div class="rule-meta">
            <span class="days-badge <?= $days_cls ?>"><?= $r['days_before'] == 0 ? 'Jour J' : 'J-' . (int)$r['days_before'] ?></span>
            <span class="cond-badge"><?= $r['condition_type'] === 'steps_incomplete' ? 'Étapes incomplètes' : h($r['condition_type']) ?></span>
            <span class="notify-badge"><span aria-hidden="true">📧</span> <?= h(notify_who_label($r['notify_who'])) ?></span>
            <?php if ($is_inactive): ?>
              <span class="badge badge-err">Inactive</span>
            <?php else: ?>
              <span class="badge badge-ok">Active</span>
            <?php endif; ?>
          </div>

          <!-- Formulaire de modification -->
          <?php if ($edit_rule_id === $r['id']): ?>
          <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eee;">
            <form method="POST">
              <?= App::security()->csrfField() ?>
              <input type="hidden" name="action" value="update_rule">
              <input type="hidden" name="rule_id" value="<?= h($r['id']) ?>">
              <div class="grid-2">
                <div class="field">
                  <label>Libellé</label>
                  <input type="text" name="label" value="<?= h($r['label']) ?>" required>
                </div>
                <div class="field">
                  <label>Jours avant la date cible</label>
                  <input type="number" name="days_before" value="<?= (int)$r['days_before'] ?>" min="0" required>
                  <span class="hint">0 = alerte le jour même</span>
                </div>
                <div class="field">
                  <label>Condition</label>
                  <select name="condition_type">
                    <option value="steps_incomplete" <?= $r['condition_type'] === 'steps_incomplete' ? 'selected' : '' ?>>Étapes incomplètes</option>
                  </select>
                  <span class="hint">D'autres conditions pourront être ajoutées ultérieurement</span>
                </div>
                <div class="field">
                  <label>Notifier</label>
                  <select name="notify_who">
                    <option value="admin" <?= $r['notify_who'] === 'admin' ? 'selected' : '' ?>>Administrateurs</option>
                    <option value="submitter" <?= $r['notify_who'] === 'submitter' ? 'selected' : '' ?>>Agent (demandeur)</option>
                    <option value="validators" <?= $r['notify_who'] === 'validators' ? 'selected' : '' ?>>Validateurs en cours</option>
                    <option value="admin+submitter" <?= $r['notify_who'] === 'admin+submitter' ? 'selected' : '' ?>>Admins + Agent</option>
                    <option value="admin+validators" <?= $r['notify_who'] === 'admin+validators' ? 'selected' : '' ?>>Admins + Validateurs</option>
                    <option value="custom" <?= !in_array($r['notify_who'], ['admin','submitter','validators','admin+submitter','admin+validators']) ? 'selected' : '' ?>>Courriel personnalisé</option>
                  </select>
                </div>
                  <div class="field custom-email-field">
                    <label>Courriel personnalisé <span class="hint">(si « Courriel personnalisé » sélectionné ci-dessus)</span></label>
                    <input type="email" name="custom_email" value="<?= filter_var($r['notify_who'], FILTER_VALIDATE_EMAIL) ? h($r['notify_who']) : '' ?>" placeholder="courriel@exemple.fr">
                  </div>
                <div class="field">
                  <label class="checkbox-label">
                    <input type="checkbox" name="actif" value="1" <?= $r['actif'] ? 'checked' : '' ?>>
                    Règle active
                  </label>
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
                <a href="index.php?p=admin_alerts" class="btn btn-secondary">Annuler</a>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Ajouter une regle -->
  <div class="card">
    <h2>➕ Ajouter une règle d'alerte</h2>
    <form method="POST">
      <?= App::security()->csrfField() ?>
      <input type="hidden" name="action" value="add_rule">
      <div class="grid-2">
        <div class="field">
          <label>Formulaire</label>
          <select name="form_id" required>
            <option value="">— Sélectionner —</option>
            <?php foreach ($forms as $f): ?>
              <option value="<?= h($f['id']) ?>"><?= h($f['label']) ?><?= empty($f['deadline_field']) ? ' (⚠ pas de champ date)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Jours avant la date cible</label>
          <input type="number" name="days_before" value="5" min="0" required>
          <span class="hint">0 = alerte le jour même de la date cible</span>
        </div>
        <div class="field">
          <label>Libellé de la règle</label>
          <input type="text" name="label" placeholder="Ex: Alerte J-5 : étapes non complétées" required>
        </div>
        <div class="field">
          <label>Condition</label>
          <select name="condition_type">
            <option value="steps_incomplete">Étapes incomplètes</option>
          </select>
        </div>
        <div class="field">
          <label>Notifier</label>
          <select name="notify_who">
            <option value="admin">Administrateurs</option>
            <option value="submitter">Agent (demandeur)</option>
            <option value="validators">Validateurs en cours</option>
            <option value="admin+submitter">Admins + Agent</option>
            <option value="admin+validators">Admins + Validateurs</option>
            <option value="custom">Courriel personnalisé</option>
          </select>
        </div>
        <div class="field custom-email-field">
          <label>Courriel personnalisé <span class="hint">(si « Courriel personnalisé » sélectionné ci-dessus)</span></label>
          <input type="email" name="custom_email" placeholder="courriel@exemple.fr">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ajouter la règle</button>
        <a href="index.php?p=admin_alerts" class="btn btn-secondary">Annuler</a>
      </div>
    </form>
  </div>

  <!-- Historique des alertes envoyees -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h2 style="margin:0;border:none;padding:0;">📬 Historique des alertes envoyées</h2>
      <?php $purge_days = (int)\App\Core\App::settings()->get('alert_log_retention_days', '90'); ?>
      <form method="POST">
        <?= App::security()->csrfField() ?>
        <input type="hidden" name="action" value="delete_alert_log">
        <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .6rem;" onclick="return confirm('Purger les logs d\\'alerte de plus de <?= $purge_days ?> jours ?');"><span aria-hidden="true">🗑</span> Purger &gt; <?= $purge_days ?>j</button>
      </form>
    </div>

    <?php if (empty($alert_logs)): ?>
      <p class="empty-state">Aucune alerte envoyée pour le moment.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>
        </thead>
        <tbody>
        <?php foreach ($alert_logs as $al): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem;"><?= h(date('d/m/Y H:i', strtotime($al['sent_at']))) ?></td>
            <td><span class="badge badge-info"><?= h($al['rule_label'] ?? 'Règle supprimée') ?></span></td>
            <td><?= h($al['form_label']) ?></td>
            <td style="font-size:.8rem;"><?= h($al['message']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Alertes', 'alerts', $page_css, $content, ['nav_extra' => $nav_extra]);
