<?php
// confirm_action.php — Page de confirmation pour les actions destructrices
// Remplace les boîtes de dialogue JavaScript confirm() par une page serveur
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$from   = $_GET['from'] ?? '';

// Définir les actions supportées avec leur description et paramètres requis
$actions_config = [
    'cancel_submission' => [
        'label'       => 'Annuler une soumission',
        'description' => 'Voulez-vous vraiment annuler la soumission',
        'params'      => ['submission_id'],
        'param_label' => 'soumission',
        'danger'      => true,
    ],
    'regenerate_token' => [
        'label'       => 'Régénérer un token',
        'description' => 'Voulez-vous vraiment régénérer le token pour',
        'params'      => ['token_id'],
        'param_label' => 'token',
        'danger'      => false,
    ],
    'delete_rule' => [
        'label'       => 'Supprimer une règle d\'alerte',
        'description' => 'Voulez-vous vraiment supprimer cette règle d\'alerte',
        'params'      => ['rule_id'],
        'param_label' => 'règle',
        'danger'      => true,
    ],
    'delete_alert_log' => [
        'label'       => 'Supprimer une entrée de journal',
        'description' => 'Voulez-vous vraiment supprimer cette entrée du journal d\'alertes',
        'params'      => ['log_id'],
        'param_label' => 'entrée',
        'danger'      => true,
    ],
    'remove_admin' => [
        'label'       => 'Retirer les droits administrateur',
        'description' => 'Voulez-vous vraiment retirer les droits administrateur de',
        'params'      => ['email'],
        'param_label' => 'admin',
        'danger'      => true,
    ],
];

// Vérifier que l'action est supportée
if (!isset($actions_config[$action])) {
    header('Location: index.php');
    exit;
}

$config = $actions_config[$action];

// Vérifier que tous les paramètres requis sont présents
foreach ($config['params'] as $param) {
    if (empty($_GET[$param])) {
        header('Location: index.php');
        exit;
    }
}

// Construire le message de confirmation
$confirm_message = $config['description'];
$detail_text = '';

switch ($action) {
    case 'cancel_submission':
        $sub_id = (int)$_GET['submission_id'];
        $detail_text = '#' . $sub_id . ' ?';
        break;
    case 'regenerate_token':
        $token_id = (int)$_GET['token_id'];
        // Récupérer l'email associé au token
        $pdo = get_pdo();
        $tok_stmt = $pdo->prepare("SELECT t.email, st.label as step_label FROM tokens t JOIN steps st ON st.id = t.step_id WHERE t.id = ?");
        $tok_stmt->execute([$token_id]);
        $tok_info = $tok_stmt->fetch(PDO::FETCH_ASSOC);
        if ($tok_info) {
            $detail_text = h($tok_info['email']) . ' (étape : ' . h($tok_info['step_label']) . ') ?';
        } else {
            $detail_text = 'token #' . $token_id . ' ?';
        }
        break;
    case 'delete_rule':
        $rule_id = (int)$_GET['rule_id'];
        // Récupérer le nom de la règle
        $pdo = get_pdo();
        $rule_stmt = $pdo->prepare("SELECT label FROM alert_rules WHERE id = ?");
        $rule_stmt->execute([$rule_id]);
        $rule_label = $rule_stmt->fetchColumn();
        $detail_text = $rule_label ? '"' . h($rule_label) . '" ( #' . $rule_id . ') ?' : '#' . $rule_id . ' ?';
        break;
    case 'delete_alert_log':
        $log_id = (int)$_GET['log_id'];
        $detail_text = '#' . $log_id . ' ?';
        break;
    case 'remove_admin':
        $email = $_GET['email'];
        $detail_text = h($email) . ' ?';
        break;
}

// URL de retour pour le bouton Annuler
$cancel_url = $from ?: ($_SERVER['HTTP_REFERER'] ?? 'index.php');

// Construire l'URL de destination pour le POST (la page d'origine)
// On déduit la page cible à partir du paramètre `from`
$post_url = $from ?: 'index.php';
// Garder les paramètres de requête du from (ex: submission_view.php?id=5)
if ($from && strpos($from, '?') === false) {
    // Ajouter les paramètres de contexte si nécessaire
    if ($action === 'cancel_submission' && isset($_GET['submission_id'])) {
        $post_url = 'submission_view.php?id=' . (int)$_GET['submission_id'];
        if (empty($from)) {
            $post_url = 'dashboard.php';
        }
    }
}
// Si from contient déjà des paramètres (comme submission_view.php?id=5), on l'utilise tel quel
if (!empty($from)) {
    $post_url = $from;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Confirmation — <?= h($config['label']) ?> — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 600px; }
    .confirm-card { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 2rem; margin-top: 2rem; }
    .confirm-card.danger { border-color: #c0392b; }
    .confirm-card.danger .confirm-icon { color: #c0392b; }
    .confirm-card.warning { border-color: #b45309; }
    .confirm-card.warning .confirm-icon { color: #b45309; }
    .confirm-icon { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
    .confirm-title { font-size: 1.3rem; font-weight: bold; color: #c0392b; text-align: center; margin-bottom: 1rem; }
    .confirm-title.warning-title { color: #b45309; }
    .confirm-message { font-size: 1rem; color: #333; text-align: center; margin-bottom: 1.5rem; line-height: 1.6; }
    .confirm-message strong { color: #c0392b; }
    .confirm-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    .confirm-actions .btn { padding: .65rem 1.5rem; font-size: .95rem; }
    .confirm-warning { background: #fff3e0; border: 1px solid #b45309; border-radius: 4px; padding: .75rem 1rem; margin-bottom: 1.5rem; font-size: .85rem; color: #b45309; text-align: center; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
</div>
<div class="container" id="main-content">

  <div class="confirm-card <?= $config['danger'] ? 'danger' : 'warning' ?>">
    <div class="confirm-icon"><?= $config['danger'] ? '⚠️' : '🔄' ?></div>
    <div class="confirm-title <?= $config['danger'] ? '' : 'warning-title' ?>"><?= h($config['label']) ?></div>
    <div class="confirm-message">
      <?= $confirm_message ?> <strong><?= $detail_text ?></strong>
    </div>

    <?php if ($config['danger']): ?>
    <div class="confirm-warning">
      Cette action est irréversible.
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= h($post_url) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= h($action) ?>">
      <input type="hidden" name="confirmed" value="1">
      <?php foreach ($config['params'] as $param): ?>
        <input type="hidden" name="<?= h($param) ?>" value="<?= h($_GET[$param]) ?>">
      <?php endforeach; ?>

      <div class="confirm-actions">
        <button type="submit" class="btn btn-danger">Confirmer</button>
        <a href="<?= h($cancel_url) ?>" class="btn btn-secondary">Annuler</a>
      </div>
    </form>
  </div>

</div>
<?= render_footer() ?>
</body>
</html>
