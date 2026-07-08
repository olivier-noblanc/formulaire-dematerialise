<?php
// confirm_action.php — Page de confirmation pour les actions destructrices
// Remplace les boîtes de dialogue JavaScript confirm() par une page serveur
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

/**
 * Valide qu'une URL est relative (interne au site) — pas d'open redirect.
 * Accepte : "index.php?p=submission_view&id=5", "index.php?p=admin_forms&form_id=xxx#owners"
 * Rejette : "https://evil.com", "//evil.com", "javascript:alert(1)", "/etc/passwd"
 */
function safe_relative_url(string $url): string {
    $url = trim($url);
    if ($url === '') return 'index.php';
    // Rejeter les URLs absolues (http://, https://, //, javascript:, data:)
    if (preg_match('#^(https?:)?//#i', $url)) return 'index.php';
    if (preg_match('#^(javascript|data|file):#i', $url)) return 'index.php';
    // Accepter uniquement les URLs qui commencent par un nom de fichier PHP valide
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php/', $url)) return 'index.php';
    return $url;
}

$action = $_GET['action'] ?? '';
$from   = safe_relative_url($_GET['from'] ?? '');

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
    'remove_owner' => [
        'label'       => 'Retirer un propriétaire de formulaire',
        'description' => 'Voulez-vous vraiment retirer ce propriétaire',
        'params'      => ['id', 'form_id'],
        'param_label' => 'propriétaire',
        'danger'      => true,
    ],
    'delete_submission' => [
        'label'       => 'Supprimer définitivement une demande',
        'description' => 'Voulez-vous vraiment supprimer DÉFINITIVEMENT cette demande ? Cette action est irréversible. Toutes les données (tokens, pièces jointes, historique) seront perdues.',
        'params'      => ['submission_id'],
        'param_label' => 'soumission',
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
        $sub_id = trim($_GET['submission_id']);
        $detail_text = '#' . h($sub_id) . ' ?';
        break;
    case 'regenerate_token':
        $token_id = trim($_GET['token_id']);
        // Récupérer l'email associé au token
        $pdo = App::db()->getPdo();
        $tok_stmt = $pdo->prepare("SELECT t.email, st.label as step_label FROM tokens t JOIN steps st ON st.id = t.step_id WHERE t.id = ?");
        $tok_stmt->execute([$token_id]);
        $tok_info = $tok_stmt->fetch(PDO::FETCH_ASSOC);
        if ($tok_info) {
            $detail_text = display_user($tok_info['email']) . ' (étape : ' . h($tok_info['step_label']) . ') ?';
        } else {
            $detail_text = 'token #' . h($token_id) . ' ?';
        }
        break;
    case 'delete_rule':
        $rule_id = trim($_GET['rule_id']);
        // Récupérer le nom de la règle
        $pdo = App::db()->getPdo();
        $rule_stmt = $pdo->prepare("SELECT label FROM alert_rules WHERE id = ?");
        $rule_stmt->execute([$rule_id]);
        $rule_label = $rule_stmt->fetchColumn();
        $detail_text = $rule_label ? '"' . h((string)$rule_label) . '" ( #' . h((string)$rule_id) . ') ?' : '#' . h((string)$rule_id) . ' ?';
        break;
    case 'delete_alert_log':
        $log_id = trim($_GET['log_id']);
        $detail_text = '#' . h($log_id) . ' ?';
        break;
    case 'remove_admin':
        $email = $_GET['email'];
        $detail_text = h($email) . ' ?';
        break;
    case 'remove_owner':
        $owner_id = trim($_GET['id']);
        $pdo = App::db()->getPdo();
        $ow_stmt = $pdo->prepare("SELECT email FROM form_owners WHERE id = ?");
        $ow_stmt->execute([$owner_id]);
        $ow_email = $ow_stmt->fetchColumn();
        $detail_text = $ow_email ? display_user((string)$ow_email) . ' ?' : '#' . h((string)$owner_id) . ' ?';
        break;
    case 'delete_submission':
        $sub_id = trim($_GET['submission_id']);
        $detail_text = '#' . h(substr($sub_id, 0, 8)) . ' ?';
        break;
}

// URL de retour pour le bouton Annuler — $from déjà validé par safe_relative_url()
$cancel_url = $from ?: 'index.php';
// Ne PAS utiliser HTTP_REFERER — c'est une entrée utilisateur non fiable
// qui peut être utilisée pour un open redirect.

// Construire l'URL de destination pour le POST (la page d'origine)
// $from déjà validé par safe_relative_url() — c'est une URL interne sûre.
$post_url = $from ?: 'index.php';
// Cas spécifique remove_owner : rediriger vers admin_forms.php
if ($action === 'remove_owner' && isset($_GET['form_id'])) {
    $post_url = 'index.php?p=admin_forms&form_id=' . urlencode($_GET['form_id']) . '#owners';
    $cancel_url = $post_url;
}
?>
<?php
$page_css = '';
ob_start();
?>

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
      <?= App::security()->csrfField() ?>
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

<?php
$content = (string)ob_get_clean();
echo render_page('Confirmation — ' . h($config['label']), 'dashboard', $page_css, $content);
