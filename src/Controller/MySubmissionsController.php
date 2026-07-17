<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\SubmissionStatus;

/**
 * Contrôleur de la page "Mes demandes" pour l'agent connecté.
 */
final class MySubmissionsController extends BaseController
{
    public function handle(): void
    {
        $user = App::auth()->getUser();
        $search = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['statut'] ?? 'tous';

        $where = ['s.submitted_by = ?'];
        $params = [$user];
        if ($search !== '' && $search !== '0') {
            $where[] = '(f.label LIKE ? OR s.data LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        if ($statusFilter === SubmissionStatus::EnCours->value) {
            $where[] = "s.status = 'en_cours'";
        } elseif ($statusFilter === SubmissionStatus::Valide->value) {
            $where[] = "s.status = 'valide'";
        } elseif ($statusFilter === SubmissionStatus::Refuse->value) {
            $where[] = "s.status = 'refuse'";
        } elseif ($statusFilter === SubmissionStatus::Annule->value) {
            $where[] = "s.status = 'annule'";
        }
        $whereSql = implode(' AND ', $where);

        $submissions = $this->submissionRepo->findPaginatedBySubmitter($user, $whereSql, $params, 0, 0);

        $workflowStepsByForm = [];
        $tokensBySub = [];
        if ($submissions !== []) {
            $formIds = array_values(array_unique(array_column($submissions, 'form_id')));
            if ($formIds !== []) {
                $workflowStepsByForm = $this->formRepo->getWorkflowStepsByFormIds($formIds);
            }

            $subIds = array_column($submissions, 'id');
            $tokensBySub = $this->tokenRepo->findBySubmissionIds($subIds);
        }

        foreach ($submissions as &$sub) {
            $sid = $sub['id'];
            $sub['workflow_steps'] = $workflowStepsByForm[$sub['form_id']] ?? [];
            $sub['tokens'] = $tokensBySub[$sid] ?? [];

            $tokensByStep = [];
            foreach ($sub['tokens'] as $tok) {
                $tokensByStep[$tok['step_id']][] = $tok;
            }

            foreach ($sub['workflow_steps'] as &$ws) {
                $stepId = $ws['step_id'];
                if (!isset($tokensByStep[$stepId])) {
                    $ws['step_status'] = 'upcoming';
                    $ws['step_detail'] = '';
                } else {
                    $allDone = true;
                    $detailParts = [];
                    foreach ($tokensByStep[$stepId] as $tok) {
                        if (!empty($tok['done_at'])) {
                            $detailParts[] = App::html()->displayUser($tok['email']) . ' <span aria-hidden="true">✓</span>';
                        } else {
                            $allDone = false;
                            $detailParts[] = App::html()->displayUser($tok['email']) . ' <span aria-hidden="true">⏳</span>';
                        }
                    }
                    $ws['step_status'] = $allDone ? 'validated' : 'current';
                    $ws['step_detail'] = implode('<br>', $detailParts);
                }
            }
            unset($ws);

            $total = count($sub['workflow_steps']);
            $done = count(array_filter($sub['workflow_steps'], fn($s) => $s['step_status'] === 'validated'));
            $sub['progress_pct'] = $total > 0 ? round(($done / $total) * 100) : 0;
            $sub['progress_done'] = $done;
            $sub['progress_total'] = $total;
        }
        unset($sub);

        $statusCounts = $this->submissionRepo->getStatusCountsBySubmitter($user);
        $totalCount = 0;
        $enCoursCount = 0;
        $valideCount = 0;
        $refuseCount = 0;
        $annuleCount = 0;
        foreach ($statusCounts as $row) {
            $totalCount += (int) $row['cnt'];
            if ($row['status'] === SubmissionStatus::Valide->value) {
                $valideCount = (int) $row['cnt'];
            } elseif ($row['status'] === SubmissionStatus::Refuse->value) {
                $refuseCount = (int) $row['cnt'];
            } elseif ($row['status'] === SubmissionStatus::Annule->value) {
                $annuleCount = (int) $row['cnt'];
            } else {
                $enCoursCount += (int) $row['cnt'];
            }
        }
        unset($row);

        $activeForms = $this->formRepo->findActiveSlugsAndLabels();

        $content = \App\Render\MySubmissionsRenderer::content(
            $submissions,
            $statusFilter,
            $totalCount,
            $enCoursCount,
            $valideCount,
            $refuseCount,
            $annuleCount,
            $search,
            $activeForms,
        );

        echo $this->renderPage('Mes demandes', 'mes_demandes', '', $content);
    }
}
