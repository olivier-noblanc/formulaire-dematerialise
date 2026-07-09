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
            $targetEmail = trim($_GET['email'] ?? '');
            if ($targetEmail === '') {
                http_response_code(400);
                render_error_page(400, 'Email manquant',
                    'Le paramètre email est requis pour action=start.',
                    '');
            }

            try {
                $pdo = $this->db->getPdo();
                $check = $pdo->prepare("SELECT 1 FROM submissions WHERE submitted_by = ? LIMIT 1");
                $check->execute([$targetEmail]);
                if (!$check->fetchColumn()) {
                    render_error_page(404, 'Utilisateur inconnu',
                        'Aucune soumission trouvée pour ' . h($targetEmail) . '.',
                        'Le persona ne peut être activé que pour un utilisateur existant.');
                }
            } catch (\Throwable $e) {
                render_error_page(500, 'Erreur DB', h($e->getMessage()), '');
            }

            $adminEmail = App::auth()->getUser();
            $token = persona_create_token($adminEmail, $targetEmail);
            if ($token === '') {
                render_error_page(500, 'Erreur création token',
                    'Impossible de créer le token persona.', '');
            }

            $redirectUrl = 'index.php?persona_token=' . urlencode($token);
        } elseif ($action === 'stop') {
            if ($currentToken !== '') {
                persona_revoke($currentToken);
            }
            $redirectUrl = 'index.php';
        } else {
            http_response_code(400);
            render_error_page(400, 'Action invalide',
                'Action non reconnue. Utilisez ?action=start&email=XXX ou ?action=stop.',
                '');
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}
