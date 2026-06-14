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
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1080px; }

    /* Hero — Aurora mesh gradient */
    .hero {
      background: var(--gradient-mesh-hero);
      color: #fff;
      border-radius: var(--r-2xl);
      padding: 3rem;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-2xl), 0 0 60px rgba(30,64,175,.2);
    }
    .hero::before {
      content: '';
      position: absolute;
      top: -30%; right: -10%;
      width: 500px; height: 500px;
      background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 60%);
      border-radius: 50%;
      pointer-events: none;
    }
    .hero::after {
      content: '';
      position: absolute;
      bottom: -20%; left: -5%;
      width: 300px; height: 300px;
      background: radial-gradient(circle, rgba(6,182,212,.15) 0%, transparent 60%);
      border-radius: 50%;
      pointer-events: none;
    }
    .hero h1 {
      color: #fff;
      font-size: var(--text-4xl);
      margin-bottom: .5rem;
      position: relative;
      letter-spacing: -.04em;
      font-weight: 900;
    }
    .hero p {
      opacity: .9;
      font-size: var(--text-lg);
      line-height: 1.7;
      position: relative;
      max-width: 640px;
    }

    /* Quick stats — Bento grid */
    .quick-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: var(--sp-4);
      margin-bottom: 2rem;
    }
    .qs-card {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      padding: 1.25rem;
      text-align: center;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
      transition: transform .25s var(--ease-out), box-shadow .25s var(--ease-out);
    }
    .qs-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg), var(--shadow-glow);
    }
    .qs-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: var(--gradient-primary);
    }
    .qs-card .qs-icon { font-size: 1.5rem; margin-bottom: .3rem; opacity: .7; }
    .qs-card .qs-value {
      font-size: var(--text-3xl);
      font-weight: 800;
      color: var(--c-primary);
      letter-spacing: -.03em;
      font-variant-numeric: tabular-nums;
    }
    .qs-card .qs-label {
      font-size: var(--text-xs);
      color: var(--c-text-tertiary);
      margin-top: .15rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .qs-card.warning::before { background: var(--c-warning); }
    .qs-card.warning .qs-value { color: var(--c-warning-dark); }
    .qs-card.danger::before { background: var(--c-danger); }
    .qs-card.danger .qs-value { color: var(--c-danger-dark); }
    .qs-card.success::before { background: var(--c-success); }
    .qs-card.success .qs-value { color: var(--c-success-dark); }

    /* Form cards — Clickable bento with hover glow */
    .form-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }
    .form-card {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      padding: 1.5rem;
      text-decoration: none;
      color: inherit;
      display: block;
      box-shadow: var(--shadow-sm);
      transition: all .3s var(--ease-out);
      position: relative;
      overflow: hidden;
    }
    .form-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: var(--gradient-primary);
      opacity: 0;
      transition: opacity .3s var(--ease-out);
    }
    .form-card:hover {
      box-shadow: var(--shadow-xl), var(--shadow-glow);
      border-color: var(--c-primary-light);
      transform: translateY(-3px);
    }
    .form-card:hover::before { opacity: 1; }
    .form-card .fc-title {
      font-size: var(--text-lg);
      font-weight: 700;
      color: var(--c-primary-dark);
      margin-bottom: .5rem;
    }
    .form-card .fc-desc {
      font-size: var(--text-sm);
      color: var(--c-text-secondary);
      line-height: 1.6;
      margin-bottom: 1rem;
    }
    .form-card .fc-btn {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      background: var(--gradient-primary);
      color: #fff;
      padding: .55rem 1.3rem;
      border-radius: var(--r-full);
      font-size: var(--text-sm);
      font-weight: 600;
      box-shadow: var(--shadow-colored);
      transition: all .2s var(--ease-out);
    }
    .form-card:hover .fc-btn { background: var(--gradient-primary-hover); transform: translateX(2px); }

    /* Nav tiles — Elegant icon + text cards */
    .nav-tiles {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }
    .nav-tile {
      background: var(--c-surface);
      border: 1px solid var(--c-border-light);
      border-radius: var(--r-lg);
      padding: 1.25rem;
      text-decoration: none;
      color: inherit;
      display: flex;
      align-items: center;
      gap: 1rem;
      box-shadow: var(--shadow-xs);
      transition: all .25s var(--ease-out);
    }
    .nav-tile:hover {
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
      border-color: var(--c-primary-light);
      text-decoration: none;
    }
    .nav-tile .nt-icon {
      font-size: 1.5rem;
      flex-shrink: 0;
      width: 40px; height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--c-primary-50);
      border-radius: var(--r-md);
      transition: background .2s var(--ease-out);
    }
    .nav-tile:hover .nt-icon { background: var(--c-primary-100); }
    .nav-tile .nt-label { font-weight: 700; color: var(--c-primary-dark); font-size: var(--text-sm); }
    .nav-tile .nt-desc { font-size: var(--text-xs); color: var(--c-text-tertiary); margin-top: .1rem; }

    .section-title {
      font-size: var(--text-xl);
      color: var(--c-primary-dark);
      border-bottom: 2px solid var(--c-primary-50);
      padding-bottom: .5rem;
      margin-bottom: 1rem;
    }

    /* Brand icon in nav */
    .brand-icon {
      font-size: 1.1rem;
      opacity: .8;
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('accueil') ?>
<main class="container" id="main-content">
  <!-- Hero -->
  <div class="hero">
    <h1>Workflow DREETS BFC</h1>
    <p>Bienvenue sur la plateforme de dématérialisation des circuits de validation. Choisissez un formulaire pour démarrer, ou suivez vos demandes en cours.</p>
  </div>

  <!-- Quick stats -->
  <?php if ($my_pending > 0): ?>
  <div class="quick-stats">
    <div class="qs-card warning">
      <div class="qs-icon" aria-hidden="true">✅</div>
      <div class="qs-value"><?= $my_pending ?></div>
      <div class="qs-label">Validation(s) en attente</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
  <div class="quick-stats">
    <div class="qs-card">
      <div class="qs-icon" aria-hidden="true">📊</div>
      <div class="qs-value"><?= $admin_stats['total'] ?></div>
      <div class="qs-label">Soumissions totales</div>
    </div>
    <div class="qs-card warning">
      <div class="qs-icon" aria-hidden="true">⏳</div>
      <div class="qs-value"><?= $admin_stats['en_cours'] ?></div>
      <div class="qs-label">En cours</div>
    </div>
    <div class="qs-card success">
      <div class="qs-icon" aria-hidden="true">✓</div>
      <div class="qs-value"><?= $admin_stats['valide'] ?></div>
      <div class="qs-label">Validées</div>
    </div>
    <?php if ($admin_stats['bloques'] > 0): ?>
    <div class="qs-card danger">
      <div class="qs-icon" aria-hidden="true">🚨</div>
      <div class="qs-value"><?= $admin_stats['bloques'] ?></div>
      <div class="qs-label">Tokens bloqués</div>
    </div>
    <?php endif; ?>
  </div>
  <?php elseif ($my_total > 0): ?>
  <div class="quick-stats">
    <div class="qs-card">
      <div class="qs-icon" aria-hidden="true">📋</div>
      <div class="qs-value"><?= $my_total ?></div>
      <div class="qs-label">Mes demandes</div>
    </div>
    <div class="qs-card warning">
      <div class="qs-icon" aria-hidden="true">⏳</div>
      <div class="qs-value"><?= $my_en_cours ?></div>
      <div class="qs-label">En cours</div>
    </div>
    <div class="qs-card success">
      <div class="qs-icon" aria-hidden="true">✓</div>
      <div class="qs-value"><?= $my_valide ?></div>
      <div class="qs-label">Validées</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Formulaires disponibles -->
  <h2 class="section-title"><span aria-hidden="true">📝</span> Nouvelle demande</h2>
  <?php if (empty($active_forms)): ?>
    <p style="color:#595959;font-style:italic;margin-bottom:2rem;">Aucun formulaire disponible pour le moment.</p>
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
  <h2 class="section-title"><span aria-hidden="true">🧭</span> Accès rapide</h2>
  <div class="nav-tiles">
    <a href="my_submissions.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">📋</span>
      <div>
        <div class="nt-label">Mes demandes</div>
        <div class="nt-desc">Suivre l'avancement de mes soumissions</div>
      </div>
    </a>
    <a href="my_validations.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">✅</span>
      <div>
        <div class="nt-label">Mes validations</div>
        <div class="nt-desc">Voir les tâches de validation qui m'attendent</div>
      </div>
    </a>
    <a href="docs.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">📖</span>
      <div>
        <div class="nt-label">Documentation</div>
        <div class="nt-desc">Guides et aide pour utiliser la plateforme</div>
      </div>
    </a>
    <?php if ($has_owned): ?>
    <?php foreach ($owned_forms as $of): ?>
    <a href="form_tracking.php?f=<?= urlencode($of['id']) ?>" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">📊</span>
      <div>
        <div class="nt-label">Suivi : <?= h($of['label']) ?></div>
        <div class="nt-desc">Tableau de suivi propriétaire</div>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <a href="dashboard.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">📊</span>
      <div>
        <div class="nt-label">Dashboard admin</div>
        <div class="nt-desc">Superviser toutes les soumissions</div>
      </div>
    </a>
    <a href="monitoring.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">🖥</span>
      <div>
        <div class="nt-label">Monitoring</div>
        <div class="nt-desc">Santé système, alertes, audit</div>
      </div>
    </a>
    <a href="admin_forms.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">⚙</span>
      <div>
        <div class="nt-label">Gestion formulaires</div>
        <div class="nt-desc">Configurer formulaires, étapes et champs</div>
      </div>
    </a>
    <a href="admin_alerts.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">🔔</span>
      <div>
        <div class="nt-label">Alertes</div>
        <div class="nt-desc">Configurer les règles d'alerte</div>
      </div>
    </a>
    <a href="admin_settings.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">🔧</span>
      <div>
        <div class="nt-label">Paramètres</div>
        <div class="nt-desc">Configuration SMTP et workflow</div>
      </div>
    </a>
    <a href="backup.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">💾</span>
      <div>
        <div class="nt-label">Sauvegarde</div>
        <div class="nt-desc">Sauvegarder et restaurer la base de données</div>
      </div>
    </a>
    <a href="stats.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">📊</span>
      <div>
        <div class="nt-label">Statistiques</div>
        <div class="nt-desc">Tableaux de bord et métriques d'utilisation</div>
      </div>
    </a>
    <a href="rgpd.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">🔐</span>
      <div>
        <div class="nt-label">RGPD</div>
        <div class="nt-desc">Conformité et gestion des données personnelles</div>
      </div>
    </a>
    <?php endif; ?>
    <a href="health.php" class="nav-tile">
      <span class="nt-icon" aria-hidden="true">🏥</span>
      <div>
        <div class="nt-label">Santé système</div>
        <div class="nt-desc">Vérifier l'état des services et de l'infrastructure</div>
      </div>
    </a>
  </div>
</main>
<?= render_footer() ?>
</body>
</html>
