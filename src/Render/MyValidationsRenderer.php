<?php
declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Rendu de la page « Mes validations » (dashboard validateur).
 */
final class MyValidationsRenderer
{
    /**
     * Retourne le HTML complet du contenu principal de la page Mes validations.
     *
     * @param array<int, array<string, mixed>> $pendingTokens
     * @param array<int, array<string, mixed>> $doneTokens
     * @param string $activeTab
     * @param int $pendingCount
     * @param int $doneCount
     * @param string $search
     * @param string $delegationMsg
     * @param array<string, list<array<string, mixed>>> $allStepsBySub keyed by submission_id
     * @param array<int, array<string, mixed>> $myVdRows
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
        $h = App::html();
        $pendingActive = $activeTab === 'pending' ? ' active' : '';
        $doneActive = $activeTab === 'done' ? ' active' : '';

        $html = '';
        $html .= '<h1><span aria-hidden="true">✅</span> Mes validations</h1>' . "\n";
        $html .= '<div class="stats">' . "\n";
        $html .= '  <a href="index.php?p=my_validations&tab=pending" class="stat warning' . $pendingActive . '"><strong>' . $pendingCount . '</strong><span>En attente</span></a>' . "\n";
        $html .= '  <a href="index.php?p=my_validations&tab=done" class="stat success' . $doneActive . '"><strong>' . $doneCount . '</strong><span>Traitées</span></a>' . "\n";
        $html .= '</div>' . "\n";

        if ($delegationMsg) {
            $html .= '<div class="msg-info" role="status" aria-live="polite">' . $h->escape($delegationMsg) . '</div>' . "\n";
        }

        $html .= (new FormRenderer())->searchBar('index.php?p=my_validations', $search, 'Rechercher un formulaire...', ['tab' => $activeTab]) . "\n";

        if ($activeTab === 'pending') {
            $html .= self::pendingTab($pendingTokens, $allStepsBySub);
        } else {
            $html .= self::doneTab($doneTokens, $user);
        }

        $html .= self::myVdSection($myVdRows);

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $pendingTokens
     * @param array<string, list<array<string, mixed>>> $allStepsBySub
     */
    private static function pendingTab(array $pendingTokens, array $allStepsBySub): string
    {
        $h = App::html();
        $html = '<div id="tab-pending">' . "\n";

        if (empty($pendingTokens)) {
            $html .= '  <div class="empty-state">' . "\n";
            $html .= '    <div class="empty-icon" aria-hidden="true">🎉</div>' . "\n";
            $html .= '    <p>Aucune validation en attente — vous êtes à jour !</p>' . "\n";
            $html .= '  </div>' . "\n";
        } else {
            foreach ($pendingTokens as $tk) {
                $data = json_decode($tk['data'], true) ?: [];
                $expired = !empty($tk['expires_at']) && strtotime($tk['expires_at']) < time();
                $nomAgent = $h->escape(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
                $allSteps = $allStepsBySub[$tk['submission_id']] ?? [];

                $cardClass = $expired ? 'expired' : 'pending';
                $html .= '<div class="validation-card ' . $cardClass . '">' . "\n";
                $html .= '  <div class="vc-header">' . "\n";
                $html .= '    <div>' . "\n";
                $html .= '      <div class="vc-title">' . $h->escape($tk['form_label']) . ' — Étape ' . (int)$tk['ordre'] . ' : ' . $h->escape($tk['step_label']) . '</div>' . "\n";
                $html .= '      <div class="vc-meta">' . "\n";
                $html .= '        Agent : <strong>' . ($nomAgent ?: $h->escape($tk['data'] ? 'Inconnu' : '')) . '</strong>' . "\n";
                if (!empty($data['affectation'])) {
                    $html .= ' — ' . $h->escape($data['affectation']);
                }
                $html .= '<br>Soumis le ' . $h->escape(date('d/m/Y à H:i', strtotime($tk['submitted_at']))) . "\n";
                if ($tk['relance_count'] > 0) {
                    $html .= '<br><span style="color:#b45309;">Relance(s) : ' . (int)$tk['relance_count'] . '</span>' . "\n";
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
                    $allDone = !empty($dones) && !in_array('', $dones) && !in_array(null, $dones, true);
                    if ($allDone) {
                        $cls = 'wf-step-done';
                        $icon = '✓';
                    } elseif ($as['ordre'] == $tk['ordre']) {
                        $cls = 'wf-step-current';
                        $icon = '⏳';
                    } else {
                        $cls = 'wf-step-upcoming';
                        $icon = '○';
                    }
                    if ($i > 0) {
                        $html .= '<span class="wf-arrow">→</span>';
                    }
                    $html .= '<span class="wf-step-mini ' . $cls . '" aria-hidden="true">' . $icon . ' ' . $h->escape($as['label']) . '</span>' . "\n";
                }
                $html .= '    </div>' . "\n";
                $html .= '  </div>' . "\n";

                $html .= '  <div class="vc-actions">' . "\n";
                if (!$expired) {
                    $html .= '    <a href="index.php?p=validate&token=' . urlencode($tk['token']) . '" class="btn btn-primary"><span aria-hidden="true">✓</span> Valider / Refuser</a>' . "\n";
                } else {
                    $html .= '    <span style="font-size:.85rem;color:#c0392b;">Token expiré — contactez un administrateur pour régénérer</span>' . "\n";
                }
                $html .= '    <details style="margin-left:.5rem;">' . "\n";
                $html .= '      <summary class="btn btn-secondary" style="font-size:.8rem;padding:.4rem .75rem;cursor:pointer;display:inline;"><span aria-hidden="true">🔄</span> Déléguer</summary>' . "\n";
                $html .= '      <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;padding:.75rem;background:#f8f8fc;border-radius:4px;border:1px solid #ddd;">' . "\n";
                $html .= '        ' . App::security()->csrfField() . "\n";
                $html .= '        <input type="hidden" name="action" value="delegate_token">' . "\n";
                $html .= '        <input type="hidden" name="token_id" value="' . $h->escape($tk['token_id']) . '">' . "\n";
                $html .= '        <input type="email" name="delegate_to" placeholder="email@exemple.invalid" required style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:220px;">' . "\n";
                $html .= '        <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:180px;">' . "\n";
                $html .= '        <button type="submit" style="font-size:.8rem;padding:.3rem .75rem;background:#6c3483;color:#fff;border:none;border-radius:3px;cursor:pointer;">Confirmer</button>' . "\n";
                $html .= '      </form>' . "\n";
                $html .= '    </details>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }
        }

        $html .= '</div>' . "\n";
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $doneTokens
     */
    private static function doneTab(array $doneTokens, string $user): string
    {
        $h = App::html();
        $html = '<div id="tab-done">' . "\n";

        if (empty($doneTokens)) {
            $html .= '  <div class="empty-state">' . "\n";
            $html .= '    <div class="empty-icon" aria-hidden="true">📋</div>' . "\n";
            $html .= '    <p>Vous n\'avez encore validé aucune demande.</p>' . "\n";
            $html .= '  </div>' . "\n";
        } else {
            foreach ($doneTokens as $tk) {
                $data = json_decode($tk['data'], true) ?: [];
                $nomAgent = $h->escape(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));

                $actionLabel = 'Validé';
                $actionCls = 'badge-ok';
                if ($tk['sub_status'] === 'refuse') {
                    $refusedByMe = false;
                    if (isset($data['validations'])) {
                        foreach ($data['validations'] as $v) {
                            if ($v['action'] === 'refuser' && (string)($v['email'] ?? '') === $user) {
                                $refusedByMe = true;
                                break;
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
                $html .= '      <div class="vc-title">' . $h->escape($tk['form_label']) . ' — ' . $h->escape($tk['step_label']) . '</div>' . "\n";
                $html .= '      <div class="vc-meta">' . "\n";
                $html .= '        Agent : <strong>' . $nomAgent . '</strong>' . "\n";
                $html .= '        <br>Soumis le ' . $h->escape(date('d/m/Y à H:i', strtotime($tk['submitted_at']))) . "\n";
                $html .= '      </div>' . "\n";
                $html .= '    </div>' . "\n";
                $html .= '    <span class="badge ' . $actionCls . '">' . $actionLabel . '</span>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '  <div class="vc-body">' . "\n";
                $html .= '    <div class="done-info">Traitée le <strong>' . $h->escape(date('d/m/Y à H:i', strtotime($tk['done_at']))) . '</strong></div>' . "\n";
                $html .= '    <div class="done-date">Délai de traitement : ' . self::formatDelay($tk['done_at'], $tk['sent_at']) . '</div>' . "\n";
                $html .= '  </div>' . "\n";
                $html .= '</div>' . "\n";
            }
        }

        $html .= '</div>' . "\n";
        return $html;
    }

    private static function formatDelay(string $doneAt, string $sentAt): string
    {
        $doneTs = strtotime($doneAt);
        $sentTs = strtotime($sentAt);
        if (!$doneTs || !$sentTs) {
            return '?';
        }

        $diffSec = $doneTs - $sentTs;
        if ($diffSec >= 86400) {
            $days = (int)floor($diffSec / 86400);
            $hours = (int)floor(($diffSec % 86400) / 3600);
            return App::html()->escape($days . ' j ' . $hours . ' h');
        } elseif ($diffSec >= 3600) {
            $hours = (int)floor($diffSec / 3600);
            $mins = (int)floor(($diffSec % 3600) / 60);
            return App::html()->escape($hours . ' h ' . ($mins > 0 ? $mins . ' min' : ''));
        } else {
            $mins = (int)floor($diffSec / 60);
            return App::html()->escape($mins . ' min');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $myVdRows
     */
    private static function myVdSection(array $myVdRows): string
    {
        $h = App::html();
        if (empty($myVdRows)) {
            return '';
        }

        $html = '<details style="margin-top: 1.5rem;">' . "\n";
        $html .= '  <summary style="cursor:pointer;font-weight:600;color:var(--c-primary, #003189);font-size:.9rem;">' . "\n";
        $html .= '    📝 Champs validateur que j\'ai remplis (' . count($myVdRows) . ')' . "\n";
        $html .= '  </summary>' . "\n";
        $html .= '  <div class="card" style="margin-top:.5rem;">' . "\n";
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

        foreach ($myVdRows as $r) {
            $rFilledAt  = isset($r['filled_at'])    ? (string)$r['filled_at']    : '';
            $rFormLabel = isset($r['form_label'])   ? (string)$r['form_label']   : '';
            $rSubId     = isset($r['submission_id']) ? (string)$r['submission_id'] : '';
            $rStepLabel = isset($r['step_label'])   ? (string)$r['step_label']   : '';
            $rFieldLbl  = isset($r['field_label'])  ? (string)$r['field_label']  : '';
            $rFieldName = isset($r['field_name'])   ? (string)$r['field_name']   : '';
            $rValue     = isset($r['value'])        ? (string)$r['value']        : '';
            $ts = $rFilledAt !== '' ? strtotime($rFilledAt) : false;
            $rValueShort = mb_strimwidth($rValue, 0, 80, '…', 'UTF-8');

            $html .= '        <tr>' . "\n";
            $html .= '          <td>' . ($ts !== false ? $h->escape(date('d/m/Y H:i', $ts)) : '—') . '</td>' . "\n";
            $html .= '          <td><a href="index.php?p=submission_view&id=' . urlencode($rSubId) . '">' . $h->escape($rFormLabel) . '</a></td>' . "\n";
            $html .= '          <td>' . $h->escape(App::html()->tJargon($rStepLabel)) . '</td>' . "\n";
            $html .= '          <td>' . $h->escape(App::html()->tJargon($rFieldLbl !== '' ? $rFieldLbl : $rFieldName)) . '</td>' . "\n";
            $html .= '          <td>' . $h->escape($rValueShort) . '</td>' . "\n";
            $html .= '        </tr>' . "\n";
        }

        $html .= '      </tbody>' . "\n";
        $html .= '    </table>' . "\n";
        $html .= '  </div>' . "\n";
        $html .= '</details>' . "\n";

        return $html;
    }
}
