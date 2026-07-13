<?php
declare(strict_types=1);

/**
 * Migration v23: Ajoute la table `mail_log` pour tracer tous les envois SMTP.
 *
 * Problème : avant cette migration, send_mail() ne journalisait que des
 * résumés courts dans audit_log (action mail_sent / mail_error) via app_log().
 * L'utilisateur n'avait aucune visibilité sur la conversation SMTP
 * (connexion, authentification, EHLO, RCPT TO, réponses du serveur) ni sur
 * le détail des erreurs PHPMailer. En cas d'échec d'envoi, le diagnostic
 * était impossible depuis l'UI.
 *
 * Cette migration crée une table dédiée `mail_log` qui enregistre pour
 * chaque tentative d'envoi :
 *  - id (UUID)
 *  - created_at (timestamp UTC)
 *  - recipient (email destinataire)
 *  - subject (sujet de l'email)
 *  - status ('sent' | 'error' | 'blocked' | 'dry_run' | 'cli_blocked')
 *  - error_message (message d'erreur PHPMailer ou detail de blocage)
 *  - smtp_log (conversation SMTP complète si debug activé)
 *  - actor (utilisateur connecté ou 'CLI')
 *  - ip (adresse IP source)
 *
 * La table est consultée depuis monitoring.php (carte "Journal des emails")
 * et depuis admin_settings.php (test email affiche le smtp_log).
 *
 * @package Migrations
 */

function apply_migration_v23(PDO $pdo, int $current_version): int {
    $needs_v23 = ($current_version < 23) || ($current_version >= 900);
    if (!$needs_v23) {
        return $current_version;
    }

    try {
        // Vérifier si v23 a déjà été appliquée
        $v23_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 23");
        if ($v23_stmt === false) {
            throw new \RuntimeException('v23: COUNT query failed');
        }
        $v23_done = (int) $v23_stmt->fetchColumn();
        if ($v23_done > 0) {
            return max($current_version, 23);
        }

        // Vérifier si la table existe déjà (au cas où la base aurait été créée
        // après l'ajout de la table dans schema_initial.php)
        $table_exists_stmt = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'"
        );
        if ($table_exists_stmt === false) {
            throw new \RuntimeException('v23: COUNT sqlite_master failed');
        }
        $table_exists = (int) $table_exists_stmt->fetchColumn();

        if ($table_exists === 0) {
            $pdo->exec("
                CREATE TABLE mail_log (
                    id           TEXT PRIMARY KEY,
                    created_at   TEXT NOT NULL,
                    recipient    TEXT NOT NULL,
                    subject      TEXT NOT NULL,
                    status       TEXT NOT NULL,
                    error_message TEXT DEFAULT '',
                    smtp_log     TEXT DEFAULT '',
                    actor        TEXT DEFAULT '',
                    ip           TEXT DEFAULT ''
                )
            ");
            // Index pour rechercher par destinataire ou par statut rapidement
            $pdo->exec("CREATE INDEX idx_mail_log_created_at ON mail_log(created_at DESC)");
            $pdo->exec("CREATE INDEX idx_mail_log_recipient ON mail_log(recipient)");
            $pdo->exec("CREATE INDEX idx_mail_log_status ON mail_log(status)");
        }

        $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([23]);
        return 23;
    } catch (PDOException $e) {
        error_log('[db_migrate] v23 FAILED: ' . $e->getMessage() . ' — retry au prochain appel');
    }
    return $current_version;
}
