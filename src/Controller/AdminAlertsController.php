<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Alertes paramétrables (admin).
 */
final class AdminAlertsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $pdo = $this->db->getPdo();
        $successMsg = '';
        $errorMsg = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'add_rule') {
                $formId = trim($_POST['form_id'] ?? '');
                $daysBefore = (int)($_POST['days_before'] ?? 5);
                $conditionType = trim($_POST['condition_type'] ?? 'steps_incomplete');
                $notifyWho = trim($_POST['notify_who'] ?? 'admin');
                $label = trim($_POST['label'] ?? '');
                $customEmail = trim($_POST['custom_email'] ?? '');

                if (empty($formId)) {
                    $errorMsg = 'Veuillez sélectionner un formulaire.';
                } elseif ($daysBefore < 0) {
                    $errorMsg = 'Le nombre de jours doit être positif ou zéro.';
                } elseif (empty($label)) {
                    $errorMsg = 'Le libellé de la règle est obligatoire.';
                } else {
                    if ($notifyWho === 'custom' && !empty($customEmail)) {
                        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
                            $errorMsg = 'L\'adresse email personnalisée est invalide.';
                        } else {
                            $notifyWho = $customEmail;
                        }
                    }

                    if (empty($errorMsg)) {
                        try {
                            $ruleId = $this->alertRepo->createRule([
                                'form_id'        => $formId,
                                'days_before'    => $daysBefore,
                                'condition_type' => $conditionType,
                                'notify_who'     => $notifyWho,
                                'label'          => $label,
                            ]);
                            App::audit()->log('alert_rule_create', 'form:' . $formId, 'Règle d\'alerte créée : ' . $label);
                            $successMsg = 'Règle d\'alerte créée avec succès.';
                        } catch (\Exception $e) {
                            error_log('alert_rule_create error: ' . $e->getMessage());
                            $errorMsg = 'Une erreur technique est survenue.';
                        }
                    }
                }
            }
            elseif ($action === 'update_rule') {
                $ruleId = trim($_POST['rule_id'] ?? '');
                $daysBefore = (int)($_POST['days_before'] ?? 5);
                $conditionType = trim($_POST['condition_type'] ?? 'steps_incomplete');
                $notifyWho = trim($_POST['notify_who'] ?? 'admin');
                $label = trim($_POST['label'] ?? '');
                $customEmail = trim($_POST['custom_email'] ?? '');
                $actif = isset($_POST['actif']) ? 1 : 0;

                if ($daysBefore < 0) {
                    $errorMsg = 'Le nombre de jours doit être positif ou zéro.';
                } elseif (empty($label)) {
                    $errorMsg = 'Le libellé de la règle est obligatoire.';
                } else {
                    if ($notifyWho === 'custom' && !empty($customEmail)) {
                        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
                            $errorMsg = 'L\'adresse email personnalisée est invalide.';
                        } else {
                            $notifyWho = $customEmail;
                        }
                    }

                    if (empty($errorMsg)) {
                        try {
                            $this->alertRepo->updateRule($ruleId, [
                                'days_before'    => $daysBefore,
                                'condition_type' => $conditionType,
                                'notify_who'     => $notifyWho,
                                'label'          => $label,
                                'actif'          => $actif,
                            ]);
                            App::audit()->log('alert_rule_update', 'rule:' . $ruleId, 'Règle d\'alerte modifiée : ' . $label);
                            $successMsg = 'Règle d\'alerte modifiée avec succès.';
                        } catch (\Exception $e) {
                            error_log('alert_rule_update error: ' . $e->getMessage());
                            $errorMsg = 'Une erreur technique est survenue.';
                        }
                    }
                }
            }
            elseif ($action === 'delete_rule') {
                $ruleId = trim($_POST['rule_id'] ?? '');
                try {
                    $this->alertRepo->deleteRule($ruleId);
                    App::audit()->log('alert_rule_delete', 'rule:' . $ruleId, 'Règle d\'alerte supprimée');
                    $successMsg = 'Règle d\'alerte supprimée.';
                } catch (\Exception $e) {
                    error_log('alert_rule_delete error: ' . $e->getMessage());
                    $errorMsg = 'Une erreur technique est survenue.';
                }
            }
            elseif ($action === 'update_deadline_field') {
                $formId = trim($_POST['form_id'] ?? '');
                $deadlineField = trim($_POST['deadline_field'] ?? '');

                if (!empty($formId)) {
                    try {
                        $this->formRepo->setDeadlineField($formId, $deadlineField);
                        App::audit()->log('deadline_field_update', 'form:' . $formId, 'Champ deadline mis à jour : ' . ($deadlineField ?: '(aucun)'));
                        $successMsg = 'Champ date limite mis à jour pour le formulaire.';
                    } catch (\Exception $e) {
                        error_log('deadline_field_update error: ' . $e->getMessage());
                        $errorMsg = 'Une erreur technique est survenue.';
                    }
                }
            }
            elseif ($action === 'delete_alert_log') {
                $retentionDays = (int)App::settings()->get('alert_log_retention_days', '90');
                try {
                    $this->alertRepo->purgeOldLogs($retentionDays);
                    App::audit()->log('alert_log_purge', 'alert_log', "Purge des logs d'alerte > {$retentionDays} jours");
                    $successMsg = "Anciens logs d'alerte purgés (plus de {$retentionDays} jours).";
                } catch (\Exception $e) {
                    error_log('alert_log_purge error: ' . $e->getMessage());
                    $errorMsg = 'Une erreur technique est survenue.';
                }
            }
        }

        $editRuleId = trim($_GET['edit_rule'] ?? '');

        $forms = $this->formRepo->findActiveList();

        $rules = $this->alertRepo->getAllWithForm();

        $alertLogs = $this->alertRepo->getLogsWithForm();

        $lastAlertCheck = App::settings()->get('last_alert_check', '');

        $dateFieldsByForm = [];
        foreach ($forms as $f) {
            $dateFieldsByForm[$f['id']] = $this->formRepo->getDateFields($f['id']);
        }

        $navExtra = [
            'alerts'    => ['href' => 'index.php?p=admin_alerts', 'label' => 'Alertes', 'icon' => '🔔'],
            'monitoring'=> ['href' => 'index.php?p=monitoring',   'label' => 'Surveillance', 'icon' => '🖥'],
            'stats'     => ['href' => 'index.php?p=stats',         'label' => 'Statistiques', 'icon' => '📈'],
            'rgpd'      => ['href' => 'index.php?p=rgpd',          'label' => 'RGPD', 'icon' => '🔐'],
        ];

        ob_start();
        ?>
  <h1><span aria-hidden="true">🔔</span> Alertes paramétrables</h1>

  <?= (new \App\Render\ErrorRenderer())->messages(['success'=>$successMsg, 'error'=>$errorMsg]) ?>

  <div class="card">
    <h2>Script de vérification des alertes</h2>
    <?php if ($lastAlertCheck): ?>
      <?php
        $checkTs = strtotime($lastAlertCheck);
        $checkAge = ($checkTs !== false) ? (time() - $checkTs) : 999999;
        $checkOk = $checkAge < 86400;
      ?>
      <div class="script-status">
        <span class="health-dot <?= $checkOk ? 'health-ok' : 'health-warn' ?>"></span>
        Dernière exécution : <strong><?= \App\Core\App::html()->escape(date('d/m/Y à H:i', $checkTs !== false ? $checkTs : 0)) ?></strong>
        <?php if (!$checkOk): ?>
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
        <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.8rem;">php <?= \App\Core\App::html()->escape(realpath(dirname(__DIR__, 2) . '/alert_check.php') ?: '') ?></code>
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><span aria-hidden="true">📋</span> Champ date limite par formulaire</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Pour chaque formulaire, indiquez quel champ de type <strong>date</strong> représente la date cible (deadline).
      C'est cette date qui sera utilisée pour déclencher les alertes.
    </p>

    <?php foreach ($forms as $f):
      $dateFields = $dateFieldsByForm[$f['id']] ?? [];
    ?>
      <div class="deadline-config">
        <form method="POST" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
          <?= $this->security->csrfField() ?>
          <input type="hidden" name="action" value="update_deadline_field">
          <input type="hidden" name="form_id" value="<?= \App\Core\App::html()->escape($f['id']) ?>">
          <strong style="min-width:150px;"><?= \App\Core\App::html()->escape($f['label']) ?></strong>
          <select name="deadline_field" style="flex:1;">
            <option value="">— Aucun champ date —</option>
            <?php foreach ($dateFields as $df): ?>
              <option value="<?= \App\Core\App::html()->escape($df['field_name']) ?>" <?= ($f['deadline_field'] ?? '') === $df['field_name'] ? 'selected' : '' ?>>
                <?= \App\Core\App::html()->escape($df['label']) ?> (<?= \App\Core\App::html()->escape($df['field_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .8rem;">Enregistrer</button>
        </form>
        <?php if (!empty($f['deadline_field'])): ?>
          <p style="font-size:.8rem;color:#1a6b3c;margin-top:.5rem;">
            <span aria-hidden="true">✓</span> Champ date limite : <strong><?= \App\Core\App::html()->escape($f['deadline_field']) ?></strong>
          </p>
        <?php else: ?>
          <p style="font-size:.8rem;color:#c0392b;margin-top:.5rem;">
            <span aria-hidden="true">⚠</span> Aucun champ date limite configuré — les alertes ne se déclencheront pas pour ce formulaire.
          </p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2>📏 Règles d'alerte (<?= count($rules) ?>)</h2>

    <?php if (empty($rules)): ?>
      <p class="empty-state">Aucune règle d'alerte configurée. Ajoutez-en une ci-dessous.</p>
    <?php else: ?>
      <?php foreach ($rules as $r):
        $isInactive = empty($r['actif']);
        $daysCls = $r['days_before'] <= 2 ? 'urgent' : ($r['days_before'] == 0 ? 'passed' : '');
      ?>
        <div class="rule-card <?= $isInactive ? 'inactive' : '' ?>">
          <div class="rule-header">
            <h3>
              <span style="font-size:.8rem;color:#595959;"><?= \App\Core\App::html()->escape($r['form_label']) ?></span> —
              <?= \App\Core\App::html()->escape($r['label']) ?>
            </h3>
            <div class="rule-actions">
              <a href="index.php?p=admin_alerts&edit_rule=<?= urlencode($r['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;text-decoration:none;">Modifier</a>
              <form method="POST" style="display:inline;">
                <?= $this->security->csrfField() ?>
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="rule_id" value="<?= \App\Core\App::html()->escape($r['id']) ?>">
                <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.3rem .6rem;" onclick="return confirm('Supprimer cette règle d\\'alerte ?');">Supprimer</button>
              </form>
            </div>
          </div>
          <div class="rule-meta">
            <span class="days-badge <?= $daysCls ?>"><?= $r['days_before'] == 0 ? 'Jour J' : 'J-' . (int)$r['days_before'] ?></span>
            <span class="cond-badge"><?= $r['condition_type'] === 'steps_incomplete' ? 'Étapes incomplètes' : \App\Core\App::html()->escape($r['condition_type']) ?></span>
            <span class="notify-badge"><span aria-hidden="true">📧</span> <?= \App\Core\App::html()->escape(self::notifyWhoLabel($r['notify_who'])) ?></span>
            <?php if ($isInactive): ?>
              <span class="badge badge-err">Inactive</span>
            <?php else: ?>
              <span class="badge badge-ok">Active</span>
            <?php endif; ?>
          </div>

          <?php if ($editRuleId === $r['id']): ?>
          <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eee;">
            <form method="POST">
              <?= $this->security->csrfField() ?>
              <input type="hidden" name="action" value="update_rule">
              <input type="hidden" name="rule_id" value="<?= \App\Core\App::html()->escape($r['id']) ?>">
              <div class="grid-2">
                <div class="field">
                  <label>Libellé</label>
                  <input type="text" name="label" value="<?= \App\Core\App::html()->escape($r['label']) ?>" required>
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
                  <input type="email" name="custom_email" value="<?= filter_var($r['notify_who'], FILTER_VALIDATE_EMAIL) ? \App\Core\App::html()->escape($r['notify_who']) : '' ?>" placeholder="courriel@exemple.fr">
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

  <div class="card">
    <h2>➕ Ajouter une règle d'alerte</h2>
    <form method="POST">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="add_rule">
      <div class="grid-2">
        <div class="field">
          <label>Formulaire</label>
          <select name="form_id" required>
            <option value="">— Sélectionner —</option>
            <?php foreach ($forms as $f): ?>
              <option value="<?= \App\Core\App::html()->escape($f['id']) ?>"><?= \App\Core\App::html()->escape($f['label']) ?><?= empty($f['deadline_field']) ? ' (⚠ pas de champ date)' : '' ?></option>
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

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h2 style="margin:0;border:none;padding:0;">📬 Historique des alertes envoyées</h2>
      <?php $purgeDays = (int)App::settings()->get('alert_log_retention_days', '90'); ?>
      <form method="POST">
        <?= $this->security->csrfField() ?>
        <input type="hidden" name="action" value="delete_alert_log">
        <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .6rem;" onclick="return confirm('Purger les logs d\\'alerte de plus de <?= $purgeDays ?> jours ?');"><span aria-hidden="true">🗑</span> Purger &gt; <?= $purgeDays ?>j</button>
      </form>
    </div>

    <?php if (empty($alertLogs)): ?>
      <p class="empty-state">Aucune alerte envoyée pour le moment.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>
        </thead>
        <tbody>
        <?php foreach ($alertLogs as $al): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem;"><?= \App\Core\App::html()->escape(date('d/m/Y H:i', strtotime($al['sent_at']))) ?></td>
            <td><span class="badge badge-info"><?= \App\Core\App::html()->escape($al['rule_label'] ?? 'Règle supprimée') ?></span></td>
            <td><?= \App\Core\App::html()->escape($al['form_label']) ?></td>
            <td style="font-size:.8rem;"><?= \App\Core\App::html()->escape($al['message']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Alertes', 'alerts', '', $content, ['nav_extra' => $navExtra]);
    }

    private static function notifyWhoLabel(string $val): string
    {
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
}
