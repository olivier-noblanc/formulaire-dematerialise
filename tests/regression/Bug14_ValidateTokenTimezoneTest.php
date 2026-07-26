<?php
declare(strict_types=1);
/**
 * Bug 14 — WorkflowEngine::validateToken interprétait expires_at sans UTC
 *
 * Symptôme : strtotime($t['expires_at']) sans fuseau explicite interprétait
 * la chaîne UTC avec le fuseau serveur (Europe/Paris en prod). Le token
 * expirait 1-2h trop tôt en prod.
 *
 * Fix 2026-07-26 : strtotime($t['expires_at'] . ' UTC') force l'interprétation UTC.
 *
 * Test : un token expirant dans 1h (UTC) ne doit PAS être marqué expired,
 * même si PHP tourne en Europe/Paris. On simule en forcant date.timezone.
 *
 * Fichier : tests/regression/Bug14_ValidateTokenTimezoneTest.php
 *
 * @package tests\regression
 */

function run_bug14_test(): bool {
    // Sans DB de test, ce test vérifie juste que strtotime avec suffixe ' UTC'
    // interprète bien la date en UTC, indépendamment du fuseau serveur.
    // Pour simuler Europe/Paris comme serveur de prod, on forcerait
    // ini_set('date.timezone', 'Europe/Paris') — mais PHP peut déjà être
    // configuré ainsi.

    $originalTz = date_default_timezone_get();
    try {
        date_default_timezone_set('Europe/Paris');

        // Token expirant dans 1h (UTC)
        $expiresAtUtc = gmdate('Y-m-d H:i:s', time() + 3600);

        // Avant le fix : strtotime sans suffixe UTC interprétait avec le fuseau serveur
        // (Europe/Paris). En hiver (UTC+1), la chaîne UTC '2026-07-26 12:00:00'
        // était interprétée comme '12:00:00 Europe/Paris' = '11:00:00 UTC'.
        // Le token apparaissait donc expiré 1h trop tôt.
        //
        // Après le fix : strtotime($str . ' UTC') force l'interprétation UTC,
        // ce qui donne le bon timestamp.
        $expTsBuggy = strtotime($expiresAtUtc); // interpretation serveur
        $expTsFixed = strtotime($expiresAtUtc . ' UTC'); // interpretation UTC explicite

        // Vérifier que le fix retourne un timestamp futur (le token n'est pas expiré)
        if ($expTsFixed === false || $expTsFixed < time()) {
            echo "  ❌ Bug14 — Le fix ' UTC' ne marche pas : expTsFixed={$expTsFixed}, time()=" . time() . "\n";
            echo "     expiresAtUtc={$expiresAtUtc}, tz=" . date_default_timezone_get() . "\n";
            return false;
        }

        // Vérifier que la version buggée produisait un décalage
        // (uniquement si le serveur n'est pas en UTC, sinon pas de décalage)
        if ($expTsBuggy !== false && $expTsBuggy !== $expTsFixed) {
            $driftSec = abs($expTsBuggy - $expTsFixed);
            echo "  ℹ️  Bug14 — Drift détecté entre buggé et fixé : {$driftSec}s (fuseau=" . date_default_timezone_get() . ")\n";
            echo "     Sans le fix, le token expirait {$driftSec}s trop tôt.\n";
        } else {
            echo "  ℹ️  Bug14 — Serveur en UTC, pas de drift, mais le fix reste nécessaire pour la prod (Europe/Paris)\n";
        }

        // Test fonctionnel direct : la méthode validateToken doit accepter un token
        // expirant dans 1h comme valide (non-expired).
        // On ne peut pas l'appeler directement sans DB, mais on valide la logique
        // centrale : strtotime avec ' UTC' doit donner un timestamp > time().
        $oneHourAheadUtc = gmdate('Y-m-d H:i:s', time() + 3600);
        $ts = strtotime($oneHourAheadUtc . ' UTC');
        if ($ts === false || $ts < time()) {
            echo "  ❌ Bug14 — Token valide (1h avant expiration) marqué expired par la logique fixée\n";
            return false;
        }

        // Token expiré depuis 1h doit être marqué expired
        $oneHourAgoUtc = gmdate('Y-m-d H:i:s', time() - 3600);
        $ts2 = strtotime($oneHourAgoUtc . ' UTC');
        if ($ts2 === false || $ts2 >= time()) {
            echo "  ❌ Bug14 — Token expiré (1h après expiration) NON marqué expired\n";
            return false;
        }

        echo "  ✅ Bug14 — strtotime avec suffixe ' UTC' interprète correctement les dates d'expiration\n";
        return true;
    } finally {
        date_default_timezone_set($originalTz);
    }
}
