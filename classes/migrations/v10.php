<?php
declare(strict_types=1);

/**
 * Migration v10: email verification settings (mail_dry_run, email_verify_mode,
 * ldap_host, ldap_port, ldap_base_dn, ldap_bind_dn, ldap_bind_pass, ldap_filter).
 *
 * @package Migrations
 */

function apply_migration_v10(PDO $pdo, int $current_version): int {
    if ($current_version < 10) {
        try {
            $v10_settings = [
                ['mail_dry_run',      '1'],  // Sécurité : dry-run activé par défaut
                ['email_verify_mode', 'none'], // none | ldap | smtp
                ['ldap_host',         ''],     // ex: ldap.example.com (S-14: sanitized)
                ['ldap_port',         '389'],
                ['ldap_base_dn',      ''],     // ex: DC=example,DC=com (S-14: sanitized)
                ['ldap_bind_dn',      ''],     // Compte de service lecture seule
                ['ldap_bind_pass',    ''],
                ['ldap_filter',       '(mail={email})'],
            ];
            $stmt_v10 = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");
            foreach ($v10_settings as $row) {
                $stmt_v10->execute($row);
            }

            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([10]);
            return 10;
        } catch (PDOException $e) {
            // @silent-ok: fallback — migration déjà appliquée, on marque la version
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([10]); } catch (PDOException $e2) { /* @silent-ok: fallback — ignore si déjà enregistré */ }
            return 10;
        }
    }
    return $current_version;
}
