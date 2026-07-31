<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Rendu de la page d'accueil (index.php).
 *
 * Chaque fonction render_index_*() historique devient une méthode statique.
 * Les wrappers globaux dans lib/render_index.php assurent la rétrocompatibilité.
 */
final class IndexRenderer
{
    /**
     * CSS propre à la page d'accueil (chargé depuis lib/index_page.css).
     */
    public static function pageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(__DIR__ . '/../../lib/index_page.css');
        }
        return $css;
    }

    /**
     * Mini-tutoriel de 1ère utilisation (S4-TUTORIAL Action 6).
     */
    public static function tutorial(): string
    {
        return <<<HTML
                  <!-- S4-TUTORIAL (Action 6) : Mini-tutoriel de 1ère utilisation — visible
                       uniquement si l'agent a 0 soumission (vraie 1ère fois).
                       Disparaît dès qu'au moins 1 soumission existe. -->
                  <section class="tutorial" role="region" aria-label="Tutoriel de prise en main">
                    <div class="tutorial-header">
                      <span class="tutorial-header-icon" aria-hidden="true">🎓</span>
                      <div>
                        <h2 class="tutorial-title">Premiers pas en 4 étapes</h2>
                        <p class="tutorial-subtitle">Voici comment démarrer en quelques minutes.</p>
                      </div>
                    </div>
                    <ol class="tutorial-steps">
                      <li class="tutorial-step">
                        <span class="tutorial-step-num" aria-hidden="true">1</span>
                        <span class="tutorial-step-icon" aria-hidden="true">📋</span>
                        <div class="tutorial-step-body">
                          <div class="tutorial-step-title">Choisissez un formulaire</div>
                          <div class="tutorial-step-desc">Sélectionnez le type de demande que vous voulez faire dans la liste ci-dessous.</div>
                        </div>
                      </li>
                      <li class="tutorial-step">
                        <span class="tutorial-step-num" aria-hidden="true">2</span>
                        <span class="tutorial-step-icon" aria-hidden="true">✍</span>
                        <div class="tutorial-step-body">
                          <div class="tutorial-step-title">Remplissez les champs</div>
                          <div class="tutorial-step-desc">Vous pouvez reprendre la saisie plus tard si vous devez chercher une information.</div>
                        </div>
                      </li>
                      <li class="tutorial-step">
                        <span class="tutorial-step-num" aria-hidden="true">3</span>
                        <span class="tutorial-step-icon" aria-hidden="true">📊</span>
                        <div class="tutorial-step-body">
                          <div class="tutorial-step-title">Suivez l'avancement</div>
                          <div class="tutorial-step-desc">Retrouvez vos demandes et leur statut dans « Mes demandes » à gauche.</div>
                        </div>
                      </li>
                      <li class="tutorial-step">
                        <span class="tutorial-step-num" aria-hidden="true">4</span>
                        <span class="tutorial-step-icon" aria-hidden="true">📂</span>
                        <div class="tutorial-step-body">
                          <div class="tutorial-step-title"><a href="index.php?p=my_submissions" class="u-col-tex-3">Voir mes demandes</a></div>
                          <div class="tutorial-step-desc">Retrouvez vos demandes à tout moment dans la rubrique « Mes demandes » (à gauche ou ci-dessous).</div>
                        </div>
                      </li>
                    </ol>
                    <div class="tutorial-footer">
                      <a href="index.php?p=my_submissions" class="tutorial-cta"><span aria-hidden="true">📂</span> Voir mes demandes →</a>
                      <button type="button" class="tutorial-dismiss"
                              data-dismiss=".tutorial"
                              aria-label="Fermer le tutoriel de prise en main">J'ai compris ✓</button>
                    </div>
                  </section>
            HTML;
    }

    /**
     * Empty-state guidé accueil agent (U-06 part 2).
     *
     * @param array<int, array<string, mixed>> $welcome_forms
     */
    public static function welcomeState(array $welcome_forms): string
    {
        $cards = '';
        foreach ($welcome_forms as $welcome_form) {
            ['slug' => $slug, 'label' => $label, 'desc' => $desc] = self::escapeFormField($welcome_form);
            $desc_html = $desc !== '' ? "\n              <div class=\"welcome-form-desc\">{$desc}</div>" : '';
            $cards .= <<<HTML
                          <a href="index.php?p=form&f={$slug}" class="welcome-form-card">
                            <span class="welcome-form-icon" aria-hidden="true">📝</span>
                            <div class="welcome-form-body">
                              <div class="welcome-form-title">{$label}</div>{$desc_html}
                            </div>
                            <span class="welcome-form-btn" aria-hidden="true">Remplir →</span>
                          </a>
                HTML;
        }
        $app_name = \App\Core\App::html()->escape(NavigationRenderer::getAppName());
        return <<<HTML
                <!-- U-06 (part 2) : Empty-state guidé accueil agent — visible uniquement si l'agent
                     a VRAIMENT 0 soumission (pas 0 en cours mais 10 archivées) ET qu'au moins un
                     formulaire actif existe. Remplace la section "Nouvelle demande" pour éviter
                     la duplication des cartes formulaire. -->
                <section class="welcome-state" role="region" aria-label="Accueil agent">
                  <div class="welcome-icon" aria-hidden="true">👋</div>
                  <h2 class="welcome-title">Bienvenue sur {$app_name}</h2>
                  <p class="welcome-text">Vous n'avez pas encore de demande en cours. Choisissez un formulaire ci-dessous pour commencer.</p>
                  <div class="welcome-actions">
            {$cards}
                  </div>
                  <p class="welcome-doc-link">
                    <a href="index.php?p=docs">📖 Comment ça marche ?</a>
                  </p>
                </section>
            HTML;
    }

    /**
     * Section « Nouvelle demande » : cartes des formulaires actifs.
     *
     * @param array<int, array<string, mixed>> $active_forms
     */
    public static function formCards(array $active_forms): string
    {
        if ($active_forms === []) {
            return <<<'HTML'
                  <h2 class="section-title"><span aria-hidden="true">📝</span> Formulaires</h2>
                    <p class="heading-colored">Aucun formulaire disponible pour le moment.</p>
                HTML;
        }
        $cards = '';
        foreach ($active_forms as $active_form) {
            ['slug' => $slug, 'label' => $label, 'desc' => $desc] = self::escapeFormField($active_form);
            $desc_html = $desc !== '' ? "\n          <div class=\"fc-desc\">{$desc}</div>" : '';
            $cards .= <<<HTML
                        <a href="index.php?p=form&f={$slug}" class="form-card">
                          <div class="fc-title">{$label}</div>{$desc_html}
                          <div class="fc-btn">Remplir le formulaire →</div>
                        </a>
                HTML;
        }
        return <<<HTML
              <h2 class="section-title" id="form-cards"><span aria-hidden="true">📝</span> Formulaires</h2>
              <div class="form-cards">
            {$cards}
              </div>
            HTML;
    }

    /**
     * Escape common form fields (slug, label, description).
     *
     * @param array<string, mixed> $form
     * @return array{slug: string, label: string, desc: string}
     */
    private static function escapeFormField(array $form): array
    {
        $slug  = \App\Core\App::html()->escape((string) ($form['slug'] ?? ''));
        $label = \App\Core\App::html()->escape((string) ($form['label'] ?? ''));
        $desc  = '';
        if (!empty($form['description'])) {
            $desc = \App\Core\App::html()->escape((string) $form['description']);
        }
        return ['slug' => $slug, 'label' => $label, 'desc' => $desc];
    }

    /**
     * Tooltips script (cosmétique — vide depuis v10.0.6).
     */
    public static function tooltipsScript(): string
    {
        return <<<HTML
            HTML;
    }
}
