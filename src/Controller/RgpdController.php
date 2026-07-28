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
        App::auth()->requireAdminEffective();

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
                $retention = (int) ($_POST['retention_months'] ?? 24);
                if ($retention < 1) {
                    $retention = 1;
                }
                if ($retention > 120) {
                    $retention = 120;
                }
                $this->settings->set('legal_mentions', $legalText, App::auth()->getUser());
                $this->settings->set('retention_months', (string) $retention, App::auth()->getUser());
                App::audit()->log('rgpd_settings', 'settings', 'Mentions légales et durée de conservation mises à jour');
                $successMsg = 'Mentions légales et durée de conservation mises à jour.';
            }

            // Export des données d'un utilisateur
            if ($action === 'export_user') {
                $email = validate_email($_POST['export_email'] ?? '');
                if ($email === '' || $email === '0') {
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
                        $safeEmail = preg_replace('/[^a-zA-Z0-9_-]/', '_', $email);
                        header('Content-Disposition: attachment; filename="rgpd_export_' . $safeEmail . '_' . date('Ymd_His') . '.json"');
                        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        exit;
                    }
                }
            }

            // Suppression des données d'un utilisateur
            if ($action === 'delete_user') {
                $email = validate_email($_POST['delete_email'] ?? '');
                $confirmed = !empty($_POST['confirmed']);
                if ($email === '' || $email === '0') {
                    $errorMsg = 'Adresse email invalide.';
                } elseif (!$confirmed) {
                    $errorMsg = 'Veuillez confirmer la suppression en cochant la case.';
                } elseif ($email === App::auth()->getUser()) {
                    $errorMsg = 'Vous ne pouvez pas supprimer vos propres données.';
                } else {
                    $this->db->enableForeignKeys();

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
                    $months = (int) $this->settings->get('retention_months', '24');

                    $this->db->enableForeignKeys();

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
        $retentionMonths = (int) $this->settings->get('retention_months', '24');
        $legalMentions = $this->settings->get('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données. Contact : ' . $this->settings->get('rgpd_contact', 'CIL DREETS') . '.');

        $totalSubmissions = $this->submissionRepo->countAll();
        $totalAttachments = $this->attachmentRepo->countAll();
        $totalAudit = $this->auditRepo->countAll();
        $oldSubmissions = $this->submissionRepo->countOldByRetention($retentionMonths);
        $dbSize = new \App\Webhook\WebhookService()->getDbSize();

        $pageCss = '';
        $content = \App\Render\RgpdRenderer::content(
            $successMsg,
            $errorMsg,
            $infoMsg,
            $totalSubmissions,
            $totalAttachments,
            $totalAudit,
            $dbSize,
            $oldSubmissions,
            $retentionMonths,
            $legalMentions,
            $this->settings->get('email_domain', 'exemple.invalid')
        );
        echo $this->renderPage('RGPD', 'rgpd', $pageCss, $content);
    }
}
