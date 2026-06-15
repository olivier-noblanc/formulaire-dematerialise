<?php
// screenshot.php — Sert les captures d'écran depuis le dossier docs/screenshots/
// Contourne le problème IIS qui ne sert pas les fichiers statiques dans les sous-dossiers.
//
// Usage : screenshot.php?f=01_index_agent.png

require_once __DIR__ . '/helpers.php';

$file = $_GET['f'] ?? '';

// Sécurité : uniquement un nom de fichier simple (pas de traversal)
if (empty($file) || basename($file) !== $file) {
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

$path = __DIR__ . '/docs/screenshots/' . $file;

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
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');

readfile($path);
exit;
