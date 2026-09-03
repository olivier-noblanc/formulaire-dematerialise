<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Forms\SubmissionData;

use function Safe\json_decode;

/**
 * Rendu de la page « Mes validations » (dashboard validateur).
 */
final class MyValidationsRenderer
{
    /**
     * Retourne le HTML complet du contenu principal de la page Mes validations.
     *
     * @param array<int, array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $pendingTokens
     * @param array<int, array{token_id: string, done_at: string|null, sent_at: string|null, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $doneTokens
     * @param array<string, list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}>> $allStepsBySub keyed by submission_id
     * @param array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null, form_id: string, form_label: string}> $myVdRows
     * @param string $user current user email
     */
    public static function content(
        array $pendingTokens,
        array $doneTokens,
        string $activeTab,
        int $pendingCount,
        int $doneCount,
        string $search,
        string $delegationMsg,
        array $allStepsBySub,
        array $myVdRows,
        string $user,
    ): string {
        $htmlService = App::html();
        $pendingActive = $activeTab === 'pending' ? ' active' : '';
        $doneActive = $activeTab === 'done' ? ' active' : '';

        $html = '';
        $html .= '<h1><span aria-hidden="true">✅</span> Mes validations</h1>' . "\n";
        $html .= '<div class="stats">' . "\n";
        $html .= '  <a href="index.php?p=my_validations&tab=pending" class="stat warning' . $pendingActive . '"><strong>' . $pendingCount . '</strong><span>En attente</span></a>' . "\n";
        $html .= '  <a href="index.php?p=my_validations&tab=done" class="stat success' . $doneActive . '"><strong>' . $doneCount . '</strong><span>Traitées</span></a>' . "\n";
        $html .= '</div>' . "\n";

        if ($delegationMsg !== '' && $delegationMsg !== '0') {
            $html .= '<div class="msg-info" role="status" aria-live="polite">' . $htmlService->escape($delegationMsg) . '</div>' . "\n";
        }

        $html .= new FormRenderer()->searchBar('index.php?p=my_validations', $search, 'Rechercher un formulaire...', ['tab' => $activeTab]) . "\n";

        if ($activeTab === 'pending') {
            $html .= self::pendingTab($pendingTokens, $allStepsBySub);
        } else {
            $html .= self::doneTab($doneTokens, $user);
        }

        return $html . self::myVdSection($myVdRows);
    }

    /**
     * @param array<int, array{token_id: string, token: string, sent_at: string|null, expires_at: string|null, relance_count: int, step_id: string, email: string, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $pendingTokens
     * @param array<string, list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}>> $allStepsBySub
     */
    private static function pendingTab(array $pendingTokens, array $allStepsBySub): string
    {
        $htmlService = App::html();
        $html = '<div id="tab-pending">' . "\n";

        if ($pendingTokens === []) {
            $html .= '  <div class="empty-state">' . "\n";
            $html .= '    <div class="empty-icon" aria-hidden="true">🎉</div>' . "\n";
            $html .= '    <p>Aucune validation en attente — vous êtes à jour !</p>' . "\n";
            $html .= '  </div>' . "\n";
        } else {
            foreach ($pendingTokens as $pendingToken) {
                $data = json_decode((string) ($pendingToken['data'] ?? '{}'), true) ?? [];
                $expired = (bool) ($pendingToken['expires_at']) && strtotime($pendingToken['expires_at']) < time();
                $nomAgent = $htmlService->escape(
                    SubmissionData::get($data, SubmissionField::PRENOM) . ' ' . SubmissionData::get($data, SubmissionField::NOM)
                );
                $allSteps = $allStepsBySub[$pendingToken['submission_id']] ?? [];

                $cardClass = $expired ? 'expired' : 'pending';
                $html .= '<div class="validation-card ' . $cardClass . '">' . "\n";
                $html .= '  <div class="vc-header">' . "\n";
                $html .= '    <div>' . "\n";
                $html .= '      <div class="vc-title">' . $htmlService->escape($pendingToken['form_label']) . ' — Étape ' . (int) $pendingToken['ordre'] . ' : ' . $htmlService->escape($pendingToken['step_label']) . '</div>' . "\n";
                $html .= '      <div class="vc-meta">' . "\n";
                $html .= '        Agent : <strong>' . ($nomAgent ?? $htmlService->escape($pendingToken['data'] ? 'Inconnu' : '')) . '</strong>' . "\n";
                if (SubmissionData::has($data, SubmissionField::AFFECTATION)) {
                    $html .= ' — ' . $htmlService->escape(SubmissionData::get($data, SubmissionField::AFFECTATION));
                }
                $html .= '<br>Soumis le ' . $htmlService->escape($htmlService->formatDateTimeFr((string) ($pendingToken['submitted_at'] ?? ''))) . "\n";
                if ($pendingToken['relance_count'] > 0) {
                    $html .= '<br><span class="text-warning">Relance(s) : ' . (int) $pendingToken['relance_count'] . '</span>' . "\n";
                }
                $html .= '      </div>' . "\n";
                $html .= '    </div>' . "\n";

                if ($expired) {
                    $html .= '    <span class="expired-badge"><span aria-hidden="true">⏰</span> Expiré</span>' . "\n";
                } else {
                    $html .= '    <span class="badge badge-warn"><span aria-hidden="true">⏳</span> En attente de votre validation</span>' . "\n";
                }
                $html .= '  </div>' . "\n";

                $html .= '  <div class="vc-body">' . "\n";
                $html .= '    <div class="workflow-mini">' . "\n";
                foreach ($allSteps as $i => $as) {
                    $dones = array_filter(explode('|', $as['dones'] ?? ''));
                    $allDone = $dones !== [] && !in_array('', $dones, true) && !in_array(null, $dones, true);
                    if ($allDone) {
                        $cls = 'wf-step-done';
                        $icon = '✓';
                    } elseif ($as['ordre'] === $pendingToken['ordre']) {
                        $cls = 'wf-step-current';
                        $icon = '⏳';
                    } else {
                        $cls = 'wf-step-upcoming';
                        $icon = '○';
                    }
                    if ($i > 0) {
                        $html .= '<span class="wf-arrow">→</span>';
                    }
                    $html .= '<span class="wf-step-mini ' . $cls . '" aria-hidden="true">' . $icon . ' ' . $htmlService->escape($as['label']) . '</span>' . "\n";
                }
                $html .= '    </div>' . "\n";
                $html .= '  </div>' . "\n";

                $html .= '  <div class="vc-actions">' . "\n";
                if (!$expired) {
                    $html .= '    <a href="index.php?p=validate&token=' . urlencode((string) ($pendingToken['token'] ?? '')) . '" class="btn btn-primary"><span aria-hidden="true">✓</span> Valider / Refuser</a>' . "\n";
                } else {
                    $html .= '    <span class="u-col-fon-5">Token expiré — contactez un administrateur pour régénérer</span>' . "\n";
                }
                $html .= '    <details class="u-mar">' . "\n";
                $html .= '      <summary class="btn btn-secondary btn-sm-10"><span aria-hidden="true">🔄</span> Déléguer</summary>' . "\n";
                $html .= '      <form method="POST" class="styled-box-12">' . "\n";
                $html .= '        ' . App::security()->csrfField() . "\n";
                $html .= '        <input type="hidden" name="action" value="delegate_token">' . "\n";
                $html .= '        <input type="hidden" name="token_id" value="' . $htmlService->escape($pendingToken['token_id']) . '">' . "\n";
                $html .= '        <input type="email" name="delegate_to" placeholder="email@exemple.invalid" required class="input-filter-3">' . "\n";
                $html .= '        <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" class="input-filter-2">' . "\n";
                $html .= '        <button type="submit" class="styled-box-7">Confirmer</button>' . "\n";
                $html .= '      </form>' . "\n";
                $html .= '    </details>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }
        }
        return $html . ('</div>' . "\n");
    }

    /**
     * @param array<int, array{token_id: string, done_at: string|null, sent_at: string|null, step_label: string, ordre: int, submission_id: string, data: string|null, submitted_at: string|null, sub_status: string, form_label: string, form_slug: string}> $doneTokens
     */
    private static function doneTab(array $doneTokens, string $user): string
    {
        $htmlService = App::html();
        $html = '<div id="tab-done">' . "\n";

        if ($doneTokens === []) {
            $html .= '  <div class="empty-state">' . "\n";
            $html .= '    <div class="empty-icon" aria-hidden="true">📋</div>' . "\n";
            $html .= '    <p>Vous n\'avez encore validé aucune demande.</p>' . "\n";
            $html .= '  </div>' . "\n";
        } else {
            foreach ($doneTokens as $doneToken) {
                $data = json_decode((string) ($doneToken['data'] ?? '{}'), true) ?? [];
                $nomAgent = $htmlService->escape(
                    SubmissionData::get($data, SubmissionField::PRENOM) . ' ' . SubmissionData::get($data, SubmissionField::NOM)
                );

                $actionLabel = 'Validé';
                $actionCls = 'badge-ok';
                if ($doneToken['sub_status'] === SubmissionStatus::Refuse->value) {
                    $refusedByMe = false;
                    if (SubmissionData::has($data, SubmissionField::VALIDATIONS)) {
                        $validations = $data['validations'] ?? [];
                        if (is_array($validations)) {
                            foreach ($validations as $v) {
                                if ($v['action'] === ValidationAction::Refuser->value && (string) ($v['email'] ?? '') === $user) {
                                    $refusedByMe = true;
                                    break;
                                }
                            }
                        }
                    }
                    if ($refusedByMe) {
                        $actionLabel = 'Refusé';
                        $actionCls = 'badge-err';
                    } else {
                        $actionLabel = 'Validé (refusé ailleurs)';
                        $actionCls = 'badge-warn';
                    }
                }

                $html .= '<div class="validation-card done">' . "\n";
                $html .= '  <div class="vc-header">' . "\n";
                $html .= '    <div>' . "\n";
                $html .= '      <div class="vc-title">' . $htmlService->escape($doneToken['form_label']) . ' — ' . $htmlService->escape($doneToken['step_label']) . '</div>' . "\n";
                $html .= '      <div class="vc-meta">' . "\n";
                $html .= '        Agent : <strong>' . $nomAgent . '</strong>' . "\n";
                $html .= '        <br>Soumis le ' . $htmlService->escape($htmlService->formatDateTimeFr((string) ($doneToken['submitted_at'] ?? ''))) . "\n";
                $html .= '      </div>' . "\n";
                $html .= '    </div>' . "\n";
                $html .= '    <span class="badge ' . $actionCls . '">' . $actionLabel . '</span>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '  <div class="vc-body">' . "\n";
                $html .= '    <div class="done-info">Traitée le <strong>' . $htmlService->escape($htmlService->formatDateTimeFr((string) ($doneToken['done_at'] ?? ''))) . '</strong></div>' . "\n";
                $html .= '    <div class="done-date">Délai de traitement : ' . self::formatDelay((string) ($doneToken['done_at'] ?? ''), (string) ($doneToken['sent_at'] ?? '')) . '</div>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }
        }
        return $html . ('</div>' . "\n");
    }

    private static function formatDelay(string $doneAt, string $sentAt): string
    {
        $doneTs = strtotime($doneAt);
        $sentTs = strtotime($sentAt);
        if (!((bool) $doneTs) || !((bool) $sentTs)) {
            return '?';
        }

        $diffSec = $doneTs - $sentTs;
        if ($diffSec >= 86400) {
            $days = (int) floor($diffSec / 86400);
            $hours = (int) floor(($diffSec % 86400) / 3600);
            return App::html()->escape($days . ' j ' . $hours . ' h');
        }
        if ($diffSec >= 3600) {
            $hours = (int) floor($diffSec / 3600);
            $mins = (int) floor(($diffSec % 3600) / 60);
            return App::html()->escape($hours . ' h ' . ($mins > 0 ? $mins . ' min' : ''));
        }
        $mins = (int) floor($diffSec / 60);
        return App::html()->escape($mins . ' min');
    }

    /**
     * @param array<int, array{id: string, submission_id: string, field_name: string, field_label: string, field_type: string, value: string|null, filled_by: string, filled_at: string, step_id: string|null, step_label: string|null, filled_by_email: string|null, token_id: string|null, form_id: string, form_label: string}> $myVdRows
     */
    private static function myVdSection(array $myVdRows): string
    {
        $htmlService = App::html();
        if ($myVdRows === []) {
            return '';
        }

        $html = '<details class="mt-15">' . "\n";
        $html .= '  <summary class="u-col-cur-fon-fon">' . "\n";
        $html .= '    📝 Champs validateur que j\'ai remplis (' . count($myVdRows) . ')' . "\n";
        $html .= '  </summary>' . "\n";
        $html .= '  <div class="card mt-5">' . "\n";
        $html .= '    <table>' . "\n";
        $html .= '      <thead>' . "\n";
        $html .= '        <tr>' . "\n";
        $html .= '          <th>Date</th>' . "\n";
        $html .= '          <th>Formulaire</th>' . "\n";
        $html .= '          <th>Étape</th>' . "\n";
        $html .= '          <th>Champ</th>' . "\n";
        $html .= '          <th>Valeur</th>' . "\n";
        $html .= '        </tr>' . "\n";
        $html .= '      </thead>' . "\n";
        $html .= '      <tbody>' . "\n";

        foreach ($myVdRows as $myVdRow) {
            $rFilledAt  = $myVdRow['filled_at'] ?? '';
            $rFormLabel = $myVdRow['form_label'] ?? '';
            $rSubId     = $myVdRow['submission_id'] ?? '';
            $rStepLabel = $myVdRow['step_label'] ?? '';
            $rFieldLbl  = $myVdRow['field_label'] ?? '';
            $rFieldName = $myVdRow['field_name'] ?? '';
            $rValue     = $myVdRow['value'] ?? '';
            $ts = $rFilledAt !== '' ? strtotime($rFilledAt) : false;
            $rValueShort = mb_strimwidth($rValue, 0, 80, '…', 'UTF-8');

            $html .= '        <tr>' . "\n";
            $html .= '          <td>' . ($ts !== false ? $htmlService->escape(date('d/m/Y H:i', $ts)) : '—') . '</td>' . "\n";
            $html .= '          <td><a href="index.php?p=submission_view&id=' . urlencode($rSubId) . '">' . $htmlService->escape($rFormLabel) . '</a></td>' . "\n";
            $html .= '          <td>' . $htmlService->escape(App::html()->tJargon($rStepLabel)) . '</td>' . "\n";
            $html .= '          <td>' . $htmlService->escape(App::html()->tJargon($rFieldLbl !== '' ? $rFieldLbl : $rFieldName)) . '</td>' . "\n";
            $html .= '          <td>' . $htmlService->escape($rValueShort) . '</td>' . "\n";
            $html .= '        </tr>' . "\n";
        }

        $html .= '      </tbody>' . "\n";
        $html .= '    </table>' . "\n";
        $html .= '  </div>' . "\n";

        return $html . ('</details>' . "\n");
    }
}
