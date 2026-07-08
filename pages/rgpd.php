<?php
// rgpd.php — Conformité RGPD : mentions légales, export, suppression, purge
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

require_admin();

$pdo = get_pdo();
$success_msg = '';
$error_msg = '';
$info_msg = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // Mise à jour des mentions légales
    if ($action === 'update_legal') {
        $legal_text = trim($_POST['legal_mentions'] ?? '');
        $retention = (int)($_POST['retention_months'] ?? 24);
        if ($retention < 1) $retention = 1;
        if ($retention > 120) $retention = 120;
        \App\Core\App::settings()->set('legal_mentions', $legal_text, get_auth_user());
        \App\Core\App::settings()->set('retention_months', (string)$retention, get_auth_user());
        App::audit()->log('rgpd_settings', 'settings', 'Mentions légales et durée de conservation mises à jour');
        $success_msg = 'Mentions légales et durée de conservation mises à jour.';
    }

    // Export des données d'un utilisateur
    if ($action === 'export_user') {
        $email = validate_email($_POST['export_email'] ?? '');
        if (empty($email)) {
            $error_msg = 'Adresse email invalide.';
        } else {
            $data = rgpd_export_user_data($email);

            // P2-B : Inclure les données validator (filled_by='validator')
            // (1) Données validator remplies PAR l'agent (filled_by_email)
            $vd_filled_stmt = $pdo->prepare(
                "SELECT submission_id, field_name, field_label, field_type, value,\n"
                . "       filled_at, step_id, step_label\n"
                . "FROM submission_validator_data\n"
                . "WHERE filled_by_email = ?\n"
                . "ORDER BY filled_at DESC, field_name"
            );
            $vd_filled_stmt->execute([$email]);
            $data['validator_data_filled'] = $vd_filled_stmt->fetchAll(PDO::FETCH_ASSOC);

            // (2) Données validator associées aux soumissions de l'agent (en tant que demandeur)
            $vd_on_subs_stmt = $pdo->prepare(
                "SELECT svd.submission_id, svd.field_name, svd.field_label, svd.field_type,\n"
                . "       svd.value, svd.filled_at, svd.step_id, svd.step_label, svd.filled_by_email\n"
                . "FROM submission_validator_data svd\n"
                . "JOIN submissions s ON s.id = svd.submission_id\n"
                . "WHERE s.submitted_by = ?\n"
                . "ORDER BY svd.submission_id, svd.filled_at, svd.field_name"
            );
            $vd_on_subs_stmt->execute([$email]);
            $data['validator_data_on_submissions'] = $vd_on_subs_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($data['submissions'])
                && empty($data['validations'])
                && empty($data['validator_data_filled'])
                && empty($data['validator_data_on_submissions'])) {
                $info_msg = 'Aucune donnée trouvée pour ' . h($email) . '.';
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
            $error_msg = 'Adresse email invalide.';
        } elseif (!$confirmed) {
            $error_msg = 'Veuillez confirmer la suppression en cochant la case.';
        } elseif ($email === get_auth_user()) {
            $error_msg = 'Vous ne pouvez pas supprimer vos propres données.';
        } else {
            // P2-B : Purger les données validator (filled_by='validator')
            // La FK submission_validator_data.submission_id est ON DELETE CASCADE,
            // MAIS rgpd_delete_user_data() anonymise les soumissions via UPDATE
            // (pas DELETE) — donc il faut explicitement supprimer ces données
            // AVANT l'anonymisation (sinon on perd le lien submitted_by).
            $pdo->exec('PRAGMA foreign_keys = ON');

            // (1) Supprimer les données validator associées aux soumissions de l'agent
            $pdo->prepare(
                "DELETE FROM submission_validator_data\n"
                . "WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by = ?)"
            )->execute([$email]);

            // (2) Purger les données validator remplies PAR l'agent (filled_by_email)
            //     — l'agent peut avoir validé des soumissions d'autres agents
            $pdo->prepare("DELETE FROM submission_validator_data WHERE filled_by_email = ?")
                ->execute([$email]);

            // (3) Appeler la fonction helpers standard (anonymise soumissions, tokens, etc.)
            $result = rgpd_delete_user_data($email);
            if ($result) {
                App::audit()->log('rgpd_delete', 'user:' . $email, 'Données utilisateur anonymisées');
                $success_msg = 'Données de ' . h($email) . ' supprimées (anonymisées).';
            } else {
                $error_msg = 'Erreur lors de la suppression des données.';
            }
        }
    }

    // Purge automatique des données anciennes
    if ($action === 'auto_purge') {
        $confirmed = !empty($_POST['confirmed']);
        if (!$confirmed) {
            $error_msg = 'Veuillez confirmer la purge en cochant la case de confirmation.';
        } else {
            $months = (int)\App\Core\App::settings()->get('retention_months', '24');

            // P2-B : Activer les FK pour que CASCADE supprime les submission_validator_data
            // lors du DELETE FROM submissions effectué par rgpd_auto_purge().
            // (Par défaut SQLite a PRAGMA foreign_keys = OFF, donc CASCADE est inactif.)
            $pdo->exec('PRAGMA foreign_keys = ON');

            $count = rgpd_auto_purge($months);

            // Ceinture + bretelles : supprimer les données validator orphelines
            // (au cas où PRAGMA foreign_keys n'aurait pas été actif lors d'un DELETE
            // antérieur, ou si la connexion a été réinitialisée entre-temps).
            $pdo->exec(
                "DELETE FROM submission_validator_data\n"
                . "WHERE submission_id NOT IN (SELECT id FROM submissions)"
            );

            if ($count > 0) {
                App::audit()->log('rgpd_purge', 'system', "Purge automatique : {$count} soumissions de plus de {$months} mois supprimées");
                $success_msg = "Purge effectuée : {$count} soumissions de plus de {$months} mois supprimées.";
            } else {
                $info_msg = "Aucune soumission à purger (critère : plus de {$months} mois).";
            }
        }
    }
}

// Statistiques RGPD
$retention_months = (int)\App\Core\App::settings()->get('retention_months', '24');
$legal_mentions = \App\Core\App::settings()->get('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Contact : ' . \App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS') . '.');

$total_submissions = (int)_dbm_q($pdo, "SELECT COUNT(*) FROM submissions")->fetchColumn();
$total_attachments = (int)_dbm_q($pdo, "SELECT COUNT(*) FROM attachments")->fetchColumn();
$total_audit = (int)_dbm_q($pdo, "SELECT COUNT(*) FROM audit_log")->fetchColumn();
$old_submissions_stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE status != 'en_cours' AND closed_at < datetime('now', '-' || ? || ' months')");
$old_submissions_stmt->execute([$retention_months]);
$old_submissions = (int)$old_submissions_stmt->fetchColumn();
$db_size = get_db_size();
?>
<?php
$page_css = '';
$nav_extra = [
    'rgpd' => ['href' => 'index.php?p=rgpd', 'label' => 'RGPD', 'icon' => '🔐'],
];
ob_start();
?>
  <h1><span aria-hidden="true">🔐</span> Conformité RGPD</h1>

  <?= render_messages(['success'=>$success_msg, 'error'=>$error_msg, 'info'=>$info_msg]) ?>

  <!-- Statistiques des données -->
  <div class="stat-row">
    <div class="stat-mini"><div class="val"><?= $total_submissions ?></div><div class="lbl">Soumissions</div></div>
    <div class="stat-mini"><div class="val"><?= $total_attachments ?></div><div class="lbl">Pièces jointes</div></div>
    <div class="stat-mini"><div class="val"><?= $total_audit ?></div><div class="lbl">Entrées d'audit</div></div>
    <div class="stat-mini"><div class="val"><?= format_file_size($db_size) ?></div><div class="lbl">Taille base de données</div></div>
  </div>

  <?php if ($old_submissions > 0): ?>
  <div class="warn-box" style="margin-bottom:1.5rem;">
    <strong><span aria-hidden="true">⚠</span> <?= $old_submissions ?> soumission<?= $old_submissions > 1 ? 's' : '' ?></strong> clôturée<?= $old_submissions > 1 ? 's' : '' ?> depuis plus de <?= $retention_months ?> mois peuvent être purgées.
  </div>
  <?php endif; ?>

  <!-- Mentions légales -->
  <div class="card">
    <h2><span aria-hidden="true">📜</span> Mentions légales & Politique de conservation</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_legal">
      <div class="field">
        <label for="legal_mentions">Mentions légales affichées aux utilisateurs</label>
        <textarea id="legal_mentions" name="legal_mentions" rows="6" style="min-height:120px;"><?= h($legal_mentions) ?></textarea>
        <span class="hint">Ce texte est affiché lors de la soumission des formulaires et dans la documentation.</span>
      </div>
      <div class="field">
        <label for="retention_months">Durée de conservation (mois)</label>
        <input type="number" id="retention_months" name="retention_months" value="<?= $retention_months ?>" min="1" max="120" style="width:100px;">
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
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="export_user">
      <div class="field" style="margin-bottom:0;flex:1;min-width:250px;">
        <label for="export_email">Email de l'agent</label>
        <input type="email" id="export_email" name="export_email" placeholder="prenom.nom@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" required>
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
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_user">
      <div class="field">
        <label for="delete_email">Email de l'agent à supprimer</label>
        <input type="email" id="delete_email" name="delete_email" placeholder="prenom.nom@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" required>
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
      Supprime définitivement les soumissions clôturées de plus de <strong><?= $retention_months ?> mois</strong>,
      ainsi que leurs pièces jointes, tokens et alertes associées.
    </p>
    <?php if ($old_submissions > 0): ?>
      <div class="warn-box" style="margin-bottom:1rem;">
        <strong><?= $old_submissions ?> soumission<?= $old_submissions > 1 ? 's' : '' ?></strong> éligible<?= $old_submissions > 1 ? 's' : '' ?> à la purge.
      </div>
    <?php else: ?>
      <p style="color:#1a6b3c;font-size:.9rem;margin-bottom:1rem;"><span aria-hidden="true">✓</span> Aucune soumission à purger actuellement.</p>
    <?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
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
echo render_page('RGPD', 'rgpd', $page_css, $content, ['nav_extra' => $nav_extra]);
