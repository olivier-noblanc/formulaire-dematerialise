<?php
// rgpd.php — Conformité RGPD : mentions légales, export, suppression, purge
require_once __DIR__ . '/helpers.php';

if (!is_admin_user()) {
    header('Location: admin_access.php');
    exit;
}

$pdo = get_pdo();
$success_msg = '';
$error_msg = '';
$info_msg = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        render_error_page(403, 'Requête invalide', 'Le jeton de sécurité (CSRF) de votre session est invalide ou a expiré. Cela peut arriver si votre session a été inactive trop longtemps ou si la page est restée ouverte depuis longtemps.', 'Rechargez la page et réessayez. Si le problème persiste, fermez tous les onglets de l\'application et reconnectez-vous.');
    }

    $action = $_POST['action'] ?? '';

    // Mise à jour des mentions légales
    if ($action === 'update_legal') {
        $legal_text = trim($_POST['legal_mentions'] ?? '');
        $retention = (int)($_POST['retention_months'] ?? 24);
        if ($retention < 1) $retention = 1;
        if ($retention > 120) $retention = 120;
        set_setting('legal_mentions', $legal_text, get_auth_user());
        set_setting('retention_months', (string)$retention, get_auth_user());
        app_log('rgpd_settings', 'settings', 'Mentions légales et durée de conservation mises à jour');
        $success_msg = 'Mentions légales et durée de conservation mises à jour.';
    }

    // Export des données d'un utilisateur
    if ($action === 'export_user') {
        if (!rate_limit_check('rgpd_export', 5, 60)) {
            $error_msg = 'Trop de demandes d\'export. Veuillez patienter.';
        } else {
            $email = validate_email($_POST['export_email'] ?? '');
            if (empty($email)) {
                $error_msg = 'Adresse email invalide.';
            } else {
                $data = rgpd_export_user_data($email);
                if (empty($data['submissions']) && empty($data['validations'])) {
                    $info_msg = 'Aucune donnée trouvée pour ' . h($email) . '.';
                } else {
                    app_log('rgpd_export', 'user:' . $email, 'Export des données demandé');
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="rgpd_export_' . str_replace(['@', '.'], '_', $email) . '_' . date('Ymd_His') . '.json"');
                    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    exit;
                }
            }
        }
    }

    // Suppression des données d'un utilisateur
    if ($action === 'delete_user') {
        if (!rate_limit_check('rgpd_delete', 3, 300)) {
            $error_msg = 'Trop de demandes de suppression. Veuillez patienter.';
        } else {
            $email = validate_email($_POST['delete_email'] ?? '');
            $confirmed = !empty($_POST['confirmed']);
            if (empty($email)) {
                $error_msg = 'Adresse email invalide.';
            } elseif (!$confirmed) {
                $error_msg = 'Veuillez confirmer la suppression en cochant la case.';
            } elseif ($email === get_auth_user()) {
                $error_msg = 'Vous ne pouvez pas supprimer vos propres données.';
            } else {
                $result = rgpd_delete_user_data($email);
                if ($result) {
                    app_log('rgpd_delete', 'user:' . $email, 'Données utilisateur anonymisées');
                    $success_msg = 'Données de ' . h($email) . ' supprimées (anonymisées).';
                } else {
                    $error_msg = 'Erreur lors de la suppression des données.';
                }
            }
        }
    }

    // Purge automatique des données anciennes
    if ($action === 'auto_purge') {
        $confirmed = !empty($_POST['confirmed']);
        if (!$confirmed) {
            $error_msg = 'Veuillez confirmer la purge en cochant la case de confirmation.';
        } else {
            $months = (int)get_setting('retention_months', '24');
            $count = rgpd_auto_purge($months);
            if ($count > 0) {
                app_log('rgpd_purge', 'system', "Purge automatique : {$count} soumissions de plus de {$months} mois supprimées");
                $success_msg = "Purge effectuée : {$count} soumissions de plus de {$months} mois supprimées.";
            } else {
                $info_msg = "Aucune soumission à purger (critère : plus de {$months} mois).";
            }
        }
    }
}

// Statistiques RGPD
$retention_months = (int)get_setting('retention_months', '24');
$legal_mentions = get_setting('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Contact : CIL DREETS.');

$total_submissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$total_attachments = (int)$pdo->query("SELECT COUNT(*) FROM attachments")->fetchColumn();
$total_audit = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$old_submissions = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status != 'en_cours' AND closed_at < datetime('now', '-{$retention_months} months')")->fetchColumn();
$db_size = file_exists(defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db') ? filesize(defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db') : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RGPD — FluxDREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 900px; }
    .danger-zone { border: 2px solid var(--c-danger-dark); background: var(--c-danger-50); border-radius: var(--r-md); padding: 1.5rem; margin-bottom: 1.5rem; }
    .danger-zone h3 { color: #c0392b; margin-bottom: 1rem; }
    .stat-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .stat-mini { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--r-sm); padding: .75rem 1rem; flex: 1; min-width: 140px; text-align: center; }
    .stat-mini .val { font-size: 1.5rem; font-weight: bold; color: var(--c-primary-dark); }
    .stat-mini .lbl { font-size: .8rem; color: var(--c-text-secondary); margin-top: .25rem; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('rgpd', [
    'rgpd' => ['href' => 'rgpd.php', 'label' => 'RGPD', 'icon' => '🔐'],
]) ?>
<?= render_breadcrumb([['Accueil', 'index.php'], ['RGPD']]) ?>
<main class="container" id="main-content">
  <h1><span aria-hidden="true">🔐</span> Conformité RGPD</h1>

  <?php if ($success_msg): ?><div class="msg-success"><?= h($success_msg) ?></div><?php endif; ?>
  <?php if ($error_msg): ?><div class="msg-error"><?= h($error_msg) ?></div><?php endif; ?>
  <?php if ($info_msg): ?><div class="msg-info"><?= h($info_msg) ?></div><?php endif; ?>

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
        <input type="email" id="export_email" name="export_email" placeholder="prenom.nom@dreets.gouv.fr" required>
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
        <input type="email" id="delete_email" name="delete_email" placeholder="prenom.nom@dreets.gouv.fr" required>
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

</main>
<?= render_footer() ?>
</body>
</html>
