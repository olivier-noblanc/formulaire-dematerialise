<?php
// index.php — Page d'accueil adaptée au rôle de l'utilisateur
require_once __DIR__ . '/helpers.php';

$user = get_auth_user();
$pdo = get_pdo();
$is_admin = is_admin_user();

// Récupérer les formulaires dont l'utilisateur est propriétaire
$owned_forms = get_owned_forms($user);
$has_owned = !empty($owned_forms);

// Récupérer les formulaires actifs
$active_forms = $pdo->query("SELECT id, slug, label, description FROM forms WHERE actif = 1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

// Pour les agents : compter leurs soumissions
$my_total = 0; $my_en_cours = 0; $my_valide = 0;
if (!$is_admin) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status");
    $stmt->execute([$user]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $my_total += (int)$row['cnt'];
        if ($row['status'] === 'en_cours') $my_en_cours = (int)$row['cnt'];
        elseif ($row['status'] === 'valide') $my_valide = (int)$row['cnt'];
    }
}

// Pour les validateurs : compter les tokens en attente
$my_pending = 0;
$pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE email = ? AND done_at IS NULL");
$pending_stmt->execute([$user]);
$my_pending = (int)$pending_stmt->fetchColumn();

// Pour les admins : stats globales
$admin_stats = [];
if ($is_admin) {
    $admin_stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    $admin_stats['en_cours'] = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'en_cours'")->fetchColumn();
    $admin_stats['valide'] = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'valide'")->fetchColumn();
    $admin_stats['bloques'] = 0;
    $delai = (int)get_setting('delai_relance_h', '48');
    $bloque_h = $delai * 2;
    $admin_stats['bloques'] = (int)$pdo->query("
        SELECT COUNT(*) FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE t.done_at IS NULL AND s.status = 'en_cours'
          AND CAST(strftime('%s', 'now') AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) > ($bloque_h * 3600)
    ")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accueil — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1000px; }
    h1 { font-size: 1.6rem; margin-bottom: .25rem; }

    /* Hero */
    .hero { background: linear-gradient(135deg, #003189 0%, #002270 100%); color: #fff; border-radius: 8px; padding: 2rem; margin-bottom: 2rem; }
    .hero h1 { color: #fff; font-size: 1.6rem; margin-bottom: .5rem; }
    .hero p { opacity: .85; font-size: .95rem; line-height: 1.6; }

    /* Quick stats */
    .quick-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .qs-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1.25rem; text-align: center; transition: box-shadow .2s; }
    .qs-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .qs-card .qs-icon { font-size: 1.8rem; margin-bottom: .5rem; }
    .qs-card .qs-value { font-size: 2rem; font-weight: bold; color: #003189; }
    .qs-card .qs-label { font-size: .8rem; color: #888; margin-top: .15rem; }
    .qs-card.warning .qs-value { color: #b45309; }
    .qs-card.danger .qs-value { color: #c0392b; }
    .qs-card.success .qs-value { color: #1a6b3c; }

    /* Form cards */
    .form-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
    .form-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem; transition: box-shadow .2s, border-color .2s; text-decoration: none; color: inherit; display: block; }
    .form-card:hover { box-shadow: 0 4px 16px rgba(0,49,137,.12); border-color: #003189; }
    .form-card .fc-title { font-size: 1.1rem; font-weight: bold; color: #003189; margin-bottom: .5rem; }
    .form-card .fc-desc { font-size: .85rem; color: #666; line-height: 1.5; margin-bottom: 1rem; }
    .form-card .fc-btn { display: inline-block; background: #003189; color: #fff; padding: .5rem 1.25rem; border-radius: 4px; font-size: .85rem; font-weight: bold; }
    .form-card:hover .fc-btn { background: #002270; }

    /* Nav tiles */
    .nav-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
    .nav-tile { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1.25rem; text-decoration: none; color: inherit; display: flex; align-items: center; gap: 1rem; transition: box-shadow .2s; }
    .nav-tile:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .nav-tile .nt-icon { font-size: 1.8rem; flex-shrink: 0; }
    .nav-tile .nt-label { font-weight: bold; color: #003189; font-size: .95rem; }
    .nav-tile .nt-desc { font-size: .78rem; color: #888; margin-top: .15rem; }

    .section-title { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h($user) ?></strong></span>
  <span>
    <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📖 Documentation</a>
    <?php if ($is_admin): ?>
    <a href="stats.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📊 Stats</a>
    <a href="rgpd.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">🔐 RGPD</a>
    <?php endif; ?>
    <a href="health.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">🏥 Santé</a>
  </span>
</div>
<div class="container" id="main-content">
  <!-- Hero -->
  <div class="hero">
    <h1>Workflow DREETS BFC</h1>
    <p>Bienvenue sur la plateforme de dématérialisation des circuits de validation. Choisissez un formulaire pour démarrer, ou suivez vos demandes en cours.</p>
  </div>

  <!-- Quick stats -->
  <?php if ($my_pending > 0): ?>
  <div class="quick-stats">
    <div class="qs-card warning">
      <div class="qs-icon">✅</div>
      <div class="qs-value"><?= $my_pending ?></div>
      <div class="qs-label">Validation(s) en attente</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
  <div class="quick-stats">
    <div class="qs-card">
      <div class="qs-icon">📊</div>
      <div class="qs-value"><?= $admin_stats['total'] ?></div>
      <div class="qs-label">Soumissions totales</div>
    </div>
    <div class="qs-card warning">
      <div class="qs-icon">⏳</div>
      <div class="qs-value"><?= $admin_stats['en_cours'] ?></div>
      <div class="qs-label">En cours</div>
    </div>
    <div class="qs-card success">
      <div class="qs-icon">✓</div>
      <div class="qs-value"><?= $admin_stats['valide'] ?></div>
      <div class="qs-label">Validées</div>
    </div>
    <?php if ($admin_stats['bloques'] > 0): ?>
    <div class="qs-card danger">
      <div class="qs-icon">🚨</div>
      <div class="qs-value"><?= $admin_stats['bloques'] ?></div>
      <div class="qs-label">Tokens bloqués</div>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($my_total > 0): ?>
  <div class="quick-stats">
    <div class="qs-card">
      <div class="qs-icon">📋</div>
      <div class="qs-value"><?= $my_total ?></div>
      <div class="qs-label">Mes demandes</div>
    </div>
    <div class="qs-card warning">
      <div class="qs-icon">⏳</div>
      <div class="qs-value"><?= $my_en_cours ?></div>
      <div class="qs-label">En cours</div>
    </div>
    <div class="qs-card success">
      <div class="qs-icon">✓</div>
      <div class="qs-value"><?= $my_valide ?></div>
      <div class="qs-label">Validées</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Formulaires disponibles -->
  <h2 class="section-title">📝 Nouvelle demande</h2>
  <?php if (empty($active_forms)): ?>
    <p style="color:#888;font-style:italic;margin-bottom:2rem;">Aucun formulaire disponible pour le moment.</p>
  <?php else: ?>
    <div class="form-cards">
      <?php foreach ($active_forms as $af): ?>
        <a href="form.php?f=<?= h($af['slug']) ?>" class="form-card">
          <div class="fc-title"><?= h($af['label']) ?></div>
          <?php if (!empty($af['description'])): ?>
            <div class="fc-desc"><?= h($af['description']) ?></div>
          <?php endif; ?>
          <div class="fc-btn">Remplir le formulaire →</div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Navigation rapide -->
  <h2 class="section-title">🧭 Accès rapide</h2>
  <div class="nav-tiles">
    <a href="my_submissions.php" class="nav-tile">
      <span class="nt-icon">📋</span>
      <div>
        <div class="nt-label">Mes demandes</div>
        <div class="nt-desc">Suivre l'avancement de mes soumissions</div>
      </div>
    </a>
    <a href="my_validations.php" class="nav-tile">
      <span class="nt-icon">✅</span>
      <div>
        <div class="nt-label">Mes validations</div>
        <div class="nt-desc">Voir les tâches de validation qui m'attendent</div>
      </div>
    </a>
    <a href="docs.php" class="nav-tile">
      <span class="nt-icon">📖</span>
      <div>
        <div class="nt-label">Documentation</div>
        <div class="nt-desc">Guides et aide pour utiliser la plateforme</div>
      </div>
    </a>
    <?php if ($has_owned): ?>
    <?php foreach ($owned_forms as $of): ?>
    <a href="form_tracking.php?f=<?= urlencode($of['id']) ?>" class="nav-tile">
      <span class="nt-icon">📊</span>
      <div>
        <div class="nt-label">Suivi : <?= h($of['label']) ?></div>
        <div class="nt-desc">Tableau de suivi propriétaire</div>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <a href="dashboard.php" class="nav-tile">
      <span class="nt-icon">📊</span>
      <div>
        <div class="nt-label">Dashboard admin</div>
        <div class="nt-desc">Superviser toutes les soumissions</div>
      </div>
    </a>
    <a href="monitoring.php" class="nav-tile">
      <span class="nt-icon">🖥</span>
      <div>
        <div class="nt-label">Monitoring</div>
        <div class="nt-desc">Santé système, alertes, audit</div>
      </div>
    </a>
    <a href="admin_forms.php" class="nav-tile">
      <span class="nt-icon">⚙</span>
      <div>
        <div class="nt-label">Gestion formulaires</div>
        <div class="nt-desc">Configurer formulaires, étapes et champs</div>
      </div>
    </a>
    <a href="admin_alerts.php" class="nav-tile">
      <span class="nt-icon">🔔</span>
      <div>
        <div class="nt-label">Alertes</div>
        <div class="nt-desc">Configurer les règles d'alerte</div>
      </div>
    </a>
    <a href="admin_settings.php" class="nav-tile">
      <span class="nt-icon">🔧</span>
      <div>
        <div class="nt-label">Paramètres</div>
        <div class="nt-desc">Configuration SMTP et workflow</div>
      </div>
    </a>
    <a href="backup.php" class="nav-tile">
      <span class="nt-icon">💾</span>
      <div>
        <div class="nt-label">Sauvegarde</div>
        <div class="nt-desc">Sauvegarder et restaurer la base de données</div>
      </div>
    </a>
    <a href="stats.php" class="nav-tile">
      <span class="nt-icon">📊</span>
      <div>
        <div class="nt-label">Statistiques</div>
        <div class="nt-desc">Tableaux de bord et métriques d'utilisation</div>
      </div>
    </a>
    <a href="rgpd.php" class="nav-tile">
      <span class="nt-icon">🔐</span>
      <div>
        <div class="nt-label">RGPD</div>
        <div class="nt-desc">Conformité et gestion des données personnelles</div>
      </div>
    </a>
    <?php endif; ?>
    <a href="health.php" class="nav-tile">
      <span class="nt-icon">🏥</span>
      <div>
        <div class="nt-label">Santé système</div>
        <div class="nt-desc">Vérifier l'état des services et de l'infrastructure</div>
      </div>
    </a>
  </div>
</div>
<?= render_footer() ?>
</body>
</html>
