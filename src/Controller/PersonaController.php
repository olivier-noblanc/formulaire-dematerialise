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

        $redirectUrl = 'index.php';

        if ($action === 'start') {
            $targetEmail = strtolower(trim($_GET['email'] ?? ''));
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
                'Action non reconnue. Utilisez ?action=start&email=XXX ou ?action=stop.',
                ''
            );
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}
