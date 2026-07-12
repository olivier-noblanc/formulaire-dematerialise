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

        $successMsg = '';
        $errorMsg = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'add_rule') {
                $formId = trim($_POST['form_id'] ?? '');
                $daysBefore = (int) ($_POST['days_before'] ?? 5);
                $conditionType = trim($_POST['condition_type'] ?? 'steps_incomplete');
                $notifyWho = trim($_POST['notify_who'] ?? 'admin');
                $label = trim($_POST['label'] ?? '');
                $customEmail = trim($_POST['custom_email'] ?? '');

                if ($formId === '' || $formId === '0') {
                    $errorMsg = 'Veuillez sélectionner un formulaire.';
                } elseif ($daysBefore < 0) {
                    $errorMsg = 'Le nombre de jours doit être positif ou zéro.';
                } elseif ($label === '' || $label === '0') {
                    $errorMsg = 'Le libellé de la règle est obligatoire.';
                } else {
                    if ($notifyWho === 'custom' && ($customEmail !== '' && $customEmail !== '0')) {
                        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
                            $errorMsg = 'L\'adresse email personnalisée est invalide.';
                        } else {
                            $notifyWho = $customEmail;
                        }
                    }

                    if ($errorMsg === '') {
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
            } elseif ($action === 'update_rule') {
                $ruleId = trim($_POST['rule_id'] ?? '');
                $daysBefore = (int) ($_POST['days_before'] ?? 5);
                $conditionType = trim($_POST['condition_type'] ?? 'steps_incomplete');
                $notifyWho = trim($_POST['notify_who'] ?? 'admin');
                $label = trim($_POST['label'] ?? '');
                $customEmail = trim($_POST['custom_email'] ?? '');
                $actif = isset($_POST['actif']) ? 1 : 0;

                if ($daysBefore < 0) {
                    $errorMsg = 'Le nombre de jours doit être positif ou zéro.';
                } elseif ($label === '' || $label === '0') {
                    $errorMsg = 'Le libellé de la règle est obligatoire.';
                } else {
                    if ($notifyWho === 'custom' && ($customEmail !== '' && $customEmail !== '0')) {
                        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
                            $errorMsg = 'L\'adresse email personnalisée est invalide.';
                        } else {
                            $notifyWho = $customEmail;
                        }
                    }

                    if ($errorMsg === '') {
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
            } elseif ($action === 'delete_rule') {
                $ruleId = trim($_POST['rule_id'] ?? '');
                try {
                    $this->alertRepo->deleteRule($ruleId);
                    App::audit()->log('alert_rule_delete', 'rule:' . $ruleId, 'Règle d\'alerte supprimée');
                    $successMsg = 'Règle d\'alerte supprimée.';
                } catch (\Exception $e) {
                    error_log('alert_rule_delete error: ' . $e->getMessage());
                    $errorMsg = 'Une erreur technique est survenue.';
                }
            } elseif ($action === 'update_deadline_field') {
                $formId = trim($_POST['form_id'] ?? '');
                $deadlineField = trim($_POST['deadline_field'] ?? '');

                if ($formId !== '' && $formId !== '0') {
                    try {
                        $this->formRepo->setDeadlineField($formId, $deadlineField);
                        App::audit()->log('deadline_field_update', 'form:' . $formId, 'Champ deadline mis à jour : ' . ($deadlineField ?: '(aucun)'));
                        $successMsg = 'Champ date limite mis à jour pour le formulaire.';
                    } catch (\Exception $e) {
                        error_log('deadline_field_update error: ' . $e->getMessage());
                        $errorMsg = 'Une erreur technique est survenue.';
                    }
                }
            } elseif ($action === 'delete_alert_log') {
                $retentionDays = (int) App::settings()->get('alert_log_retention_days', '90');
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
        foreach ($forms as $form) {
            $dateFieldsByForm[$form['id']] = $this->formRepo->getDateFields($form['id']);
        }

        $content = \App\Render\AdminAlertsRenderer::content(
            $successMsg,
            $errorMsg,
            $forms,
            $rules,
            $alertLogs,
            $lastAlertCheck,
            $editRuleId,
            $dateFieldsByForm,
        );
        echo $this->renderPage('Alertes', 'alerts', '', $content);
    }

}
