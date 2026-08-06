<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Gestion des formulaires (admin).
 */
final class AdminFormsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdminEffective();

        $formId = trim($_GET['form_id'] ?? '');
        $editStepId = trim($_GET['edit_step'] ?? '');
        $editFieldId = trim($_GET['edit_field'] ?? '');

        try {
            if ($formId !== '' && $formId !== '0') {
                $formId = validate_input($formId, 'uuid');
            }
            if ($editStepId !== '' && $editStepId !== '0') {
                $editStepId = validate_input($editStepId, 'uuid');
            }
            if ($editFieldId !== '' && $editFieldId !== '0') {
                $editFieldId = validate_input($editFieldId, 'uuid');
            }
        } catch (\InvalidArgumentException) {
            // @silent-ok: fallback logs security event and shows error page (which exits)
            App::audit()->securityLog('invalid_admin_forms_id', 'form_id=' . substr((string) $formId, 0, 20) . ' edit_step=' . substr((string) $editStepId, 0, 20) . ' edit_field=' . substr($editFieldId, 0, 20));
            new \App\Render\ErrorRenderer()->errorPage(400, 'Paramètre invalide', 'Un des identifiants fournis est invalide.', 'Vérifiez l\'URL et réessayez.');
        }

        $rawAction = $_POST['action'] ?? '';
        $action = is_string($rawAction) ? $rawAction : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
        }

        $errorMsg       = '';
        $successMsg     = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action !== '') {
            $result = AdminFormsHandlers::dispatch($action, (string) $formId);
            if ($result !== null) {
                if (isset($result['json_output']) && isset($result['filename'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                    echo $result['json_output'];
                    exit;
                }
                if (isset($result['redirect'])) {
                    header('Location: ' . $result['redirect']);
                    exit;
                }
                if (isset($result['error'])) {
                    $errorMsg       = $result['error'];
                }
                if (isset($result['success'])) {
                    $successMsg     = $result['success'];
                }
                if (isset($result['form_id']) && is_string($result['form_id'])) {
                    $formId         = $result['form_id'];
                }
            }
        }

        $selectedForm = null;
        $allForms = App::getInstance()->get(\App\Repository\FormRepository::class)->findAll();
        if ($formId !== '' && $formId !== '0') {
            $selectedForm = App::getInstance()->get(\App\Repository\FormRepository::class)->findById((string) $formId);
        }

        render_admin_forms_page([
            'forms'      => $allForms,
            'form_id'    => (string) $formId,
            'form'       => $selectedForm,
            'error_msg'  => $errorMsg,
            'success_msg' => $successMsg,
        ]);
    }
}
