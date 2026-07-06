<?php
/**
 * tests/test_v4_helpers.php — Helpers partagés pour test_v4.php
 *
 * Fournit les fonctions http_request() et api() utilisées par toutes les
 * phases de test v4.0.0. Ces fonctions exécutent des requêtes HTTP réelles
 * vers le serveur PHP built-in démarré par test_v4.php.
 *
 * Les globales $SERVER, $PORT, $PHP, $INI, $BASE doivent être définies
 * par le fichier principal avant tout appel à http_request() / api().
 */

declare(strict_types=1);

/**
 * Exécute une requête HTTP réelle via curl vers le serveur PHP de test.
 * Relance automatiquement le serveur si nécessaire.
 */
function http_request(string $method, string $path, array $get = [], array $post = [], string $test_user = 'test.agent', array $files = []): array {
    global $SERVER, $PORT, $PHP, $INI, $BASE;

    // Vérifier que le serveur tourne
    $check = @curl_init("$SERVER/test_api.php?action=stats");
    if ($check) {
        curl_setopt($check, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($check, CURLOPT_TIMEOUT, 2);
        curl_setopt($check, CURLOPT_HTTPHEADER, ['X-Test-Mode: 1', 'X-Test-User: test.agent']);
        $test = @curl_exec($check);
        curl_close($check);
    }

    if (empty($test)) {
        // Relancer le serveur
        shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
        sleep(1);
        shell_exec("cd $BASE && $PHP -c $INI -S localhost:$PORT -t . > /tmp/php_server_v4.log 2>&1 &");
        sleep(2);
    }

    // Construire l'URL
    $url = "$SERVER/$path";
    if (!empty($get)) {
        $url .= '?' . http_build_query($get);
    }

    // Cookie jar unique par utilisateur test
    $cookie_file = "/tmp/wf_v4_test_cookies_" . preg_replace('/[^a-z0-9]/', '_', $test_user) . ".txt";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Test-Mode: 1',
        "X-Test-User: $test_user",
    ]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Ne pas suivre les redirects

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            // Upload de fichiers : utiliser multipart/form-data
            $post_fields = $post;
            foreach ($files as $field_name => $file_info) {
                $post_fields[$field_name] = new CURLFile(
                    $file_info['tmp_name'],
                    $file_info['mime_type'] ?? 'application/octet-stream',
                    $file_info['name']
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        } elseif (!empty($post)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['http_code' => 0, 'json' => null, 'body' => '', 'error' => $curl_error];
    }

    $json = null;
    if (is_string($response)) {
        // Essayer de décoder le JSON directement
        $json = json_decode($response, true);
        // Si le JSON est invalide, chercher le premier { dans la réponse
        // (au cas où des warnings PHP précèdent le JSON)
        if ($json === null && preg_match('/\{.*\}/s', $response, $matches)) {
            $json = json_decode($matches[0], true);
        }
    }

    return [
        'http_code' => $http_code,
        'json'      => $json,
        'body'      => $response,
    ];
}

/**
 * Appel simplifié à l'API de test.
 */
function api(string $action, array $params = [], string $test_user = 'test.agent'): array {
    return http_request('GET', 'test_api.php', array_merge(['action' => $action], $params), [], $test_user);
}
