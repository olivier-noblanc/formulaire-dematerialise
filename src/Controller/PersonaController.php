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

        // v10.28.0 : plus d'étape de confirmation pour persona.
        // GET exécute directement l'action (self-agent mode : l'admin voit l'interface
        // avec ses propres droits les plus faibles — jamais d'upgrade vers un autre user).
        // POST vérifie CSRF (depuis le form sidebar).
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
        }

        $redirectUrl = 'index.php';

        if ($action === 'start') {
            $adminEmail = App::auth()->getUser();
            $targetEmail = strtolower(trim((string) ($_POST['email'] ?? $_GET['email'] ?? '')));
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
                // v10.28.0 : self-agent mode autorisé même sans soumissions
                // (l'admin visualise l'interface avec ses propres droits réduits)
                if ($targetEmail !== $adminEmail && !$subRepo->existsBySubmitter($targetEmail)) {
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
                'Action non reconnue. Utilisez ?action=start&email=XXX ou ?action=stop.',
                ''
            );
        }

        $this->redirect($redirectUrl);
    }
}
