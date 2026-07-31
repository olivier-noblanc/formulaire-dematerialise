<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Contrôleur pour servir les captures d'écran depuis docs/screenshots/.
 *
 * Contourne le problème IIS qui ne sert pas les fichiers statiques
 * dans les sous-dossiers.
 *
 * Usage : index.php?p=screenshot&f=01_index_agent.png
 */
final class ScreenshotController extends BaseController
{
    public function handle(): void
    {
        // Access control: only authenticated users can view screenshots
        if ($this->auth->getUser() === '' || $this->auth->getUser() === '0') {
            http_response_code(403);
            exit('Accès refusé');
        }

        $file = $_GET['f'] ?? '';

        // Sécurité : uniquement un nom de fichier simple (pas de traversal)
        if ($file === '' || $file === null || $file === '0' || basename((string) $file) !== $file) {
            http_response_code(400);
            exit('Fichier invalide.');
        }

        // Extensions autorisées
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime_types = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];

        if (!isset($mime_types[$ext])) {
            http_response_code(400);
            exit('Type de fichier non autorisé.');
        }

        $path = dirname(__DIR__, 2) . '/docs/screenshots/' . $file;
        $real_path = realpath($path);
        $allowed_dir = realpath(dirname(__DIR__, 2) . '/docs/screenshots');
        if ($real_path === false || $allowed_dir === false || !str_starts_with($real_path, $allowed_dir)) {
            http_response_code(400);
            exit('Chemin invalide.');
        }

        if (!file_exists($path)) {
            http_response_code(404);
            exit('Image introuvable.');
        }

        // Headers de cache (1 semaine)
        $expires = 60 * 60 * 24 * 7;
        header('Content-Type: ' . $mime_types[$ext]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=' . $expires);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT');

        readfile($path);
        exit;
    }
}
