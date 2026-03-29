<?php
// Test de la correction
require_once __DIR__ . '/helpers.php';

// Simuler un utilisateur authentifié
$_SERVER['AUTH_USER'] = 'DREETS\\olivier.noblanc';

// Tester la fonction
$email = get_auth_user();
echo "Email obtenu : " . $email . "\n";

// Tester la validation
if ($email === 'Utilisateur inconnu' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Validation échouée : email invalide\n";
} else {
    echo "Validation réussie : email valide\n";
}
?>