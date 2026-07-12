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
                          <div class="tutorial-step-title"><a href="index.php?p=my_submissions" style="color:inherit;text-decoration:underline;">Voir mes demandes</a></div>
                          <div class="tutorial-step-desc">Retrouvez vos demandes à tout moment dans la rubrique « Mes demandes » (à gauche ou ci-dessous).</div>
                        </div>
                      </li>
                    </ol>
                    <div class="tutorial-footer">
                      <a href="index.php?p=my_submissions" class="tutorial-cta"><span aria-hidden="true">📂</span> Voir mes demandes →</a>
                      <button type="button" class="tutorial-dismiss"
                              onclick="this.closest('.tutorial').style.display='none';"
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
            $slug  = \App\Core\App::html()->escape((string) ($welcome_form['slug'] ?? ''));
            $label = \App\Core\App::html()->escape((string) ($welcome_form['label'] ?? ''));
            $desc_html = '';
            if (!empty($welcome_form['description'])) {
                $d = \App\Core\App::html()->escape((string) $welcome_form['description']);
                $desc_html = "\n              <div class=\"welcome-form-desc\">{$d}</div>";
            }
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
     * Hero — bandeau d'accueil Aurora mesh gradient.
     */
    public static function hero(): string
    {
        $app_name = \App\Core\App::html()->escape(NavigationRenderer::getAppName());
        return <<<HTML
              <!-- Hero -->
              <div class="hero">
                <h1>{$app_name}</h1>
                <p>Bienvenue sur la plateforme de dématérialisation des circuits de validation. Choisissez un formulaire pour démarrer, ou suivez vos demandes en cours.</p>
              </div>
            HTML;
    }

    /**
     * Encadré discret « Où suis-je ? » sous le hero (ITER1-C / Action A.4).
     */
    public static function whereAmI(): string
    {
        return <<<HTML
              <aside class="where-am-i" role="region" aria-label="Où suis-je ?">
                <span class="where-am-i-icon" aria-hidden="true">📍</span>
                <span class="where-am-i-text">Vous êtes sur la <strong>page d'accueil</strong>. Choisissez une action ci-dessous.</span>
              </aside>
            HTML;
    }

    /**
     * Quick stats — validateurs (tokens en attente).
     */
    public static function quickStatsValidator(int $my_pending): string
    {
        return <<<HTML
              <!-- Quick stats -->
              <div class="quick-stats">
                <a href="index.php?p=my_validations&tab=pending" class="qs-card warning" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">✅</div>
                  <div class="qs-value">{$my_pending}</div>
                  <div class="qs-label">Validation(s) en attente</div>
                </a>
              </div>
            HTML;
    }

    /**
     * Quick stats — administrateur (vue globale).
     *
     * @param array<string, int> $admin_stats
     */
    public static function quickStatsAdmin(array $admin_stats): string
    {
        $total    = (int) ($admin_stats['total'] ?? 0);
        $en_cours = (int) ($admin_stats['en_cours'] ?? 0);
        $valide   = (int) ($admin_stats['valide'] ?? 0);
        $bloques  = (int) ($admin_stats['bloques'] ?? 0);
        $bloques_card = '';
        if ($bloques > 0) {
            $bloques_card = <<<HTML

                    <div class="qs-card danger">
                      <div class="qs-icon" aria-hidden="true">🚨</div>
                      <div class="qs-value">{$bloques}</div>
                      <div class="qs-label">Tokens bloqués</div>
                    </div>
                HTML;
        }
        return <<<HTML
              <div class="quick-stats">
                <a href="index.php?p=dashboard" class="qs-card" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">📊</div>
                  <div class="qs-value">{$total}</div>
                  <div class="qs-label">Soumissions totales</div>
                </a>
                <a href="index.php?p=dashboard&statut=en_cours" class="qs-card warning" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">⏳</div>
                  <div class="qs-value">{$en_cours}</div>
                  <div class="qs-label">En cours</div>
                </a>
                <a href="index.php?p=dashboard&statut=valide" class="qs-card success" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">✓</div>
                  <div class="qs-value">{$valide}</div>
                  <div class="qs-label">Validées</div>
                </a>{$bloques_card}
              </div>
            HTML;
    }

    /**
     * Quick stats — agent (mes demandes).
     */
    public static function quickStatsAgent(int $my_total, int $my_en_cours, int $my_valide): string
    {
        return <<<HTML
              <div class="quick-stats">
                <a href="index.php?p=my_submissions&statut=tous" class="qs-card" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">📋</div>
                  <div class="qs-value">{$my_total}</div>
                  <div class="qs-label">Mes demandes</div>
                </a>
                <a href="index.php?p=my_submissions&statut=en_cours" class="qs-card warning" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">⏳</div>
                  <div class="qs-value">{$my_en_cours}</div>
                  <div class="qs-label">En cours</div>
                </a>
                <a href="index.php?p=my_submissions&statut=valide" class="qs-card success" style="text-decoration:none;color:inherit;">
                  <div class="qs-icon" aria-hidden="true">✓</div>
                  <div class="qs-value">{$my_valide}</div>
                  <div class="qs-label">Validées</div>
                </a>
              </div>
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
                    <p style="color:#595959;font-style:italic;margin-bottom:2rem;">Aucun formulaire disponible pour le moment.</p>
                HTML;
        }
        $cards = '';
        foreach ($active_forms as $active_form) {
            $slug  = \App\Core\App::html()->escape((string) ($active_form['slug'] ?? ''));
            $label = \App\Core\App::html()->escape((string) ($active_form['label'] ?? ''));
            $desc_html = '';
            if (!empty($active_form['description'])) {
                $d = \App\Core\App::html()->escape((string) $active_form['description']);
                $desc_html = "\n          <div class=\"fc-desc\">{$d}</div>";
            }
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
     * Section « Accès rapide » : tuiles de navigation.
     *
     * @param array<int, array<string, mixed>> $owned_forms
     */
    public static function navTiles(bool $is_admin, bool $has_owned, array $owned_forms): string
    {
        $owned_html = '';
        if ($has_owned) {
            foreach ($owned_forms as $owned_form) {
                $id    = urlencode((string) ($owned_form['id'] ?? ''));
                $label = \App\Core\App::html()->escape((string) ($owned_form['label'] ?? ''));
                $owned_html .= <<<HTML

                        <a href="index.php?p=form_tracking&f={$id}" class="nav-tile">
                          <span class="nt-icon" aria-hidden="true">📊</span>
                          <div>
                            <div class="nt-label">Suivi : {$label}</div>
                            <div class="nt-desc">Tableau de suivi propriétaire</div>
                          </div>
                        </a>
                    HTML;
            }
        }
        $admin_html = '';
        if ($is_admin) {
            $admin_html = <<<'HTML'

                    <a href="index.php?p=dashboard" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">📊</span>
                      <div>
                        <div class="nt-label">Tableau de bord admin</div>
                        <div class="nt-desc">Superviser toutes les soumissions</div>
                      </div>
                    </a>
                    <a href="index.php?p=monitoring" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">🖥</span>
                      <div>
                        <div class="nt-label">Surveillance</div>
                        <div class="nt-desc">Santé système, alertes, audit</div>
                      </div>
                    </a>
                    <a href="index.php?p=admin_forms" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">⚙</span>
                      <div>
                        <div class="nt-label">Gestion formulaires</div>
                        <div class="nt-desc">Configurer formulaires, étapes et champs</div>
                      </div>
                    </a>
                    <a href="index.php?p=admin_alerts" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">🔔</span>
                      <div>
                        <div class="nt-label">Alertes</div>
                        <div class="nt-desc">Configurer les règles d'alerte</div>
                      </div>
                    </a>
                    <a href="index.php?p=admin_settings" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">🔧</span>
                      <div>
                        <div class="nt-label">Paramètres</div>
                        <div class="nt-desc">Configuration SMTP et workflow</div>
                      </div>
                    </a>
                    <a href="index.php?p=backup" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">💾</span>
                      <div>
                        <div class="nt-label">Sauvegarde</div>
                        <div class="nt-desc">Sauvegarder et restaurer la base de données</div>
                      </div>
                    </a>
                    <a href="index.php?p=stats" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">📊</span>
                      <div>
                        <div class="nt-label">Statistiques</div>
                        <div class="nt-desc">Tableaux de bord et métriques d'utilisation</div>
                      </div>
                    </a>
                    <a href="index.php?p=rgpd" class="nav-tile">
                      <span class="nt-icon" aria-hidden="true">🔐</span>
                      <div>
                        <div class="nt-label">RGPD</div>
                        <div class="nt-desc">Conformité et gestion des données personnelles</div>
                      </div>
                    </a>
                HTML;
        }
        return <<<HTML
              <!-- Navigation rapide -->
              <h2 class="section-title"><span aria-hidden="true">🧭</span> Accès rapide</h2>
              <div class="nav-tiles">
                <a href="index.php?p=my_submissions" class="nav-tile">
                  <span class="nt-icon" aria-hidden="true">📋</span>
                  <div>
                    <div class="nt-label">Mes demandes</div>
                    <div class="nt-desc">Suivre l'avancement de mes soumissions</div>
                  </div>
                </a>
                <a href="index.php?p=my_validations" class="nav-tile">
                  <span class="nt-icon" aria-hidden="true">✅</span>
                  <div>
                    <div class="nt-label">Mes validations</div>
                    <div class="nt-desc">Voir les tâches de validation qui m'attendent</div>
                  </div>
                </a>
                <a href="index.php?p=docs" class="nav-tile">
                  <span class="nt-icon" aria-hidden="true">📖</span>
                  <div>
                    <div class="nt-label">Documentation</div>
                    <div class="nt-desc">Guides et aide pour utiliser la plateforme</div>
                  </div>
                </a>{$owned_html}{$admin_html}
                <a href="index.php?p=health" class="nav-tile">
                  <span class="nt-icon" aria-hidden="true">🏥</span>
                  <div>
                    <div class="nt-label">Santé système</div>
                    <div class="nt-desc">Vérifier l'état des services et de l'infrastructure</div>
                  </div>
                </a>
              </div>
            HTML;
    }

    /**
     * Section « Mes formulaires (suivi) » pour les owners non-admin.
     *
     * @param array<int, array<string, mixed>> $owned_forms
     */
    public static function ownerForms(array $owned_forms): string
    {
        if ($owned_forms === []) {
            return '';
        }

        $cards = '';
        foreach ($owned_forms as $owned_form) {
            $id    = \App\Core\App::html()->escape((string) ($owned_form['id'] ?? ''));
            $label = \App\Core\App::html()->escape(t_jargon((string) ($owned_form['label'] ?? '')));
            $cards .= <<<HTML
                        <a href="index.php?p=form_tracking&f={$id}" class="form-card">
                          <div class="fc-title">📊 {$label}</div>
                          <div class="fc-desc">Suivre les demandes de ce formulaire</div>
                          <div class="fc-btn">Voir le suivi →</div>
                        </a>
                HTML;
        }

        return <<<HTML
              <h2 class="section-title" id="owner-forms" style="margin-top:2rem;"><span aria-hidden="true">📊</span> Mes formulaires (suivi)</h2>
              <div class="form-cards">
            {$cards}
              </div>
            HTML;
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
