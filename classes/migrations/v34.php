<?php
declare(strict_types=1);

/**
 * Migration v34: Retrait de la contrainte unique "une soumission active par
 * formulaire + agent" (index posé en v32, recréé en v33).
 *
 * Cette contrainte supposait qu'un agent ne pouvait avoir qu'une seule
 * soumission en_cours par formulaire. Ce n'est pas une règle métier valide :
 * un manager peut légitimement avoir plusieurs soumissions actives en
 * parallèle sur un même formulaire (ex. onboarding : une par nouvelle
 * arrivée dans son équipe ; matériel : plusieurs demandes distinctes).
 * `submitted_by` seul n'identifie pas le sujet de la demande, donc il ne
 * peut pas servir de clé d'unicité globale.
 *
 * La race condition qui motivait v32 (double-clic, retour navigateur créant
 * une soumission en double) reste couverte indépendamment par la rotation du
 * token CSRF à usage unique (SecurityService::verifyCsrf() invalide le
 * token en session après la première vérification réussie).
 *
 * Le cas où l'agent soumet volontairement une nouvelle demande alors qu'une
 * autre est déjà en cours est désormais géré par un palier de confirmation
 * explicite côté FormController (au lieu du blocage silencieux en base).
 *
 * @package Migrations
 */

function apply_migration_v34(PDO $pdo, int $current_version): int {
    $needs_v34 = ($current_version < 34) || ($current_version >= 900);
    if (!$needs_v34) {
        return $current_version;
    }

    try {
        $v34_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 34");
        if ($v34_stmt === false) {
            throw new \RuntimeException('v34: COUNT query failed');
        }
        $v34_done = (int) $v34_stmt->fetchColumn();
        // CS-06 : libérer le statement avant le prochain DDL
        $v34_stmt = null;
        if ($v34_done > 0) {
            return max($current_version, 34);
        }

        $pdo->exec("DROP INDEX IF EXISTS idx_submissions_active_per_form_user");

        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (34, datetime('now'))");
        $stmt->execute();

        return 34;
    } catch (PDOException $e) {
        error_log("Migration v34 failed: " . $e->getMessage());
        return $current_version;
    }
}
