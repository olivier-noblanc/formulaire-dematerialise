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
        $pdo      = $this->db->getPdo();
        // v9.9.0 — is_admin_effective() = false si persona actif → l'admin
        // en mode persona voit la page d'accueil comme un user simple.
        $is_admin = $this->auth->isAdminEffective();

        // Récupérer les formulaires dont l'utilisateur est propriétaire
        $owned_forms = $this->auth->getOwnedForms($user);
        $has_owned   = !empty($owned_forms);

        // Récupérer les formulaires actifs
        $active_forms = _dbm_q(
            $pdo,
            "SELECT id, slug, label, description FROM forms WHERE actif = 1 ORDER BY label"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Pour les agents : compter leurs soumissions
        $my_total    = 0;
        $my_en_cours = 0;
        $my_valide   = 0;
        if (!$is_admin) {
            $stmt = $pdo->prepare(
                "SELECT status, COUNT(*) as cnt FROM submissions WHERE submitted_by = ? GROUP BY status"
            );
            $stmt->execute([$user]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $my_total += (int) $row['cnt'];
                if ($row['status'] === 'en_cours') {
                    $my_en_cours = (int) $row['cnt'];
                } elseif ($row['status'] === 'valide') {
                    $my_valide = (int) $row['cnt'];
                }
            }
        }

        // U-06 (part 2) : empty-state guidé pour agent sans aucune demande.
        // Détecté uniquement quand l'agent a VRAIMENT 0 soumission.
        $welcome_forms = [];
        if (!$is_admin && $my_total === 0) {
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
        $my_pending = 0;
        $pending_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM tokens WHERE email = ? AND done_at IS NULL"
        );
        $pending_stmt->execute([$user]);
        $my_pending = (int) $pending_stmt->fetchColumn();

        // Pour les admins : stats globales
        $admin_stats = [];
        if ($is_admin) {
            $gstats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
            $admin_stats['total']    = $gstats['total'];
            $admin_stats['en_cours'] = $gstats['en_cours'];
            $admin_stats['valide']   = $gstats['valide'];
            $admin_stats['bloques']  = 0;
            $delai   = (int) $this->settings->get('delai_relance_h', '48');
            $bloque_h = $delai * 2;
            $admin_stats['bloques'] = (int) _dbm_q(
                $pdo,
                "SELECT COUNT(*) FROM tokens t
                 JOIN submissions s ON s.id = t.submission_id
                 WHERE t.done_at IS NULL AND s.status = 'en_cours'
                   AND CAST(strftime('%s', 'now') AS REAL)
                       - CAST(strftime('%s', t.sent_at) AS REAL) > ($bloque_h * 3600)"
            )->fetchColumn();
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
