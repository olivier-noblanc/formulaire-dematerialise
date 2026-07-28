<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page de confirmation pour les actions destructrices.
 */
final class ConfirmActionController extends BaseController
{
    public function handle(): void
    {
        $action = $_GET['action'] ?? '';
        $from   = $this->safeRelativeUrl($_GET['from'] ?? '');

        $actionsConfig = [
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
            'persona_start' => [
                'label'       => 'Activer le mode persona',
                'description' => 'Voulez-vous vraiment activer le mode persona pour visualiser l\'interface comme',
                'params'      => ['email'],
                'param_label' => 'utilisateur',
                'danger'      => false,
            ],
            'persona_stop' => [
                'label'       => 'Désactiver le mode persona',
                'description' => 'Voulez-vous vraiment quitter le mode persona et revenir en mode administrateur ?',
                'params'      => ['persona_token'],
                'param_label' => '',
                'danger'      => false,
            ],
        ];

        if (!isset($actionsConfig[$action])) {
            $this->redirect('index.php');
        }

        $config = $actionsConfig[$action];

        foreach ($config['params'] as $param) {
            if (empty($_GET[$param])) {
                $this->redirect('index.php');
            }
        }

        // B-02-1 fix (audit 2026-07-26) : requireCsrf() était appelé sur GET, ce qui
        // cassait la page de confirmation en production (CSRF token absent des URLs
        // de redirection qui pointent vers cette page). Le CSRF est vérifié sur le
        // POST final (dans les controllers qui exécutent l'action), pas sur l'affichage
        // de la page de confirmation. En TEST_MODE, requireCsrf est no-op donc le bug
        // était invisible à la suite de tests.
        // $this->security->requireCsrf(); // ← retiré

        $confirmMessage = $config['description'];
        $detailText = '';

        switch ($action) {
            case 'cancel_submission':
                $subId = trim($_GET['submission_id']);
                $detailText = '#' . \App\Core\App::html()->escape($subId) . ' ?';
                break;
            case 'regenerate_token':
                $tokenId = trim($_GET['token_id']);
                $tokInfo = App::getInstance()->get(\App\Repository\TokenRepository::class)->findEmailAndStepLabelById($tokenId);
                if ($tokInfo) {
                    $detailText = App::html()->displayUser($tokInfo['email']) . ' (étape : ' . \App\Core\App::html()->escape($tokInfo['step_label']) . ') ?';
                } else {
                    $detailText = 'token #' . \App\Core\App::html()->escape($tokenId) . ' ?';
                }
                break;
            case 'delete_rule':
                $ruleId = trim($_GET['rule_id']);
                $ruleLabel = App::getInstance()->get(\App\Repository\AlertRepository::class)->findLabelById($ruleId);
                $detailText = $ruleLabel ? '"' . \App\Core\App::html()->escape($ruleLabel) . '" ( #' . \App\Core\App::html()->escape($ruleId) . ') ?' : '#' . \App\Core\App::html()->escape($ruleId) . ' ?';
                break;
            case 'delete_alert_log':
                $logId = trim($_GET['log_id']);
                $detailText = '#' . \App\Core\App::html()->escape($logId) . ' ?';
                break;
            case 'remove_admin':
                $email = $_GET['email'];
                $detailText = \App\Core\App::html()->escape($email) . ' ?';
                break;
            case 'remove_owner':
                $ownerId = trim($_GET['id']);
                $owEmail = App::getInstance()->get(\App\Repository\FormRepository::class)->findOwnerEmailById($ownerId);
                $detailText = $owEmail ? App::html()->displayUser($owEmail) . ' ?' : '#' . \App\Core\App::html()->escape($ownerId) . ' ?';
                break;
            case 'delete_submission':
                $subId = trim($_GET['submission_id']);
                $detailText = '#' . \App\Core\App::html()->escape(substr($subId, 0, 8)) . ' ?';
                break;
            case 'persona_start':
                $targetEmail = trim($_GET['email'] ?? '');
                $detailText = \App\Core\App::html()->escape($targetEmail) . ' ?';
                break;
            case 'persona_stop':
                $detailText = '';
                break;
        }

        $cancelUrl = $from ?: 'index.php';
        $postUrl = $from ?: 'index.php';
        if ($action === 'remove_owner' && isset($_GET['form_id'])) {
            $postUrl = 'index.php?p=admin_forms&form_id=' . urlencode((string) ($_GET['form_id'] ?? '')) . '#owners';
            $cancelUrl = $postUrl;
        } elseif ($action === 'persona_start') {
            $targetEmail = strtolower(trim($_GET['email'] ?? ''));
            $postUrl = 'index.php?p=persona&action=start&email=' . urlencode($targetEmail);
        } elseif ($action === 'persona_stop') {
            $currentToken = $_GET['persona_token'] ?? '';
            $postUrl = 'index.php?p=persona&action=stop&persona_token=' . urlencode($currentToken);
        }

        $content = \App\Render\ConfirmActionRenderer::content($action, $config, $confirmMessage, $detailText, $cancelUrl, $postUrl, $_GET);
        echo $this->renderPage('Confirmation — ' . \App\Core\App::html()->escape($config['label']), 'dashboard', '', $content);
    }

    private function safeRelativeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'index.php';
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            return 'index.php';
        }
        if (preg_match('#^(javascript|data|file):#i', $url)) {
            return 'index.php';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.php/', $url)) {
            return 'index.php';
        }
        return $url;
    }
}
