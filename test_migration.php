<?php
// Test de migration automatique
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

echo "Test de l'auto-migration...\n";

try {
    // Cela devrait déclencher la migration automatique
    $pdo = get_pdo();
    echo "Connexion PDO réussie\n";
    
    // Vérifier que les tables ont été créées
    $tables = ['forms', 'steps', 'step_recipients', 'submissions', 'tokens', 'admins', 'admin_requests'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) {
            echo "Table '$table' présente\n";
        } else {
            echo "Table '$table' absente\n";
        }
    }
    
    // Vérifier que l'admin principal a été ajouté
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    $count = $stmt->fetchColumn();
    echo "Nombre d'administrateurs : $count\n";
    
    echo "Test terminé avec succès !\n";
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
}
?>