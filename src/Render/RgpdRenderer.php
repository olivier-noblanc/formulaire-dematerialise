<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;

/**
 * Rendu de la page RGPD.
 */
final class RgpdRenderer
{
    public static function content(
        string $successMsg,
        string $errorMsg,
        string $infoMsg,
        int $totalSubmissions,
        int $totalAttachments,
        int $totalAudit,
        int $dbSize,
        int $oldSubmissions,
        int $retentionMonths,
        string $legalMentions,
        string $emailDomain
    ): string {
        $h = App::html()->escape(...);
        $csrf = App::security()->csrfField();

        $msgHtml = new ErrorRenderer()->messages([
            'success' => $successMsg,
            'error' => $errorMsg,
            'info' => $infoMsg,
        ]);

        $dbSizeFormatted = App::html()->formatFileSize($dbSize);
        $escapedLegal = $h($legalMentions);
        $escapedDomain = $h($emailDomain);
        $retentionVal = $retentionMonths;

        $html = <<<HTML
              <h1><span aria-hidden="true">🔐</span> Conformité RGPD</h1>

              {$msgHtml}

              <!-- Statistiques des données -->
              <div class="stat-row">
                <div class="stat-mini"><div class="val">{$totalSubmissions}</div><div class="lbl">Soumissions</div></div>
                <div class="stat-mini"><div class="val">{$totalAttachments}</div><div class="lbl">Pièces jointes</div></div>
                <div class="stat-mini"><div class="val">{$totalAudit}</div><div class="lbl">Entrées d'audit</div></div>
                <div class="stat-mini"><div class="val">{$dbSizeFormatted}</div><div class="lbl">Taille base de données</div></div>
              </div>

            HTML;

        if ($oldSubmissions > 0) {
            $s = $oldSubmissions > 1 ? 's' : '';
            $html .= <<<HTML
                  <div class="warn-box mb-15">
                    <strong><span aria-hidden="true">⚠</span> {$oldSubmissions} soumission{$s}</strong> clôturée{$s} depuis plus de {$retentionVal} mois peuvent être purgées.
                  </div>

                HTML;
        }

        $html .= <<<HTML
              <!-- Mentions légales -->
              <div class="card">
                <h2><span aria-hidden="true">📜</span> Mentions légales & Politique de conservation</h2>
                <form method="POST">
                  {$csrf}
                  <input type="hidden" name="action" value="update_legal">
                  <div class="field">
                    <label for="legal_mentions">Mentions légales affichées aux utilisateurs</label>
                    <textarea id="legal_mentions" name="legal_mentions" rows="6" class="minh-120">{$escapedLegal}</textarea>
                    <span class="hint">Ce texte est affiché lors de la soumission des formulaires et dans la documentation.</span>
                  </div>
                  <div class="field">
                    <label for="retention_months">Durée de conservation (mois)</label>
                    <input type="number" id="retention_months" name="retention_months" value="{$retentionVal}" min="1" max="120" class="u-wid">
                    <span class="hint">Les soumissions clôturées plus anciennes seront purgées automatiquement.</span>
                  </div>
                  <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
              </div>

              <!-- Export des données -->
              <div class="card">
                <h2><span aria-hidden="true">📤</span> Droit d'accès — Export des données</h2>
                <p class="caption-2">
                  Conformément à l'article 15 du RGPD, toute personne peut demander l'export de ses données personnelles.
                  Saisissez l'adresse email de l'agent pour générer un export JSON complet.
                </p>
                <form method="POST" class="flex-gap5">
                  {$csrf}
                  <input type="hidden" name="action" value="export_user">
                  <div class="field flex-3">
                    <label for="export_email">Email de l'agent</label>
                    <input type="email" id="export_email" name="export_email" placeholder="prenom.nom@{$escapedDomain}" required>
                  </div>
                  <button type="submit" class="btn btn-primary"><span aria-hidden="true">📥</span> Exporter les données</button>
                </form>
              </div>

              <!-- Suppression des données -->
              <div class="danger-zone">
                <h3><span aria-hidden="true">🗑</span> Droit à l'effacement — Suppression des données</h3>
                <p class="caption-2">
                  Conformément à l'article 17 du RGPD, toute personne peut demander la suppression de ses données personnelles.
                  Les soumissions seront anonymisées (le statut et le workflow sont conservés pour traçabilité, mais les données personnelles sont remplacées).
                </p>
                <form method="POST">
                  {$csrf}
                  <input type="hidden" name="action" value="delete_user">
                  <div class="field">
                    <label for="delete_email">Email de l'agent à supprimer</label>
                    <input type="email" id="delete_email" name="delete_email" placeholder="prenom.nom@{$escapedDomain}" required>
                  </div>
                  <label class="checkbox-item mb-1">
                    <input type="checkbox" name="confirmed" value="1" required>
                    Je confirme vouloir anonymiser toutes les données de cet agent. Cette action est irréversible.
                  </label>
                  <button type="submit" class="btn btn-danger"><span aria-hidden="true">🗑</span> Supprimer les données</button>
                </form>
              </div>

              <!-- Purge automatique -->
              <div class="danger-zone">
                <h3><span aria-hidden="true">🧹</span> Purge automatique des données anciennes</h3>
                <p class="caption-2">
                  Supprime définitivement les soumissions clôturées de plus de <strong>{$retentionVal} mois</strong>,
                  ainsi que leurs pièces jointes, tokens et alertes associées.
                </p>

            HTML;

        if ($oldSubmissions > 0) {
            $s = $oldSubmissions > 1 ? 's' : '';
            $html .= <<<HTML
                      <div class="warn-box mb-1">
                        <strong>{$oldSubmissions} soumission{$s}</strong> éligible{$s} à la purge.
                      </div>

                HTML;
        } else {
            $html .= <<<HTML
                      <p class="u-col-fon-mar-2"><span aria-hidden="true">✓</span> Aucune soumission à purger actuellement.</p>

                HTML;
        }

        return $html . <<<HTML
                <form method="POST">
                  {$csrf}
                  <input type="hidden" name="action" value="auto_purge">
                  <label class="checkbox-item mb-1">
                    <input type="checkbox" name="confirmed" value="1" required>
                    Je confirme vouloir purger définitivement les soumissions anciennes. Cette action est irréversible.
                  </label>
                  <button type="submit" class="btn btn-danger"><span aria-hidden="true">🧹</span> Exécuter la purge</button>
                </form>
              </div>

            HTML;
    }
}
