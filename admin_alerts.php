<?php
// admin_alerts.php — Configuration des regles d'alerte parametrables
require_once __DIR__ . '/helpers.php';

if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$pdo = get_pdo();
$success_msg = '';
$error_msg = '';

// Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
    }

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
                    $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (generate_uuid(), ?, ?, ?, ?, ?, 1)")
                        ->execute([$form_id, $days_before, $condition_type, $notify_who, $label]);
                    app_log('alert_rule_create', 'form:' . $form_id, 'Règle d\'alerte créée : ' . $label);
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
                    app_log('alert_rule_update', 'rule:' . $rule_id, 'Règle d\'alerte modifiée : ' . $label);
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
            app_log('alert_rule_delete', 'rule:' . $rule_id, 'Règle d\'alerte supprimée');
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
                app_log('deadline_field_update', 'form:' . $form_id, 'Champ deadline mis à jour : ' . ($deadline_field ?: '(aucun)'));
                $success_msg = 'Champ date limite mis à jour pour le formulaire.';
            } catch (Exception $e) {
                $error_msg = 'Erreur : ' . $e->getMessage();
            }
        }
    }
    elseif ($action === 'delete_alert_log') {
        // Purger les anciens logs d'alerte (> 90 jours)
        try {
            $pdo->exec("DELETE FROM alert_log WHERE sent_at < datetime('now', '-90 days')");
            app_log('alert_log_purge', 'alert_log', 'Purge des logs d\'alerte > 90 jours');
            $success_msg = 'Anciens logs d\'alerte purgés (plus de 90 jours).';
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
$last_alert_check = get_setting('last_alert_check', '');

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
    if (filter_var($val, FILTER_VALIDATE_EMAIL)) return 'Email : ' . $val;
    return $val;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Alertes — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1100px; }
    .rule-card { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--r-sm); padding: 1.25rem; margin-bottom: 1rem; }
    .rule-card.inactive { opacity: .6; }
    .rule-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; }
    .rule-header h3 { margin: 0; font-size: 1rem; }
    .rule-meta { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
    .rule-actions { display: flex; gap: .5rem; }
    .days-badge { font-size: .85rem; font-weight: bold; background: var(--c-warning-50); color: var(--c-warning-dark); padding: .25rem .75rem; border-radius: var(--r-sm); }
    .days-badge.urgent { background: #fde8e8; color: #c0392b; }
    .days-badge.passed { background: #c0392b; color: #fff; }
    .notify-badge { font-size: .8rem; background: var(--c-info-50); color: var(--c-info); padding: .2rem .6rem; border-radius: var(--r-sm); }
    .cond-badge { font-size: .8rem; background: var(--c-primary-50); color: var(--c-primary-dark); padding: .2rem .6rem; border-radius: var(--r-sm); }
    .deadline-config { background: var(--c-primary-50); border: 1px solid var(--c-border); border-radius: var(--r-sm); padding: 1rem 1.25rem; margin-bottom: 1.5rem; }
    .deadline-config select { max-width: 300px; }
    .script-status { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('alerts', [
    'alerts'    => ['href' => 'admin_alerts.php', 'label' => 'Alertes', 'icon' => '🔔'],
    'monitoring'=> ['href' => 'monitoring.php',   'label' => 'Monitoring', 'icon' => '🖥'],
    'stats'     => ['href' => 'stats.php',         'label' => 'Statistiques', 'icon' => '📈'],
    'rgpd'      => ['href' => 'rgpd.php',          'label' => 'RGPD', 'icon' => '🔐'],
]) ?>
<?= render_breadcrumb([['Accueil', 'index.php'], ['Alertes']]) ?>
<main class="container" id="main-content">
  <h1><span aria-hidden="true">🔔</span> Alertes paramétrables</h1>

  <?php if ($success_msg): ?>
    <div class="msg-success"><?= h($success_msg) ?></div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
    <div class="msg-error"><?= h($error_msg) ?></div>
  <?php endif; ?>

  <!-- Statut du script d'alerte -->
  <div class="card">
    <h2>Script de vérification des alertes</h2>
    <?php if ($last_alert_check): ?>
      <?php
        $check_age = time() - strtotime($last_alert_check);
        $check_ok = $check_age < 86400;
      ?>
      <div class="script-status">
        <span class="health-dot <?= $check_ok ? 'health-ok' : 'health-warn' ?>"></span>
        Dernière exécution : <strong><?= h(date('d/m/Y à H:i', strtotime($last_alert_check))) ?></strong>
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
        <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.8rem;">php <?= h(realpath(__DIR__ . '/alert_check.php')) ?></code>
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
          <?= csrf_field() ?>
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
              <a href="?edit_rule=<?= urlencode($r['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;text-decoration:none;">Modifier</a>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="rule_id" value="<?= h($r['id']) ?>">
                <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.3rem .6rem;">Supprimer</button>
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
          <?php if (($edit_rule_id ?? '') === $r['id']): ?>
          <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eee;">
            <form method="POST">
              <?= csrf_field() ?>
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
                    <option value="custom" <?= !in_array($r['notify_who'], ['admin','submitter','validators','admin+submitter','admin+validators']) ? 'selected' : '' ?>>Email personnalisé</option>
                  </select>
                </div>
                  <div class="field custom-email-field">
                    <label>Email personnalisé <span class="hint">(si "Email personnalisé" sélectionné ci-dessus)</span></label>
                    <input type="email" name="custom_email" value="<?= filter_var($r['notify_who'], FILTER_VALIDATE_EMAIL) ? h($r['notify_who']) : '' ?>" placeholder="email@exemple.fr">
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
                <a href="admin_alerts.php" class="btn btn-secondary">Annuler</a>
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
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_rule">
      <div class="grid-2">
        <div class="field">
          <label>Formulaire</label>
          <select name="form_id" required>
            <option value="">— Sélectionner —</option>
            <?php foreach ($forms as $f): ?>
              <option value="<?= h($f['id']) ?>"><?= h($f['label']) ?><?= empty($f['deadline_field']) ? ' (<span aria-hidden="true">⚠</span> pas de champ date)' : '' ?></option>
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
            <option value="custom">Email personnalisé</option>
          </select>
        </div>
        <div class="field custom-email-field">
          <label>Email personnalisé <span class="hint">(si "Email personnalisé" sélectionné ci-dessus)</span></label>
          <input type="email" name="custom_email" placeholder="email@exemple.fr">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ajouter la règle</button>
        <a href="admin_alerts.php" class="btn btn-secondary">Annuler</a>
      </div>
    </form>
  </div>

  <!-- Historique des alertes envoyees -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h2 style="margin:0;border:none;padding:0;">📬 Historique des alertes envoyées</h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_alert_log">
        <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .6rem;"><span aria-hidden="true">🗑</span> Purger > 90j</button>
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

</main>
<?= render_footer() ?>
</body>
</html>
