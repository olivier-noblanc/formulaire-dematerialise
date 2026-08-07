<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Enum\SubmissionField;
use App\Forms\SubmissionData;

/**
 * Rendu HTML de la page "Mes demandes" pour l'agent connecté.
 */
final class MySubmissionsRenderer
{
    /**
     * Génère le HTML de la liste des soumissions de l'utilisateur.
     *
     * @param array<int, array<string, mixed>> $submissions
     * @param array<int, array{slug: string, label: string}> $activeForms
     */
    public static function content(
        array $submissions,
        string $statusFilter,
        int $totalCount,
        int $enCoursCount,
        int $valideCount,
        int $refuseCount,
        int $annuleCount,
        string $search,
        array $activeForms,
    ): string {
        $html = '';

        $html .= '  <h1><span aria-hidden="true">📋</span> Mes demandes</h1>' . "\n";

        if ($totalCount > 0) {
            $activeTous    = $statusFilter === 'tous' ? ' active' : '';
            $activeEnCours = $statusFilter === SubmissionStatus::EnCours->value ? ' active' : '';
            $activeValide  = $statusFilter === SubmissionStatus::Valide->value ? ' active' : '';
            $activeRefuse  = $statusFilter === SubmissionStatus::Refuse->value ? ' active' : '';
            $activeAnnule  = $statusFilter === SubmissionStatus::Annule->value ? ' active' : '';

            $html .= <<<HTML
                  <div class="stats">
                    <a href="index.php?p=my_submissions&statut=tous" class="stat{$activeTous}"><strong>{$totalCount}</strong><span>Total</span></a>
                    <a href="index.php?p=my_submissions&statut=en_cours" class="stat en-cours{$activeEnCours}"><strong>{$enCoursCount}</strong><span>En cours</span></a>
                    <a href="index.php?p=my_submissions&statut=valide" class="stat valide{$activeValide}"><strong>{$valideCount}</strong><span>Validées</span></a>
                    <a href="index.php?p=my_submissions&statut=refuse" class="stat refuse{$activeRefuse}"><strong>{$refuseCount}</strong><span>Refusées</span></a>
                    <a href="index.php?p=my_submissions&statut=annule" class="stat annule{$activeAnnule}"><strong>{$annuleCount}</strong><span>Annulées</span></a>
                  </div>

                HTML;

            $html .= '  <div class="mb-15">' . "\n";
            $html .= '    ' . new FormRenderer()->searchBar('index.php?p=my_submissions', $search, 'Rechercher...', ['statut' => $statusFilter]) . "\n";
            $html .= '  </div>' . "\n";
        }

        if ($submissions === []) {
            $html .= '    <div class="empty-state">' . "\n";
            $html .= '      <div class="empty-icon" aria-hidden="true">📝</div>' . "\n";
            $html .= '      <p>Vous n\'avez encore soumis aucune demande.</p>' . "\n";
            if ($activeForms !== []) {
                $html .= '        <p class="caption-8">Formulaires disponibles :</p>' . "\n";
                foreach ($activeForms as $activeForm) {
                    $slug  = App::html()->escape($activeForm['slug']);
                    $label = App::html()->escape(self::simplifyLabel($activeForm['label']));
                    $html .= "        <a href=\"index.php?p=form&f={$slug}\" class=\"btn btn-primary u-m-025rem\">{$label}</a>\n";
                }
            }
            $html .= '    </div>' . "\n";
        } else {
            foreach ($submissions as $submission) {
                $data   = json_decode($submission['data'], true);
                $status = $submission['status'] ?? SubmissionStatus::EnCours->value;

                $statusLabel = match ($status) {
                    SubmissionStatus::Valide->value  => '✓ Validée',
                    SubmissionStatus::Refuse->value  => '❌ Refusée',
                    SubmissionStatus::Annule->value  => '🗑 Annulée',
                    default   => '⏳ En cours',
                };
                $badgeCls = match ($status) {
                    SubmissionStatus::Valide->value  => 'badge-valide',
                    SubmissionStatus::Refuse->value  => 'badge-refuse',
                    SubmissionStatus::Annule->value  => 'badge-annule',
                    default   => 'badge-en-cours',
                };

                $deadlineField = $submission['deadline_field'] ?? '';
                $deadlineVal   = $deadlineField ? ($data[$deadlineField] ?? '') : '';
                $deadlineBadge = '';
                if (!in_array($deadlineVal, ['', null, '0'], true) && $status === SubmissionStatus::EnCours->value) {
                    $dl     = calculate_deadline_urgency($deadlineVal, $status);
                    $dlDays = $dl['days_left'];
                    if ($dlDays !== null) {
                        if ($dlDays < 0) {
                            $deadlineBadge = '<span class="deadline-badge overdue"><span aria-hidden="true">🚨</span> J+' . abs($dlDays) . '</span>';
                        } elseif ($dlDays <= 2) {
                            $deadlineBadge = '<span class="deadline-badge urgent"><span aria-hidden="true">⚠️</span> J-' . $dlDays . '</span>';
                        } elseif ($dlDays <= 5) {
                            $deadlineBadge = '<span class="deadline-badge ok"><span aria-hidden="true">📅</span> J-' . $dlDays . '</span>';
                        }
                    }
                }

                $pct     = (int) ($submission['progress_pct'] ?? 0);
                $fillCls = $pct === 100 ? 'complete' : 'in-progress';

                $subId       = urlencode((string) ($submission['id'] ?? ''));
                $formLabel   = App::html()->escape(self::simplifyLabel($submission['form_label']));
                $submittedAt = App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($submission['submitted_at'] ?? ''))));
                $prenomNom   = App::html()->escape(SubmissionData::get($data, SubmissionField::PRENOM) . ' ' . SubmissionData::get($data, SubmissionField::NOM));
                $formSlug    = App::html()->escape($submission['form_slug']);
                $maxPct      = max($pct, 3);
                $widthCls    = 'ipw-' . (int) $maxPct;
                \App\Core\App::css()->rule($widthCls, "width:{$maxPct}%;");

                $progressDone = (int) ($submission['progress_done'] ?? 0);
                $progressTotal = (int) ($submission['progress_total'] ?? 0);

                $html .= <<<HTML
                        <div class="sub-card">
                          <a href="index.php?p=submission_view&id={$subId}" class="u-col-tex-2">
                          <div class="sub-card-header">
                            <div>
                              <div class="sub-card-title">{$formLabel} {$deadlineBadge}</div>
                              <div class="sub-card-date">Soumis le {$submittedAt} — {$prenomNom}</div>
                            </div>
                            <span class="badge {$badgeCls}">{$statusLabel}</span>
                          </div>
                          </a>
                          <div class="sub-card-body">
                            <div class="inline-progress">
                              <div class="inline-progress-bar">
                                <div class="inline-progress-fill {$fillCls} {$widthCls}"></div>
                              </div>
                              <div class="inline-progress-text">{$progressDone}/{$progressTotal} étapes ({$pct}%)</div>
                            </div>

                            <div class="timeline-compact">
                    HTML;

                foreach ($submission['workflow_steps'] ?? [] as $ws) {
                    $cls  = match ($ws['step_status'] ?? '') {
                        'validated' => 'done',
                        'current'   => 'active',
                        default     => 'waiting',
                    };
                    $icon = match ($ws['step_status'] ?? '') {
                        'validated' => '✓',
                        'current'   => '⏳',
                        default     => '○',
                    };
                    $stepLabel = App::html()->escape($ws['step_label'] ?? '');
                    $html .= "            <div class=\"tl-step {$cls}\">\n";
                    $html .= "              <span class=\"tl-icon\" aria-hidden=\"true\">{$icon}</span>\n";
                    $html .= "              <span class=\"tl-label\">{$stepLabel}</span>\n";
                    if ((bool)($ws['step_detail'])) {
                        $html .= "                <span class=\"tl-detail\">{$ws['step_detail']}</span>\n";
                    }
                    $html .= "            </div>\n";
                }

                $html .= "        </div>\n";

                // Refusal box
                if ($status === SubmissionStatus::Refuse->value && SubmissionData::has($data, SubmissionField::VALIDATIONS)) {
                    foreach ($data[SubmissionField::VALIDATIONS->value] as $v) {
                        if ($v['action'] === ValidationAction::Refuser->value) {
                            $refUser  = App::html()->displayUser($v['email']);
                            $refStep  = App::html()->escape($v['step_label']);
                            $html .= "          <div class=\"refusal-box\">\n";
                            $html .= "            <strong>Refusé par :</strong> {$refUser} ({$refStep})\n";
                            if ((bool)($v['commentaire'])) {
                                $refComment = App::html()->escape($v['commentaire']);
                                $html .= "            <br><strong>Motif :</strong> {$refComment}\n";
                            }
                            $html .= "          </div>\n";
                            break;
                        }
                    }
                }
                // Validation box
                elseif ($status === SubmissionStatus::Valide->value && SubmissionData::has($data, SubmissionField::VALIDATIONS)) {
                    $lastValidator = null;
                    foreach ($data[SubmissionField::VALIDATIONS->value] as $v) {
                        if ($v['action'] === ValidationAction::Valider->value) {
                            $lastValidator = $v;
                        }
                    }
                    $html .= "          <div class=\"validation-box\">\n";
                    if ($lastValidator !== null) {
                        $valUser  = App::html()->displayUser($lastValidator['email']);
                        $valStep  = App::html()->escape($lastValidator['step_label']);
                        $html .= "            <strong>Validée par :</strong> {$valUser} ({$valStep})\n";
                        if ((bool)($lastValidator['commentaire'])) {
                            $valComment = App::html()->escape($lastValidator['commentaire']);
                            $html .= "            <br><strong>Commentaire :</strong> {$valComment}\n";
                        }
                    } else {
                        $html .= "            <strong>Demande validée</strong> — circuit complet\n";
                    }
                    $html .= "          </div>\n";
                }

                $html .= <<<HTML
                            <div class="card-actions">
                              <a href="index.php?p=submission_view&id={$subId}" class="btn btn-primary u-fon-2"><span aria-hidden="true">👁</span> Voir le détail</a>
                              <a href="index.php?p=form&f={$formSlug}" class="btn btn-secondary u-fon-2">Nouvelle demande</a>
                            </div>
                          </div>
                        </div>
                    HTML;
            }
        }

        return $html;
    }

    private static function simplifyLabel(string $label): string
    {
        $map = [
            'Accès SI'    => 'Demande d\'accès aux outils informatiques',
            'Onboarding'  => 'Accueil d\'un nouvel agent',
            'Outboarding' => 'Départ d\'un agent',
        ];
        $trimmed = trim($label);
        foreach ($map as $jargon => $clair) {
            if (strcasecmp($trimmed, $jargon) === 0) {
                $label = $clair;
                break;
            }
        }
        return App::html()->tJargon($label);
    }
}
