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
        $pdo  = $this->db->getPdo();
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

        $pendingStmt = $pdo->prepare("
            SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                   t.step_id, t.email,
                   st.label as step_label, st.ordre,
                   s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                   f.label as form_label, f.slug as form_slug
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
            ORDER BY t.sent_at DESC
        ");
        if ($search) {
            $pendingStmt = $pdo->prepare("
                SELECT t.id as token_id, t.token, t.sent_at, t.expires_at, t.relance_count,
                       t.step_id, t.email,
                       st.label as step_label, st.ordre,
                       s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                       f.label as form_label, f.slug as form_slug
                FROM tokens t
                JOIN steps st ON st.id = t.step_id
                JOIN submissions s ON s.id = t.submission_id
                JOIN forms f ON f.id = s.form_id
                WHERE t.email = ? AND t.done_at IS NULL AND s.status = 'en_cours'
                  AND (f.label LIKE ? OR s.data LIKE ?)
                ORDER BY t.sent_at DESC
            ");
            $pendingStmt->execute([$user, '%' . $search . '%', '%' . $search . '%']);
        } else {
            $pendingStmt->execute([$user]);
        }
        $pendingTokens = $pendingStmt->fetchAll(\PDO::FETCH_ASSOC);

        $doneStmt = $pdo->prepare("
            SELECT t.id as token_id, t.done_at, t.sent_at,
                   st.label as step_label, st.ordre,
                   s.id as submission_id, s.data, s.submitted_at, s.status as sub_status,
                   f.label as form_label, f.slug as form_slug
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.email = ? AND t.done_at IS NOT NULL
            ORDER BY t.done_at DESC
            LIMIT 50
        ");
        $doneStmt->execute([$user]);
        $doneTokens = $doneStmt->fetchAll(\PDO::FETCH_ASSOC);

        $pendingCount = count($pendingTokens);
        $doneCount = count($doneTokens);
        $activeTab = $_GET['tab'] ?? 'pending';

        $allStepsBySub = [];
        if (!empty($pendingTokens)) {
            $pendingSubIds = array_values(array_unique(array_column($pendingTokens, 'submission_id')));
            $psph = implode(',', array_fill(0, count($pendingSubIds), '?'));
            $batchStepsStmt = $pdo->prepare("
                SELECT s.id as submission_id, st.id, st.label, st.ordre,
                       GROUP_CONCAT(t2.done_at, '|') as dones
                FROM submissions s
                JOIN steps st ON st.form_id = s.form_id AND st.actif = 1
                LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = s.id
                WHERE s.id IN ($psph)
                GROUP BY s.id, st.id
                ORDER BY s.id, st.ordre
            ");
            $batchStepsStmt->execute($pendingSubIds);
            foreach ($batchStepsStmt->fetchAll(\PDO::FETCH_ASSOC) as $asRow) {
                $allStepsBySub[$asRow['submission_id']][] = $asRow;
            }
        }

        $myVdStmt = $pdo->prepare("SELECT svd.*, s.form_id, f.label as form_label
                                    FROM submission_validator_data svd
                                    JOIN submissions s ON s.id = svd.submission_id
                                    JOIN forms f ON f.id = s.form_id
                                    WHERE svd.filled_by_email = ?
                                    ORDER BY svd.filled_at DESC
                                    LIMIT 50");
        $myVdStmt->execute([$user]);
        $myVdRows = $myVdStmt->fetchAll(\PDO::FETCH_ASSOC);

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
