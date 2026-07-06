<?php
declare(strict_types=1);

/**
 * Section de documentation - extraite de docs.php (P-DOCS refactor).
 * Renvoie le HTML rendu de la section via render_docs_section_technique().
 */

function render_docs_section_technique(): string
{
    ob_start();
    ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 9. ARCHITECTURE TECHNIQUE                                   -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="technique">
    <h2>9. Architecture technique (pour l'équipe IT)</h2>
    <p style="margin-bottom:1rem;color:#555;">
      Cette section est destinée au personnel technique. Elle fournit un aperçu de l'architecture.
    </p>

    <details>
      <summary>Structure des fichiers</summary>
      <div class="detail-body">
        <div class="file-tree">
          <span class="dir"><span aria-hidden="true">📁</span> workflow/</span><br>
          &nbsp;&nbsp;<span class="file">config.php</span> — Constantes de configuration (BDD, SMTP, admin, SETTINGS_DEFAULTS)<br>
          &nbsp;&nbsp;<span class="file">helpers.php</span> — Fonctions utilitaires, moteur du circuit de validation, envoi d'emails, cache, sécurité<br>
          &nbsp;&nbsp;<span class="file">style.php</span> — CSS commun (design Marianne / RGAA, inclus via require_once)<br>
          &nbsp;&nbsp;<span class="file">install.php</span> — Assistant de génération de config.php<br>
          &nbsp;&nbsp;<span class="file">index.php</span> — Redirection vers le tableau de bord ou admin_access<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form</span> — Formulaire agent (affichage + envoi de demande)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form_preview</span> — Prévisualisation du formulaire<br>
          &nbsp;&nbsp;<span class="file">index.php?p=validate</span> — Page de validation/refus (accessible par token)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=dashboard</span> — Tableau de bord de supervision<br>
          &nbsp;&nbsp;<span class="file">index.php?p=my_submissions</span> — Mes demandes (agent)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=my_validations</span> — Mes validations (validateur)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=submission_view</span> — Vue détaillée d'une demande<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form_tracking</span> — Tableau de suivi propriétaire (owners + admins)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_access</span> — Gestion des accès administrateur<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_forms</span> — Gestion des formulaires, étapes, destinataires<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_settings</span> — Configuration SMTP et webhooks (super admin)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_alerts</span> — Configuration des alertes J-N<br>
          &nbsp;&nbsp;<span class="file">index.php?p=stats</span> — Statistiques et tableaux de bord<br>
          &nbsp;&nbsp;<span class="file">index.php?p=monitoring</span> — Tableau de bord de surveillance<br>
          &nbsp;&nbsp;<span class="file">index.php?p=health</span> — Point de contrôle de santé (HTTP 200/503)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=rgpd</span> — Conformité RGPD (export, suppression, purge)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=backup</span> — Sauvegarde et restauration<br>
          &nbsp;&nbsp;<span class="file">index.php?p=download</span> — Téléchargement sécurisé des pièces jointes<br>
          &nbsp;&nbsp;<span class="file">index.php?p=confirm_action</span> — Confirmation d'actions sensibles<br>
          &nbsp;&nbsp;<span class="file">index.php?p=screenshot</span> — Sert les captures docs/screenshots/ (contourne IIS)<br>
          &nbsp;&nbsp;<span class="file">remind.php</span> — Script CLI de relance automatique (lazy cron 1×/heure)<br>
          &nbsp;&nbsp;<span class="file">alert_check.php</span> — Script CLI de vérification des alertes J-N (lazy cron 1×/jour)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=docs</span> — Cette page de documentation<br>
          &nbsp;&nbsp;<span class="file">index.php?p=changelog</span> — Journal des versions (parse CHANGELOG.md)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> classes/</span> — DatabaseMigrations.php (migrations v0→v11 + seeding)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> PHPMailer/</span> — Librairie d'envoi d'emails<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> db/</span> — Base de données SQLite (workflow.db)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> cache/</span> — Cache file-based (LDAP suggestions, settings)
        </div>
      </div>
    </details>

    <details>
      <summary>Schéma de la base de données (simplifié)</summary>
      <div class="detail-body">
        <h3 style="margin-top:0;">Table <code>forms</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>slug</td><td>TEXT UNIQUE</td><td>Identifiant URL (ex : onboarding)</td></tr>
            <tr><td>label</td><td>TEXT</td><td>Libellé du formulaire</td></tr>
            <tr><td>description</td><td>TEXT</td><td>Description affichée</td></tr>
            <tr><td>actif</td><td>INTEGER</td><td>1 = actif, 0 = désactivé</td></tr>
          </tbody>
        </table>

        <h3>Table <code>steps</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>TEXT FK (UUID v4)</td><td>Formulaire parent</td></tr>
            <tr><td>label</td><td>TEXT</td><td>Libellé de l'étape</td></tr>
            <tr><td>ordre</td><td>INTEGER</td><td>Numéro d'ordre (détermine la séquence)</td></tr>
            <tr><td>actif</td><td>INTEGER</td><td>1 = actif</td></tr>
          </tbody>
        </table>

        <h3>Table <code>step_recipients</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>step_id</td><td>TEXT FK (UUID v4)</td><td>Étape parent</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
          </tbody>
        </table>

        <h3>Table <code>submissions</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>TEXT FK (UUID v4)</td><td>Formulaire utilisé</td></tr>
            <tr><td>data</td><td>TEXT (JSON)</td><td>Données du formulaire + historique des validations</td></tr>
            <tr><td>submitted_by</td><td>TEXT</td><td>Identifiant de l'agent (AUTH_USER)</td></tr>
            <tr><td>submitted_at</td><td>DATETIME</td><td>Date de soumission</td></tr>
            <tr><td>closed_at</td><td>DATETIME</td><td>Date de clôture (NULL si en cours)</td></tr>
            <tr><td>rgpd_consent</td><td>INTEGER</td><td>1 = consentement RGPD recueilli</td></tr>
            <tr><td>status</td><td>TEXT</td><td>Statut : en_cours, valide, refuse</td></tr>
          </tbody>
        </table>

        <h3>Table <code>tokens</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>submission_id</td><td>TEXT FK (UUID v4)</td><td>Soumission liée</td></tr>
            <tr><td>step_id</td><td>TEXT FK (UUID v4)</td><td>Étape liée</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
            <tr><td>token</td><td>TEXT UNIQUE</td><td>Jeton unique (64 hex)</td></tr>
            <tr><td>sent_at</td><td>DATETIME</td><td>Date d'envoi de l'email</td></tr>
            <tr><td>done_at</td><td>DATETIME</td><td>Date de validation (NULL = en attente)</td></tr>
            <tr><td>relance_at</td><td>DATETIME</td><td>Date de dernière relance</td></tr>
            <tr><td>expires_at</td><td>DATETIME</td><td>Date d'expiration du token</td></tr>
          </tbody>
        </table>

        <h3>Tables complémentaires</h3>
        <table class="schema-table">
          <thead><tr><th>Table</th><th>Colonnes clés</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>admins</td><td>email (UNIQUE), added_at</td><td>Liste des administrateurs</td></tr>
            <tr><td>admin_requests</td><td>email, status, token</td><td>Demandes d'accès en attente</td></tr>
            <tr><td>settings</td><td>key (PK), value, updated_at, updated_by</td><td>Paramètres configurables (SMTP, délais, webhooks…)</td></tr>
            <tr><td>audit_log</td><td>action, target, detail, actor, created_at</td><td>Journal d'audit complet</td></tr>
            <tr><td>alert_rules</td><td>form_id, days_before, condition_type, notify_who</td><td>Règles d'alerte de deadline</td></tr>
            <tr><td>alert_log</td><td>rule_id, submission_id, sent_at</td><td>Historique des alertes envoyées</td></tr>
            <tr><td>delegations</td><td>id TEXT PK (UUID v4), token_id TEXT FK (UUID v4), from_email, to_email, reason, delegated_at</td><td>Historique des délégations</td></tr>
            <tr><td>form_fields</td><td>id TEXT PK (UUID v4), form_id TEXT FK (UUID v4), label, type, options, hint, required, ordre</td><td>Champs dynamiques des formulaires</td></tr>
            <tr><td>attachments</td><td>id TEXT PK (UUID v4), submission_id TEXT FK (UUID v4), filename, mime_type, data (BLOB)</td><td>Pièces jointes sécurisées</td></tr>
            <tr><td>form_owners</td><td>id TEXT PK (UUID v4), form_id TEXT FK (UUID v4), email</td><td>Propriétaires de formulaires (droits de gestion déléguée)</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details>
      <summary>Mécanisme d'authentification</summary>
      <div class="detail-body">
        <p>L'application s'appuie sur <strong>l'authentification Windows (IIS)</strong> :</p>
        <ul>
          <li>Le serveur web IIS fournit la variable <code>$_SERVER['AUTH_USER']</code> contenant le compte Windows de l'utilisateur (ex : <code>DREETS\prenom.nom</code>).</li>
          <li>La fonction <code>get_auth_user()</code> transforme ce compte en adresse email (ex : <code>prenom.nom@<?= h(get_setting('email_domain', 'exemple.invalid')) ?></code>).</li>
          <li>Les pages <code>index.php?p=form</code>, <code>index.php?p=dashboard</code>, <code>index.php?p=admin_forms</code> et <code>index.php?p=admin_access</code> nécessitent cette authentification.</li>
          <li>La page <code>index.php?p=validate</code> est accessible <strong>sans authentification</strong> (les validateurs externes n'ont pas forcément de compte DREETS).</li>
        </ul>
        <p>Le contrôle des droits administrateur se fait par vérification de la présence de l'email dans la table <code>admins</code>.</p>
      </div>
    </details>

    <details>
      <summary>Pile technique</summary>
      <div class="detail-body">
        <table class="schema-table">
          <thead><tr><th>Composant</th><th>Technologie</th></tr></thead>
          <tbody>
            <tr><td>Serveur web</td><td>IIS (Windows Server) avec authentification Windows</td></tr>
            <tr><td>Langage</td><td>PHP 8.4+ (procédural, sans framework)</td></tr>
            <tr><td>Base de données</td><td>SQLite (fichier db/workflow.db, mode WAL)</td></tr>
            <tr><td>Envoi d'emails</td><td>PHPMailer via SMTP (pas d'auth SMTP)</td></tr>
            <tr><td>Relance automatique</td><td>Lazy cron (remind.php, 1×/heure) — pas de Planificateur Windows requis</td></tr>
            <tr><td>Vérification des alertes</td><td>Lazy cron (alert_check.php, 1×/jour)</td></tr>
            <tr><td>Motif de sécurité</td><td>Tokens à usage unique (random_bytes 32 octets = 64 hex)</td></tr>
            <tr><td>Frontend</td><td>HTML/CSS embarqué, zéro JavaScript framework</td></tr>
            <tr><td>Design</td><td>Marianne (DSFR), conforme RGAA</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details>
      <summary>Flux de données typique</summary>
      <div class="detail-body">
        <ol>
          <li><strong>Agent</strong> accède à <code>index.php?p=form&f=onboarding</code> et remplit le formulaire.</li>
          <li>Les données sont enregistrées dans <code>submissions</code> (champ <code>data</code> en JSON).</li>
          <li><code>advance_workflow()</code> est appelée : elle crée les tokens pour l'étape d'ordre 1 et envoie les emails.</li>
          <li><strong>Validateur</strong> clique sur le lien dans l'email → arrive sur <code>index.php?p=validate&token=…</code></li>
          <li>Il valide ou refuse. <code>validate_token()</code> met à jour <code>done_at</code> et rappelle <code>advance_workflow()</code>.</li>
          <li>Si validé : les tokens de l'étape suivante sont créés et les emails envoyés.</li>
          <li>Si refusé : le statut de la demande passe à <code>refuse</code> et <code>closed_at</code> est renseigné.</li>
          <li>Quand toutes les étapes sont validées : <code>closed_at</code> est renseigné → la demande est clôturée. Un webhook est envoyé si configuré.</li>
          <li>En parallèle, <code>remind.php</code> tourne une fois par heure (lazy cron) et envoie des relances aux validateurs en attente depuis plus de 48h.</li>
          <li>En parallèle, <code>alert_check.php</code> tourne une fois par jour (lazy cron) et envoie les alertes J-N configurées.</li>
        </ol>
      </div>
    </details>
  </div>
    <?php
    // rtrim supprime les espaces de l indentation avant la balise fermante
    return rtrim((string) ob_get_clean(), " \t");
}
