<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page d'accueil (index.php).
 *
 * Adapte le rendu au rôle de l'utilisateur (agent / validateur / admin).
 * Toute la logique métier (data fetching + construction du contenu) vit ici ;
 * index.php n'est qu'un thin wrapper qui instancie ce contrôleur.
 */
final class IndexController extends BaseController
{
    /**
     * Point d'entrée du contrôleur — reproduit à l'identique la logique
     * historique de index.php (ordre des sections et requêtes SQL).
     */
    public function handle(): void
    {


        $user     = $this->auth->getUser();
        // v9.9.0 — is_admin_effective() = false si persona actif → l'admin
        // en mode persona voit la page d'accueil comme un user simple.
        $is_admin = $this->auth->isAdminEffective();

        // Récupérer les formulaires dont l'utilisateur est propriétaire
        $owned_forms = $this->auth->getOwnedForms($user);
        $has_owned   = !empty($owned_forms);

        // Récupérer les formulaires actifs
        $formRepo = App::getInstance()->get(\App\Repository\FormRepository::class);
        $active_forms = $formRepo->findActiveList();

        // Pour les agents : compter leurs soumissions
        $my_total    = 0;
        $my_en_cours = 0;
        $my_valide   = 0;
        if (!$is_admin) {
            $subRepo = App::getInstance()->get(\App\Repository\SubmissionRepository::class);
            $counts = $subRepo->countByStatusForSubmitter($user);
            $my_total    = $counts['total'];
            $my_en_cours = $counts['en_cours'];
            $my_valide   = $counts['valide'];
        }

        // U-06 (part 2) : empty-state guidé pour agent sans aucune demande.
        // Détecté uniquement quand l'agent a VRAIMENT 0 soumission.
        $welcome_forms = [];
        if (!$is_admin && $my_total === 0) {
            $pdo = $this->db->getPdo();
            $welcome_forms = _dbm_q(
                $pdo,
                "SELECT f.id, f.slug, f.label, f.description, COUNT(s.id) AS nb_soumissions
                 FROM forms f
                 LEFT JOIN submissions s ON s.form_id = f.id
                 WHERE f.actif = 1
                 GROUP BY f.id, f.slug, f.label, f.description
                 ORDER BY nb_soumissions DESC, f.label ASC
                 LIMIT 3"
            )->fetchAll(\PDO::FETCH_ASSOC);
        }
        // Le welcome state n'est affiché que si l'agent a 0 demande ET qu'au moins
        // un formulaire actif existe.
        $show_welcome_state = (!$is_admin && $my_total === 0 && !empty($welcome_forms));

        // S4-TUTORIAL (Action 6) : mini-tutoriel de 1ère utilisation.
        // Affiché au-dessus du welcome-state UNIQUEMENT si l'agent a 0 soumission.
        $show_tutorial = $show_welcome_state;

        // Pour les validateurs : compter les tokens en attente
        $tokenRepo = App::getInstance()->get(\App\Repository\TokenRepository::class);
        $my_pending = $tokenRepo->getActiveCountByEmail($user);

        // Pour les admins : stats globales
        $admin_stats = [];
        if ($is_admin) {
            $gstats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
            $admin_stats['total']    = $gstats['total'];
            $admin_stats['en_cours'] = $gstats['en_cours'];
            $admin_stats['valide']   = $gstats['valide'];
            $admin_stats['bloques']  = 0;
            $delai   = (int) $this->settings->get('delai_relance_h', '48');
            $admin_stats['bloques'] = $tokenRepo->getBlockedCount($delai * 2);
        }

        // ── RENDU ──────────────────────────────────────────────────────
        $page_css = \App\Render\IndexRenderer::pageCss();
        $content  = '';

        if ($show_welcome_state) {
            if ($show_tutorial) {
                $content .= \App\Render\IndexRenderer::tutorial();
            }
            $content .= \App\Render\IndexRenderer::welcomeState($welcome_forms);
        } else {
            $content .= \App\Render\IndexRenderer::formCards($active_forms);
        }

        $content .= \App\Render\IndexRenderer::tooltipsScript();

        echo $this->renderPage('Accueil', 'accueil', $page_css, $content);
    }
}
