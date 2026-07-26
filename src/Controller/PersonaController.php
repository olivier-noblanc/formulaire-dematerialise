<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page persona (activation/désactivation de persona admin).
 */
final class PersonaController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $action = $_GET['action'] ?? '';
        $currentToken = $_GET['persona_token'] ?? '';

        // B-02-5 fix (audit 2026-07-26) : start et stop sont des actions state-changing
        // (activation/désactivation d'un persona admin→user). Avant, elles étaient en
        // GET sans CSRF — un attaquant pouvait forcer un admin à activer un persona
        // via un lien/image piégé (CSRF GET). Maintenant on exige POST + CSRF.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Afficher une page de confirmation qui fait un POST avec CSRF
            // plutôt que d'exécuter directement l'action en GET.
            $redirectUrl = 'index.php';
            if ($action === 'start') {
                $targetEmail = strtolower(trim($_GET['email'] ?? ''));
                // Rediriger vers confirm_action avec les params pour GET→POST
                $redirectUrl = 'index.php?p=confirm_action&action=persona_start'
                    . '&email=' . urlencode($targetEmail)
                    . '&from=' . urlencode('index.php');
            } elseif ($action === 'stop') {
                $redirectUrl = 'index.php?p=confirm_action&action=persona_stop'
                    . '&from=' . urlencode('index.php');
            }
            $this->redirect($redirectUrl);
        }

        // POST : vérifier CSRF
        $this->security->requireCsrf();

        $redirectUrl = 'index.php';

        if ($action === 'start') {
            $targetEmail = strtolower(trim($_POST['email'] ?? ''));
            if ($targetEmail === '') {
                http_response_code(400);
                new \App\Render\ErrorRenderer()->errorPage(
                    400,
                    'Email manquant',
                    'Le paramètre email est requis pour action=start.',
                    ''
                );
            }

            try {
                $subRepo = App::getInstance()->get(\App\Repository\SubmissionRepository::class);
                if (!$subRepo->existsBySubmitter($targetEmail)) {
                    new \App\Render\ErrorRenderer()->errorPage(
                        404,
                        'Utilisateur inconnu',
                        'Aucune soumission trouvée pour ' . \App\Core\App::html()->escape($targetEmail) . '.',
                        'Le persona ne peut être activé que pour un utilisateur existant.'
                    );
                }
            } catch (\Throwable $e) {
                new \App\Render\ErrorRenderer()->errorPage(500, 'Erreur DB', \App\Core\App::html()->escape($e->getMessage()), '');
            }

            $adminEmail = App::auth()->getUser();
            $token = persona_create_token($adminEmail, $targetEmail);
            if ($token === '') {
                new \App\Render\ErrorRenderer()->errorPage(
                    500,
                    'Erreur création token',
                    'Impossible de créer le token persona.',
                    ''
                );
            }

            $redirectUrl = 'index.php?persona_token=' . urlencode($token);
        } elseif ($action === 'stop') {
            if ($currentToken !== '') {
                persona_revoke($currentToken);
            }
            $redirectUrl = 'index.php';
        } else {
            http_response_code(400);
            new \App\Render\ErrorRenderer()->errorPage(
                400,
                'Action invalide',
                'Action non reconnue. Utilisez POST ?action=start&email=XXX ou ?action=stop.',
                ''
            );
        }

        $this->redirect($redirectUrl);
    }
}
