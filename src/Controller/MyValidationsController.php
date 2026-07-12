<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page "Mes validations" (dashboard validateur).
 */
final class MyValidationsController extends BaseController
{
    public function handle(): void
    {
        $user = App::auth()->getUser();
        $search = trim($_GET['search'] ?? '');

        $delegationMsg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delegate_token') {
            $this->security->requireCsrf();
            $tokenId = trim($_POST['token_id'] ?? '');
            $delegateTo = trim($_POST['delegate_to'] ?? '');
            $delegateReason = trim($_POST['delegate_reason'] ?? '');
            $result = App::token()->delegate($tokenId, $delegateTo, $delegateReason);
            $delegationMsg = $result['message'];
        }

        $tokenRepo = App::getInstance()->get(\App\Repository\TokenRepository::class);
        $pendingTokens = $tokenRepo->findPendingByEmail($user, $search);
        $doneTokens = $tokenRepo->findDoneByEmail($user);

        $pendingCount = count($pendingTokens);
        $doneCount = count($doneTokens);
        $activeTab = $_GET['tab'] ?? 'pending';

        $allStepsBySub = [];
        if (!empty($pendingTokens)) {
            $pendingSubIds = array_values(array_unique(array_column($pendingTokens, 'submission_id')));
            $allStepsBySub = $tokenRepo->findStepsBySubmissionIds($pendingSubIds);
        }

        $myVdRows = App::getInstance()->get(\App\Repository\SubmissionRepository::class)->findValidatorDataByEmail($user);

        $content = \App\Render\MyValidationsRenderer::content(
            $pendingTokens,
            $doneTokens,
            $activeTab,
            $pendingCount,
            $doneCount,
            $search,
            $delegationMsg,
            $allStepsBySub,
            $myVdRows,
            $user,
        );
        echo $this->renderPage('Mes validations', 'mes_validations', '', $content);
    }
}
