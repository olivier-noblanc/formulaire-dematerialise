<?php
declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Rendu de la page Alertes paramétrables (admin).
 */
final class AdminAlertsRenderer
{
    /**
     * @param string $successMsg
     * @param string $errorMsg
     * @param array  $forms           formulaires actifs (findActiveList)
     * @param array  $rules           règles avec form_label (getAllWithForm)
     * @param array  $alertLogs       logs avec form_label (getLogsWithForm)
     * @param string $lastAlertCheck  date dernière exécution du script
     * @param string $editRuleId      id de la règle en cours d'édition (GET)
     * @param array  $dateFieldsByForm  champs date par formulaire, clé = form id
     */
    public static function content(
        string $successMsg,
        string $errorMsg,
        array $forms,
        array $rules,
        array $alertLogs,
        string $lastAlertCheck,
        string $editRuleId,
        array $dateFieldsByForm,
    ): string {
        $h = static fn (string $v): string => \App\Core\App::html()->escape($v);

        $purgeDays = (int) App::settings()->get('alert_log_retention_days', '90');

        $html = '';

        $html .= '  <h1><span aria-hidden="true">🔔</span> Alertes paramétrables</h1>' . "\n";
        $html .= "\n";
        $html .= '  ' . (new \App\Render\ErrorRenderer())->messages(['success' => $successMsg, 'error' => $errorMsg]) . "\n";
        $html .= "\n";

        // ── Script status ──
        $html .= '  <div class="card">' . "\n";
        $html .= '    <h2>Script de vérification des alertes</h2>' . "\n";

        if ($lastAlertCheck) {
            $checkTs = strtotime($lastAlertCheck);
            $checkAge = ($checkTs !== false) ? (time() - $checkTs) : 999999;
            $checkOk = $checkAge < 86400;

            $html .= '      <div class="script-status">' . "\n";
            $html .= '        <span class="health-dot ' . ($checkOk ? 'health-ok' : 'health-warn') . '"></span>' . "\n";
            $html .= '        Dernière exécution : <strong>' . $h(date('d/m/Y à H:i', $checkTs !== false ? $checkTs : 0)) . '</strong>' . "\n";
            if (!$checkOk) {
                $html .= '          <span class="badge badge-warn" style="margin-left:.5rem;"><span aria-hidden="true">⚠</span> Dernière exécution il y a plus de 24h</span>' . "\n";
            } else {
                $html .= '          <span class="badge badge-ok" style="margin-left:.5rem;"><span aria-hidden="true">✓</span> Script actif</span>' . "\n";
            }
            $html .= '      </div>' . "\n";
            $html .= '      <p style="font-size:.85rem;color:#595959;">' . "\n";
            $html .= '        Script : <strong>alert_check.php</strong> — À planifier via Task Scheduler (ex: toutes les 6h).' . "\n";
            $html .= '        <br>Le script vérifie les soumissions en cours et envoie des alertes si les étapes ne sont pas complétées à l\'approche de la date cible.' . "\n";
            $html .= '      </p>' . "\n";
        } else {
            $html .= '      <div class="script-status">' . "\n";
            $html .= '        <span class="health-dot health-unknown"></span>' . "\n";
            $html .= '        <span class="badge badge-info">Jamais exécuté</span>' . "\n";
            $html .= '        Le script <strong>alert_check.php</strong> n\'a jamais été lancé.' . "\n";
            $html .= '      </div>' . "\n";
            $html .= '      <p style="font-size:.85rem;color:#595959;">' . "\n";
            $html .= '        Planifiez-le via Windows Task Scheduler (ex: toutes les 6h) :<br>' . "\n";
            $html .= '        <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.8rem;">php ' . $h(realpath(dirname(__DIR__, 2) . '/alert_check.php') ?: '') . '</code>' . "\n";
            $html .= '      </p>' . "\n";
        }

        $html .= '  </div>' . "\n";
        $html .= "\n";

        // ── Deadline config ──
        $html .= '  <div class="card">' . "\n";
        $html .= '    <h2><span aria-hidden="true">📋</span> Champ date limite par formulaire</h2>' . "\n";
        $html .= '    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">' . "\n";
        $html .= '      Pour chaque formulaire, indiquez quel champ de type <strong>date</strong> représente la date cible (deadline).' . "\n";
        $html .= '      C\'est cette date qui sera utilisée pour déclencher les alertes.' . "\n";
        $html .= '    </p>' . "\n";
        $html .= "\n";

        foreach ($forms as $f) {
            $dateFields = $dateFieldsByForm[$f['id']] ?? [];

            $html .= '      <div class="deadline-config">' . "\n";
            $html .= '        <form method="POST" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">' . "\n";
            $html .= '          ' . App::security()->csrfField() . "\n";
            $html .= '          <input type="hidden" name="action" value="update_deadline_field">' . "\n";
            $html .= '          <input type="hidden" name="form_id" value="' . $h($f['id']) . '">' . "\n";
            $html .= '          <strong style="min-width:150px;">' . $h($f['label']) . '</strong>' . "\n";
            $html .= '          <select name="deadline_field" style="flex:1;">' . "\n";
            $html .= '            <option value="">— Aucun champ date —</option>' . "\n";
            foreach ($dateFields as $df) {
                $selected = (($f['deadline_field'] ?? '') === $df['field_name']) ? ' selected' : '';
                $html .= '              <option value="' . $h($df['field_name']) . '"' . $selected . '>' . "\n";
                $html .= '                ' . $h($df['label']) . ' (' . $h($df['field_name']) . ')' . "\n";
                $html .= '              </option>' . "\n";
            }
            $html .= '          </select>' . "\n";
            $html .= '          <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.4rem .8rem;">Enregistrer</button>' . "\n";
            $html .= '        </form>' . "\n";

            if (!empty($f['deadline_field'])) {
                $html .= '          <p style="font-size:.8rem;color:#1a6b3c;margin-top:.5rem;">' . "\n";
                $html .= '            <span aria-hidden="true">✓</span> Champ date limite : <strong>' . $h($f['deadline_field']) . '</strong>' . "\n";
                $html .= '          </p>' . "\n";
            } else {
                $html .= '          <p style="font-size:.8rem;color:#c0392b;margin-top:.5rem;">' . "\n";
                $html .= '            <span aria-hidden="true">⚠</span> Aucun champ date limite configuré — les alertes ne se déclencheront pas pour ce formulaire.' . "\n";
                $html .= '          </p>' . "\n";
            }

            $html .= '      </div>' . "\n";
        }

        $html .= '  </div>' . "\n";
        $html .= "\n";

        // ── Rules list ──
        $html .= '  <div class="card">' . "\n";
        $html .= '    <h2>📏 Règles d\'alerte (' . count($rules) . ')</h2>' . "\n";
        $html .= "\n";

        if (empty($rules)) {
            $html .= '      <p class="empty-state">Aucune règle d\'alerte configurée. Ajoutez-en une ci-dessous.</p>' . "\n";
        } else {
            foreach ($rules as $r) {
                $isInactive = empty($r['actif']);
                $daysCls = $r['days_before'] <= 2 ? 'urgent' : ($r['days_before'] == 0 ? 'passed' : '');

                $html .= '        <div class="rule-card ' . ($isInactive ? 'inactive' : '') . '">' . "\n";
                $html .= '          <div class="rule-header">' . "\n";
                $html .= '            <h3>' . "\n";
                $html .= '              <span style="font-size:.8rem;color:#595959;">' . $h($r['form_label']) . '</span> —' . "\n";
                $html .= '              ' . $h($r['label']) . "\n";
                $html .= '            </h3>' . "\n";
                $html .= '            <div class="rule-actions">' . "\n";
                $html .= '              <a href="index.php?p=admin_alerts&edit_rule=' . urlencode($r['id']) . '" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;text-decoration:none;">Modifier</a>' . "\n";
                $html .= '              <form method="POST" style="display:inline;">' . "\n";
                $html .= '                ' . App::security()->csrfField() . "\n";
                $html .= '                <input type="hidden" name="action" value="delete_rule">' . "\n";
                $html .= '                <input type="hidden" name="rule_id" value="' . $h($r['id']) . '">' . "\n";
                $html .= '                <button type="submit" class="btn btn-danger" style="font-size:.75rem;padding:.3rem .6rem;" onclick="return confirm(\'Supprimer cette règle d\\\\\'alerte ?\');">Supprimer</button>' . "\n";
                $html .= '              </form>' . "\n";
                $html .= '            </div>' . "\n";
                $html .= '          </div>' . "\n";
                $html .= '          <div class="rule-meta">' . "\n";
                $html .= '            <span class="days-badge ' . $daysCls . '">' . ($r['days_before'] == 0 ? 'Jour J' : 'J-' . (int) $r['days_before']) . '</span>' . "\n";
                $html .= '            <span class="cond-badge">' . ($r['condition_type'] === 'steps_incomplete' ? 'Étapes incomplètes' : $h($r['condition_type'])) . '</span>' . "\n";
                $html .= '            <span class="notify-badge"><span aria-hidden="true">📧</span> ' . $h(self::notifyWhoLabel($r['notify_who'])) . '</span>' . "\n";
                if ($isInactive) {
                    $html .= '              <span class="badge badge-err">Inactive</span>' . "\n";
                } else {
                    $html .= '              <span class="badge badge-ok">Active</span>' . "\n";
                }
                $html .= '          </div>' . "\n";

                if ($editRuleId === $r['id']) {
                    $html .= '          <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eee;">' . "\n";
                    $html .= '            <form method="POST">' . "\n";
                    $html .= '              ' . App::security()->csrfField() . "\n";
                    $html .= '              <input type="hidden" name="action" value="update_rule">' . "\n";
                    $html .= '              <input type="hidden" name="rule_id" value="' . $h($r['id']) . '">' . "\n";
                    $html .= '              <div class="grid-2">' . "\n";
                    $html .= '                <div class="field">' . "\n";
                    $html .= '                  <label>Libellé</label>' . "\n";
                    $html .= '                  <input type="text" name="label" value="' . $h($r['label']) . '" required>' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '                <div class="field">' . "\n";
                    $html .= '                  <label>Jours avant la date cible</label>' . "\n";
                    $html .= '                  <input type="number" name="days_before" value="' . (int) $r['days_before'] . '" min="0" required>' . "\n";
                    $html .= '                  <span class="hint">0 = alerte le jour même</span>' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '                <div class="field">' . "\n";
                    $html .= '                  <label>Condition</label>' . "\n";
                    $html .= '                  <select name="condition_type">' . "\n";
                    $html .= '                    <option value="steps_incomplete"' . ($r['condition_type'] === 'steps_incomplete' ? ' selected' : '') . '>Étapes incomplètes</option>' . "\n";
                    $html .= '                  </select>' . "\n";
                    $html .= '                  <span class="hint">D\'autres conditions pourront être ajoutées ultérieurement</span>' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '                <div class="field">' . "\n";
                    $html .= '                  <label>Notifier</label>' . "\n";
                    $html .= '                  <select name="notify_who">' . "\n";
                    $html .= '                    <option value="admin"' . ($r['notify_who'] === 'admin' ? ' selected' : '') . '>Administrateurs</option>' . "\n";
                    $html .= '                    <option value="submitter"' . ($r['notify_who'] === 'submitter' ? ' selected' : '') . '>Agent (demandeur)</option>' . "\n";
                    $html .= '                    <option value="validators"' . ($r['notify_who'] === 'validators' ? ' selected' : '') . '>Validateurs en cours</option>' . "\n";
                    $html .= '                    <option value="admin+submitter"' . ($r['notify_who'] === 'admin+submitter' ? ' selected' : '') . '>Admins + Agent</option>' . "\n";
                    $html .= '                    <option value="admin+validators"' . ($r['notify_who'] === 'admin+validators' ? ' selected' : '') . '>Admins + Validateurs</option>' . "\n";
                    $html .= '                    <option value="custom"' . (!in_array($r['notify_who'], ['admin', 'submitter', 'validators', 'admin+submitter', 'admin+validators']) ? ' selected' : '') . '>Courriel personnalisé</option>' . "\n";
                    $html .= '                  </select>' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '                <div class="field custom-email-field">' . "\n";
                    $html .= '                  <label>Courriel personnalisé <span class="hint">(si « Courriel personnalisé » sélectionné ci-dessus)</span></label>' . "\n";
                    $html .= '                  <input type="email" name="custom_email" value="' . (filter_var($r['notify_who'], FILTER_VALIDATE_EMAIL) ? $h($r['notify_who']) : '') . '" placeholder="courriel@exemple.fr">' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '                <div class="field">' . "\n";
                    $html .= '                  <label class="checkbox-label">' . "\n";
                    $html .= '                    <input type="checkbox" name="actif" value="1"' . ($r['actif'] ? ' checked' : '') . '>' . "\n";
                    $html .= '                    Règle active' . "\n";
                    $html .= '                  </label>' . "\n";
                    $html .= '                </div>' . "\n";
                    $html .= '              </div>' . "\n";
                    $html .= '              <div class="form-actions">' . "\n";
                    $html .= '                <button type="submit" class="btn btn-primary">Enregistrer</button>' . "\n";
                    $html .= '                <a href="index.php?p=admin_alerts" class="btn btn-secondary">Annuler</a>' . "\n";
                    $html .= '              </div>' . "\n";
                    $html .= '            </form>' . "\n";
                    $html .= '          </div>' . "\n";
                }

                $html .= '        </div>' . "\n";
            }
        }

        $html .= '  </div>' . "\n";
        $html .= "\n";

        // ── Add rule form ──
        $html .= '  <div class="card">' . "\n";
        $html .= '    <h2>➕ Ajouter une règle d\'alerte</h2>' . "\n";
        $html .= '    <form method="POST">' . "\n";
        $html .= '      ' . App::security()->csrfField() . "\n";
        $html .= '      <input type="hidden" name="action" value="add_rule">' . "\n";
        $html .= '      <div class="grid-2">' . "\n";
        $html .= '        <div class="field">' . "\n";
        $html .= '          <label>Formulaire</label>' . "\n";
        $html .= '          <select name="form_id" required>' . "\n";
        $html .= '            <option value="">— Sélectionner —</option>' . "\n";
        foreach ($forms as $f) {
            $html .= '            <option value="' . $h($f['id']) . '">' . $h($f['label']) . (empty($f['deadline_field']) ? ' (⚠ pas de champ date)' : '') . '</option>' . "\n";
        }
        $html .= '          </select>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="field">' . "\n";
        $html .= '          <label>Jours avant la date cible</label>' . "\n";
        $html .= '          <input type="number" name="days_before" value="5" min="0" required>' . "\n";
        $html .= '          <span class="hint">0 = alerte le jour même de la date cible</span>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="field">' . "\n";
        $html .= '          <label>Libellé de la règle</label>' . "\n";
        $html .= '          <input type="text" name="label" placeholder="Ex: Alerte J-5 : étapes non complétées" required>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="field">' . "\n";
        $html .= '          <label>Condition</label>' . "\n";
        $html .= '          <select name="condition_type">' . "\n";
        $html .= '            <option value="steps_incomplete">Étapes incomplètes</option>' . "\n";
        $html .= '          </select>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="field">' . "\n";
        $html .= '          <label>Notifier</label>' . "\n";
        $html .= '          <select name="notify_who">' . "\n";
        $html .= '            <option value="admin">Administrateurs</option>' . "\n";
        $html .= '            <option value="submitter">Agent (demandeur)</option>' . "\n";
        $html .= '            <option value="validators">Validateurs en cours</option>' . "\n";
        $html .= '            <option value="admin+submitter">Admins + Agent</option>' . "\n";
        $html .= '            <option value="admin+validators">Admins + Validateurs</option>' . "\n";
        $html .= '            <option value="custom">Courriel personnalisé</option>' . "\n";
        $html .= '          </select>' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '        <div class="field custom-email-field">' . "\n";
        $html .= '          <label>Courriel personnalisé <span class="hint">(si « Courriel personnalisé » sélectionné ci-dessus)</span></label>' . "\n";
        $html .= '          <input type="email" name="custom_email" placeholder="courriel@exemple.fr">' . "\n";
        $html .= '        </div>' . "\n";
        $html .= '      </div>' . "\n";
        $html .= '      <div class="form-actions">' . "\n";
        $html .= '        <button type="submit" class="btn btn-primary">Ajouter la règle</button>' . "\n";
        $html .= '        <a href="index.php?p=admin_alerts" class="btn btn-secondary">Annuler</a>' . "\n";
        $html .= '      </div>' . "\n";
        $html .= '    </form>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= "\n";

        // ── Alert logs ──
        $html .= '  <div class="card">' . "\n";
        $html .= '    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">' . "\n";
        $html .= '      <h2 style="margin:0;border:none;padding:0;">📬 Historique des alertes envoyées</h2>' . "\n";
        $html .= '      <form method="POST">' . "\n";
        $html .= '        ' . App::security()->csrfField() . "\n";
        $html .= '        <input type="hidden" name="action" value="delete_alert_log">' . "\n";
        $html .= '        <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .6rem;" onclick="return confirm(\'Purger les logs d\\\\\'alerte de plus de ' . $purgeDays . ' jours ?\');"><span aria-hidden="true">🗑</span> Purger &gt; ' . $purgeDays . 'j</button>' . "\n";
        $html .= '      </form>' . "\n";
        $html .= '    </div>' . "\n";
        $html .= "\n";

        if (empty($alertLogs)) {
            $html .= '      <p class="empty-state">Aucune alerte envoyée pour le moment.</p>' . "\n";
        } else {
            $html .= '      <table>' . "\n";
            $html .= '        <thead>' . "\n";
            $html .= '          <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>' . "\n";
            $html .= '        </thead>' . "\n";
            $html .= '        <tbody>' . "\n";
            foreach ($alertLogs as $al) {
                $html .= '          <tr>' . "\n";
                $html .= '            <td style="white-space:nowrap;font-size:.8rem;">' . $h(date('d/m/Y H:i', strtotime($al['sent_at']))) . '</td>' . "\n";
                $html .= '            <td><span class="badge badge-info">' . $h($al['rule_label'] ?? 'Règle supprimée') . '</span></td>' . "\n";
                $html .= '            <td>' . $h($al['form_label']) . '</td>' . "\n";
                $html .= '            <td style="font-size:.8rem;">' . $h($al['message']) . '</td>' . "\n";
                $html .= '          </tr>' . "\n";
            }
            $html .= '        </tbody>' . "\n";
            $html .= '      </table>' . "\n";
        }

        $html .= '  </div>' . "\n";

        return $html;
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
        if (isset($map[$val])) {
            return $map[$val];
        }
        if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
            return 'Courriel : ' . $val;
        }
        return $val;
    }
}
