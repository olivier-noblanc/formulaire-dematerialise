<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page RGPD (rgpd.php).
 *
 * Gère la conformité RGPD : mentions légales, export, suppression, purge.
 * Réservé aux administrateurs.
 */
final class RgpdController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $pdo = $this->db->getPdo();
        $successMsg = '';
        $errorMsg = '';
        $infoMsg = '';

        // Traitement des actions POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();

            $action = $_POST['action'] ?? '';

            // Mise à jour des mentions légales
            if ($action === 'update_legal') {
                $legalText = trim($_POST['legal_mentions'] ?? '');
                $retention = (int)($_POST['retention_months'] ?? 24);
                if ($retention < 1) $retention = 1;
                if ($retention > 120) $retention = 120;
                $this->settings->set('legal_mentions', $legalText, App::auth()->getUser());
                $this->settings->set('retention_months', (string)$retention, App::auth()->getUser());
                App::audit()->log('rgpd_settings', 'settings', 'Mentions légales et durée de conservation mises à jour');
                $successMsg = 'Mentions légales et durée de conservation mises à jour.';
            }

            // Export des données d'un utilisateur
            if ($action === 'export_user') {
                $email = validate_email($_POST['export_email'] ?? '');
                if (empty($email)) {
                    $errorMsg = 'Adresse email invalide.';
                } else {
                    $data = App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData($email);

                    // P2-B : Inclure les données validator (filled_by='validator')
                    $data['validator_data_filled'] = $this->submissionRepo->getValidatorDataFilledByEmail($email);
                    $data['validator_data_on_submissions'] = $this->submissionRepo->getValidatorDataOnSubmissionsByEmail($email);

                    if (empty($data['submissions'])
                        && empty($data['validations'])
                        && empty($data['validator_data_filled'])
                        && empty($data['validator_data_on_submissions'])) {
                        $infoMsg = 'Aucune donnée trouvée pour ' . \App\Core\App::html()->escape($email) . '.';
                    } else {
                        App::audit()->log('rgpd_export', 'user:' . $email, 'Export des données demandé');
                        header('Content-Type: application/json; charset=utf-8');
                        header('Content-Disposition: attachment; filename="rgpd_export_' . str_replace(['@', '.'], '_', $email) . '_' . date('Ymd_His') . '.json"');
                        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        exit;
                    }
                }
            }

            // Suppression des données d'un utilisateur
            if ($action === 'delete_user') {
                $email = validate_email($_POST['delete_email'] ?? '');
                $confirmed = !empty($_POST['confirmed']);
                if (empty($email)) {
                    $errorMsg = 'Adresse email invalide.';
                } elseif (!$confirmed) {
                    $errorMsg = 'Veuillez confirmer la suppression en cochant la case.';
                } elseif ($email === App::auth()->getUser()) {
                    $errorMsg = 'Vous ne pouvez pas supprimer vos propres données.';
                } else {
                    $pdo->exec('PRAGMA foreign_keys = ON');

                    $this->submissionRepo->deleteValidatorDataBySubmitter($email);
                    $this->submissionRepo->deleteValidatorDataByEmail($email);

                    $result = App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData($email);
                    if ($result) {
                        App::audit()->log('rgpd_delete', 'user:' . $email, 'Données utilisateur anonymisées');
                        $successMsg = 'Données de ' . \App\Core\App::html()->escape($email) . ' supprimées (anonymisées).';
                    } else {
                        $errorMsg = 'Erreur lors de la suppression des données.';
                    }
                }
            }

            // Purge automatique des données anciennes
            if ($action === 'auto_purge') {
                $confirmed = !empty($_POST['confirmed']);
                if (!$confirmed) {
                    $errorMsg = 'Veuillez confirmer la purge en cochant la case de confirmation.';
                } else {
                    $months = (int)$this->settings->get('retention_months', '24');

                    $pdo->exec('PRAGMA foreign_keys = ON');

                    $count = App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge($months);

                    $this->submissionRepo->purgeOrphanValidatorData();

                    if ($count > 0) {
                        App::audit()->log('rgpd_purge', 'system', "Purge automatique : {$count} soumissions de plus de {$months} mois supprimées");
                        $successMsg = "Purge effectuée : {$count} soumissions de plus de {$months} mois supprimées.";
                    } else {
                        $infoMsg = "Aucune soumission à purger (critère : plus de {$months} mois).";
                    }
                }
            }
        }

        // Statistiques RGPD
        $retentionMonths = (int)$this->settings->get('retention_months', '24');
        $legalMentions = $this->settings->get('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Contact : ' . $this->settings->get('rgpd_contact', 'CIL DREETS') . '.');

        $totalSubmissions = $this->submissionRepo->countAll();
        $totalAttachments = $this->attachmentRepo->countAll();
        $totalAudit = $this->auditRepo->countAll();
        $oldSubmissions = $this->submissionRepo->countOldByRetention($retentionMonths);
        $dbSize = App::webhook()->getDbSize();

        $pageCss = '';
        $navExtra = [
            'rgpd' => ['href' => 'index.php?p=rgpd', 'label' => 'RGPD', 'icon' => '🔐'],
        ];
        ob_start();
        ?>
  <h1><span aria-hidden="true">🔐</span> Conformité RGPD</h1>

  <?= (new \App\Render\ErrorRenderer())->messages(['success'=>$successMsg, 'error'=>$errorMsg, 'info'=>$infoMsg]) ?>

  <!-- Statistiques des données -->
  <div class="stat-row">
    <div class="stat-mini"><div class="val"><?= $totalSubmissions ?></div><div class="lbl">Soumissions</div></div>
    <div class="stat-mini"><div class="val"><?= $totalAttachments ?></div><div class="lbl">Pièces jointes</div></div>
    <div class="stat-mini"><div class="val"><?= $totalAudit ?></div><div class="lbl">Entrées d'audit</div></div>
    <div class="stat-mini"><div class="val"><?= App::html()->formatFileSize($dbSize) ?></div><div class="lbl">Taille base de données</div></div>
  </div>

  <?php if ($oldSubmissions > 0): ?>
  <div class="warn-box" style="margin-bottom:1.5rem;">
    <strong><span aria-hidden="true">⚠</span> <?= $oldSubmissions ?> soumission<?= $oldSubmissions > 1 ? 's' : '' ?></strong> clôturée<?= $oldSubmissions > 1 ? 's' : '' ?> depuis plus de <?= $retentionMonths ?> mois peuvent être purgées.
  </div>
  <?php endif; ?>

  <!-- Mentions légales -->
  <div class="card">
    <h2><span aria-hidden="true">📜</span> Mentions légales & Politique de conservation</h2>
    <form method="POST">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="update_legal">
      <div class="field">
        <label for="legal_mentions">Mentions légales affichées aux utilisateurs</label>
        <textarea id="legal_mentions" name="legal_mentions" rows="6" style="min-height:120px;"><?= \App\Core\App::html()->escape($legalMentions) ?></textarea>
        <span class="hint">Ce texte est affiché lors de la soumission des formulaires et dans la documentation.</span>
      </div>
      <div class="field">
        <label for="retention_months">Durée de conservation (mois)</label>
        <input type="number" id="retention_months" name="retention_months" value="<?= $retentionMonths ?>" min="1" max="120" style="width:100px;">
        <span class="hint">Les soumissions clôturées plus anciennes seront purgées automatiquement.</span>
      </div>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
  </div>

  <!-- Export des données -->
  <div class="card">
    <h2><span aria-hidden="true">📤</span> Droit d'accès — Export des données</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Conformément à l'article 15 du RGPD, toute personne peut demander l'export de ses données personnelles.
      Saisissez l'adresse email de l'agent pour générer un export JSON complet.
    </p>
    <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="export_user">
      <div class="field" style="margin-bottom:0;flex:1;min-width:250px;">
        <label for="export_email">Email de l'agent</label>
        <input type="email" id="export_email" name="export_email" placeholder="prenom.nom@<?= \App\Core\App::html()->escape($this->settings->get('email_domain', 'exemple.invalid')) ?>" required>
      </div>
      <button type="submit" class="btn btn-primary"><span aria-hidden="true">📥</span> Exporter les données</button>
    </form>
  </div>

  <!-- Suppression des données -->
  <div class="danger-zone">
    <h3><span aria-hidden="true">🗑</span> Droit à l'effacement — Suppression des données</h3>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Conformément à l'article 17 du RGPD, toute personne peut demander la suppression de ses données personnelles.
      Les soumissions seront anonymisées (le statut et le workflow sont conservés pour traçabilité, mais les données personnelles sont remplacées).
    </p>
    <form method="POST">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="delete_user">
      <div class="field">
        <label for="delete_email">Email de l'agent à supprimer</label>
        <input type="email" id="delete_email" name="delete_email" placeholder="prenom.nom@<?= \App\Core\App::html()->escape($this->settings->get('email_domain', 'exemple.invalid')) ?>" required>
      </div>
      <label class="checkbox-item" style="margin-bottom:1rem;">
        <input type="checkbox" name="confirmed" value="1" required>
        Je confirme vouloir anonymiser toutes les données de cet agent. Cette action est irréversible.
      </label>
      <button type="submit" class="btn btn-danger"><span aria-hidden="true">🗑</span> Supprimer les données</button>
    </form>
  </div>

  <!-- Purge automatique -->
  <div class="danger-zone">
    <h3><span aria-hidden="true">🧹</span> Purge automatique des données anciennes</h3>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Supprime définitivement les soumissions clôturées de plus de <strong><?= $retentionMonths ?> mois</strong>,
      ainsi que leurs pièces jointes, tokens et alertes associées.
    </p>
    <?php if ($oldSubmissions > 0): ?>
      <div class="warn-box" style="margin-bottom:1rem;">
        <strong><?= $oldSubmissions ?> soumission<?= $oldSubmissions > 1 ? 's' : '' ?></strong> éligible<?= $oldSubmissions > 1 ? 's' : '' ?> à la purge.
      </div>
    <?php else: ?>
      <p style="color:#1a6b3c;font-size:.9rem;margin-bottom:1rem;"><span aria-hidden="true">✓</span> Aucune soumission à purger actuellement.</p>
    <?php endif; ?>
    <form method="POST">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="auto_purge">
      <label class="checkbox-item" style="margin-bottom:1rem;">
        <input type="checkbox" name="confirmed" value="1" required>
        Je confirme vouloir purger définitivement les soumissions anciennes. Cette action est irréversible.
      </label>
      <button type="submit" class="btn btn-danger"><span aria-hidden="true">🧹</span> Exécuter la purge</button>
    </form>
  </div>

<?php
        $content = ob_get_clean();
        if ($content === false) { $content = ''; }
        echo $this->renderPage('RGPD', 'rgpd', $pageCss, $content, ['nav_extra' => $navExtra]);
    }
}