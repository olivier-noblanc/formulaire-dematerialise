# Changelog — Formulaire Dématérialisé DREETS

## [4.5.0] — 2026-06-15

### Navigation uniformisée — Critique

- **Navigation centralisée** : Les 9 pages qui utilisaient un bandeau manuel (`<div class="bandeau">`) avec des liens incohérents utilisent désormais toutes `render_nav()`. La navigation est identique sur toutes les pages : Accueil, Mes demandes, Mes validations, Documentation (+ liens admin pour les administrateurs). Les sous-pages admin (Monitoring, Alertes, Statistiques, etc.) sont accessibles via des liens contextuels dans la barre de navigation.

- **Fil d'Ariane sur toutes les pages** : Chaque page affiche désormais un fil d'Ariane (`render_breadcrumb()`) pour que l'utilisateur sache toujours où il se trouve et puisse revenir en arrière.

- **Liens « Accueil » et « Mes validations »** sur toutes les pages d'erreur (validate.php : lien invalide, déjà validé, workflow terminé, lien expiré). Plus aucune impasse de navigation.

### Accessibilité RGAA — Critique

- **Contraste couleurs corrigé** : Les liens du bandeau passent de `#b3c8f0` (contraste 3.5:1, non conforme WCAG AA) à `#c8dbf5` (contraste 4.7:1, conforme). Les textes d'aide passent de `#888` (3.5:1) à `#595959` (5.3:1). Le footer passe de `#888` à `#595959`.

- **Emojis décoratifs avec `aria-hidden="true"`** : Tous les emojis décoratifs (🏠📋✅📖📊⚙🔔🖥📈🔐💾🏥✅❌⏳📎📧🔄🗑⚠) sont désormais enveloppés dans `<span aria-hidden="true">` pour ne pas perturber les lecteurs d'écran.

- **Landmarks HTML5** : Les pages `admin_access.php`, `admin_alerts.php`, `backup.php`, `form_preview.php`, `form_tracking.php`, `submission_view.php` utilisent désormais `<main>` au lieu de `<div class="container">`.

- **Skip-link** : Toutes les pages ont un lien d'évitement « Aller au contenu principal ».

### Confirmation de refus — Haut

- **Commentaire obligatoire pour le refus** : Le bouton « Refuser » sur la page de validation requiert désormais un motif dans le champ commentaire. Sans commentaire, le refus est bloqué avec un message d'erreur explicite. Le label du champ commentaire indique « obligatoire en cas de refus ».

### Post-soumission — Haut

- **Liens après soumission** : Après la soumission d'un formulaire, l'utilisateur voit désormais trois boutons : « Voir ma demande » (lien direct vers submission_view.php), « Mes demandes » et « Accueil ». Plus de page orpheline après soumission.

### Administration

- **Email admin en base de données** : L'email de l'administrateur principal (anciennement `ADMIN_EMAIL` dans config.php) est désormais stocké dans la table `settings` et modifiable depuis l'interface d'administration (Paramètres → Administration). La constante `ADMIN_EMAIL` reste en fallback. Migration v11.

- **Clôturés → Validés + Refusés** : Le tableau de bord admin sépare désormais les soumissions clôturées en « Validés » et « Refusés » (statistiques + filtres). Le filtre « Clôturés » reste disponible pour compatibilité.

- **Section « Administration »** dans admin_settings.php : Nouveau champ pour modifier l'email de l'administrateur principal.

### Technique

- **`render_nav()` améliorée** : Nouveau paramètre `$extra_admin_links` pour ajouter des liens contextuels admin (Monitoring, Alertes, etc.) selon la page courante. Utilisation de classes CSS au lieu de styles inline pour la navigation.

- **`get_admin_email()`** : Nouvelle fonction qui récupère l'email admin depuis la base de données avec fallback sur la constante `ADMIN_EMAIL`. `is_super_admin()` utilise cette fonction.

- **`run_lazy_cron()` corrigé** : Ajout d'un guard `static $running` pour empêcher la récursion infinie (get_pdo() → run_lazy_cron() → get_pdo()).

- **CSS centralisé** : Ajout de classes CSS pour la navigation (`nav-brand`, `nav-main`, `nav-admin`, `nav-user`, `nav-badge`, `nav-active`), le fil d'Ariane (`breadcrumb`, `separator`, `current`), les détails/summary (`details`, `summary`). Styles responsive améliorés pour le bandeau et les tableaux.

- **`<details>/<summary>` HTML5** : Nouveaux styles CSS pour les éléments `<details>/<summary>` (accordéon sans JavaScript), utilisables dans toutes les pages.

## [4.4.0] — 2026-06-15

### Sécurité email — Critique

- **Mode Dry-Run (mail_dry_run)** : Nouveau paramètre activable dans l'administration qui intercepte **tous** les envois d'emails. En mode dry-run, `send_mail()` journalise chaque tentative d'envoi dans l'audit log sans contacter le serveur SMTP. Le workflow continue normalement (tokens créés, étapes avancées). **Activé par défaut** lors de la migration — un administrateur doit explicitement le désactiver pour autoriser les envois réels.

- **Vérification LDAP / Active Directory** : Nouveau mode de vérification des adresses destinataires avant envoi. Si activé, le système se connecte à l'Active Directory en lecture seule (bind anonyme ou compte de service) et recherche l'adresse email dans l'annuaire. Si l'adresse est introuvable, l'envoi est bloqué et journalisé. Configuration complète dans la section admin : hôte LDAP, port, base DN, bind DN/mot de passe, filtre de recherche.

- **Vérification SMTP (probe RCPT TO)** : Mode alternatif de vérification des adresses. Le système ouvre une connexion SMTP, envoie `HELO`, `MAIL FROM`, `RCPT TO` et vérifie si le serveur accepte le destinataire, puis se déconnecte proprement (`QUIT` avant `DATA`). Supporte STARTTLS. Attention : certains serveurs Exchange acceptent toutes les adresses en RCPT TO (mode catch-all), ce qui rend cette vérification moins fiable que LDAP.

- **Blocage des emails non vérifiés** : Si la vérification est activée (LDAP ou SMTP) et qu'une adresse échoue, `send_mail()` retourne `false` et journalise l'événement dans l'audit log (`mail_blocked`). Cela empêche l'envoi à des adresses placeholder ou inexistantes.

- **Audit log des emails** : Chaque appel à `send_mail()` est désormais journalisé dans l'audit log avec le type `mail_sent`, `mail_dry_run`, `mail_blocked` ou `mail_error`, incluant le destinataire, le sujet et le détail.

- **Ordre de priorité dans send_mail()** : TEST_MODE → Dry-Run → Vérification email → Blocage CLI → Envoi réel PHPMailer. Chaque couche de protection est évaluée dans l'ordre.

### Administration

- **Section « Sécurité email »** dans admin_settings.php : Nouvelle interface de configuration avec :
  - Toggle Dry-Run avec explication claire
  - Sélecteur du mode de vérification (aucun / LDAP / SMTP)
  - Configuration LDAP complète (hôte, port, base DN, bind DN, mot de passe, filtre)
  - Information sur les limitations du mode SMTP
  - Détection automatique de l'extension PHP LDAP
  - Bouton de test de vérification email avec résultat détaillé

- **Résumé de sécurité email** : Tableau de bord affichant le statut de chaque couche de protection (Dry-Run, vérification, PHPMailer stub/real, blocage CLI) et un score de sécurité sur 4.

- **Badge Dry-Run** : Avertissement visuel en haut de la page quand le mode Dry-Run est actif.

- **Slug auto-généré** : Le champ « Slug » n'est plus visible dans l'interface d'administration. L'identifiant technique est désormais généré automatiquement à partir du libellé du formulaire (ex: « Demande de congé » → `demande_de_conge`). Si un slug existe déjà, un suffixe numérique est ajouté automatiquement. En édition, le slug actuel est affiché en hint en lecture seule. La documentation a été mise à jour en conséquence.

- **Version footer/docs** : Correction du fallback de version dans `docs.php` (était `4.3.1`, désormais `4.4.0`).

### Base de données

- **Migration v10** : Ajout des paramètres `mail_dry_run` (défaut : `1`), `email_verify_mode` (défaut : `none`), `ldap_host`, `ldap_port`, `ldap_base_dn`, `ldap_bind_dn`, `ldap_bind_pass`, `ldap_filter` dans la table `settings`.

### Version

- Passage de `4.3.1` à `4.4.0` (version mineure : nouvelle fonctionnalité de sécurité email).

## [4.3.1] — 2026-06-15

### Sécurité — Critique

- **Protection contre l'envoi d'emails lors des tests** : Les scripts de test (`test_all.php`, `test_e2e.php`) forcent désormais le mode `TEST_MODE` pour intercepter tous les appels à `send_mail()`. Aucun email réel ne peut plus être envoyé pendant l'exécution des tests. Toutes les adresses de test utilisent le domaine `@e2e.test` (réservé RFC 2606, impossible qu'il soit réel).

- **Garde-fou CLI pour `send_mail()`** : Ajout d'un blocage automatique de l'envoi d'emails en contexte CLI sauf si la constante `CLI_MAIL_ALLOWED` est définie. Les scripts légitimes `remind.php` et `alert_check.php` déclarent cette constante. Cela empêche tout envoi accidentel d'emails depuis un script de test ou une exécution CLI inattendue.

### Tests

- **80 tests E2E intensifs** (`test_e2e.php`) : Nouveau script de test end-to-end couvrant 15 catégories — soumission de formulaires, avancement du workflow complet, validation étape par étape, refus, annulation, délégation, cas limites de sécurité (tokens invalides/expirés, injection SQL, XSS, CSRF), uploads de fichiers (BLOB), formulaire outboarding, fonctions utilitaires, intégrité des données, conformité RGPD, relance/expiration des tokens, types de champs. Résultat : **80/80 tests passent**.

- **51 tests unitaires** (`test_all.php`) : Mise à jour pour la compatibilité avec le mode TEST (authentification via `X-Test-User`, CSRF bypass testé via `hash_equals()`). Résultat : **51/51 tests passent**.

- **Total : 131/131 tests passent**, zéro email réel envoyé.

### Documentation

- **Schéma de base de données corrigé** : La section technique de `docs.php` affichait `INTEGER PK` pour toutes les tables — désormais corrigé en `TEXT PK (UUID v4)` et `TEXT FK (UUID v4)` pour refléter la migration UUID. Ajout des tables `form_owners`, `delegations` et de la colonne `rgpd_consent`.

- **Bouton retour en haut** : Ajout d'un bouton flottant « ↑ » en bas à droite (CSS pur, zéro JavaScript) pour remonter en haut de la page de documentation (1700+ lignes).

- **Indicateur de version** : Affichage du badge `v4.3.0` en haut de la page de documentation.

- **Captures d'écran manquantes** : Intégration de `13_docs.png` (page d'aide) et `14_changelog.png` (journal des modifications) qui existaient dans le dossier mais n'étaient pas affichées.

- **Version PHP corrigée** : « PHP 7.4+ » → « PHP 8+ » dans la section architecture technique.

- **Table des types de champs** : Ajout d'un tableau de référence des 6 types de champs disponibles (text, date, select, checkbox, textarea, file) dans le guide administrateur.

- **FAQ déploiement IT** : Nouvelle entrée FAQ pour l'équipe technique avec les prérequis système, les étapes d'installation et les tâches planifiées.

- **Avertissement sur les emails de seeding** : Commentaire dans `helpers.php` indiquant que les adresses email par défaut dans le seeding sont des valeurs à remplacer par l'administrateur.

## [4.3.0] — 2026-06-14

### Sécurité — Majeure

- **Zéro ID entier dans toute l'application** : Toutes les clés primaires et étrangères de la base de données sont désormais des UUID (TEXT) au lieu d'entiers auto-incrémentés. Plus aucune table n'utilise `INTEGER PRIMARY KEY AUTOINCREMENT`. Les 15 tables d'entités utilisent `id TEXT PRIMARY KEY NOT NULL` avec des UUID v4 générés par `generate_uuid()`. Les colonnes `uuid` de la table `forms` ont été supprimées (l'`id` EST l'UUID). Cela rend impossible la devinette ou l'énumération d'identifiants dans les URLs.

- **URLs entièrement en UUID** : Tous les paramètres d'URL (`?id=`, `?form_id=`, `?submission_id=`, `?token_id=`, `?step_id=`, `?rule_id=`, etc.) utilisent désormais des UUID non devinables au lieu d'entiers séquentiels. Plus d'attaque IDOR possible par énumération.

### Architecture

- **Suppression complète de `lastInsertId()`** : Les 29 appels à `$pdo->lastInsertId()` ont été remplacés par des UUIDs pré-générés avant chaque INSERT. Chaque INSERT inclut désormais explicitement la colonne `id` avec la valeur UUID.

- **Migration v9** : Migration complète des bases existantes — recréation de toutes les tables avec clés TEXT, mapping des anciens IDs entiers vers les nouveaux UUIDs, préservation de toutes les données et relations.

- **Signatures de fonctions** : Tous les paramètres d'ID sont passés de `int` à `string` (`int $form_id` → `string $form_id`). Suppression de tous les casts `(int)` sur les variables d'ID dans tout le codebase.

### Pages d'erreur visuelles

- **`render_error_page()`** : Nouvelle fonction réutilisable pour afficher des pages d'erreur HTML complètes et soignées (403, 404, 400, 401, 500). Chaque code a son icône SVG, son code HTTP en gros, un message descriptif, un encart « Que faire ? » et un bouton de retour. Remplace tous les appels `die()` avec texte brut (20+ remplacements à travers 13 fichiers). La page 401 (authentification) a été mise à jour pour matcher le même design.

### Base de données

- 15 tables migrées de `INTEGER PRIMARY KEY AUTOINCREMENT` vers `id TEXT PRIMARY KEY NOT NULL`
- Toutes les colonnes FK migrées de `INTEGER` vers `TEXT`
- Colonne `uuid` supprimée de la table `forms` (l'`id` est l'UUID)
- Migration v9 : reconstruction complète avec mapping old_int → new_uuid

### Corrections

- **Colonne `hint` manquante dans `form_fields`** : Le `CREATE TABLE` initial de `form_fields` ne contenait pas la colonne `hint`, qui était ajoutée via `ALTER TABLE` en legacy. Cela causait un crash lors de l'initialisation d'une base neuve. La colonne est désormais dans la définition de la table.
- **`generate_field_name()` crashait sans `mbstring`/`intl`** : La fonction appelait `mb_strtolower()` et `transliterator_transliterate()` sans vérifier la disponibilité des extensions. Ajout de fallbacks via `strtolower()` et remplacement manuel des caractères accentués.
- **`test_all.php` inutilisable avec les UUIDs** : Les requêtes SQL du test utilisaient les UUIDs sans quotes (`WHERE form_id=$onboarding_id`), `generate_uuid()` était appelé dans le SQL comme fonction SQLite, et les tests de pages utilisaient des IDs entiers (`'1'`). Réécriture complète du fichier de test avec prepared statements et UUIDs corrects.
- **Onboarding et outboarding sans recipients** : Les formulaires onboarding et outboarding étaient seedés avec des étapes mais sans destinataires (`step_recipients`) ni propriétaires (`form_owners`). Le workflow ne pouvait donc pas démarrer pour ces deux formulaires. Ajout des recipients par défaut (responsable.direct, informatique, rh, logistique) et des owners.
- **4 screenshots manquants dans la documentation** : Les captures d'écran disponibles (`04_form_outboarding`, `09_admin_access`, `16_submission_view`, `17_form_preview`) n'étaient pas intégrées dans `docs.php`. Ajout dans les sections Guide de l'agent, Guide du validateur et Guide de l'administrateur.

## [4.2.0] — 2026-06-14

### Sécurité

- **UUID pour les formulaires** : Les formulaires sont désormais identifiés par un UUID v4 (RFC 4122) dans les URLs au lieu d'un identifiant entier prédictible. Les URLs de suivi propriétaire passent de `form_tracking.php?form_id=3` à `form_tracking.php?f=a1b2c3d4-e5f6-7890-abcd-ef1234567890`. Colonnes `uuid` ajoutée à la table `forms` (migration v7). Fonction `generate_uuid()` et `get_form_by_uuid()` ajoutées.

- **Validation HTML5 native** : Les champs de formulaire utilisent désormais les types HTML5 appropriés (email, tel, number, time, url) détectés automatiquement à partir du nom du champ. Ajout de `pattern`, `maxlength`, `min/max`, `step` pour une validation côté navigateur sans JavaScript. Retrait de l'attribut `novalidate` qui désactivait la validation HTML5.

### Architecture

- **Lazy cron (pas de Task Scheduler)** : Les tâches planifiées (relances et alertes) sont désormais exécutées par le premier utilisateur qui se connecte, au lieu d'un cron externe. `run_lazy_cron()` est appelé automatiquement au premier accès PDO. La table `lazy_cron` (migration v8) trace la dernière exécution de chaque tâche. Le remind s'exécute toutes les heures, l'alert_check une fois par jour.

- **Cloisonnement propriétaire** : Les tableaux de suivi (`form_tracking.php`) sont strictement isolés — seuls les owners du formulaire et les administrateurs peuvent y accéder. Les autres utilisateurs n'ont aucun moyen de voir les données ou les stats d'un formulaire dont ils ne sont pas propriétaires.

### Interface

- **Pages d'erreur visuelles** : Toutes les pages d'erreur (403 Accès refusé, 404 Introuvable, 400 Requête invalide, 401 Authentification requise) sont désormais des pages HTML complètes et soignées au lieu de texte brut. Nouvelle fonction `render_error_page()` dans helpers.php, avec icône SVG, code HTTP en gros, message descriptif, encart « Que faire ? » et bouton de retour. Style cohérent avec le design DREETS (bandeau, Marianne, palette #003189/#c0392b/#b45309). CSS dédié dans `style.php`. Les 13 appels `die()` avec messages CSRF affichent également une page 403 soignée au lieu d'un texte brut.

### Base de données

- Colonne `uuid TEXT UNIQUE` sur `forms` : identifiant non devinable pour les URLs publiques (migration v7).
- Table `lazy_cron` : suivi de la dernière exécution des tâches planifiées (migration v8).

## [4.1.0] — 2026-06-14

### Fonctionnalités majeures

- **Propriétaires de formulaire** : Nouveau concept de propriétaires (owners) par formulaire. Les owners peuvent accéder à un tableau de suivi dédié sans être administrateurs. Chaque formulaire peut avoir un ou plusieurs propriétaires, gérés depuis le form builder admin. Nouvelle table `form_owners` (migration version 6).

- **Tableau de suivi propriétaire** : Nouvelle page `form_tracking.php` réservée aux propriétaires d'un formulaire. Affiche toutes les soumissions du formulaire avec colonnes clés, barre d'avancement, filtres par statut, pagination, export CSV. Accessible aussi aux administrateurs.

- **Formulaires métier recalibrés** : Remplacement des formulaires "Demande de congé" et "Signalement" (déplacés vers d'autres applications) par les trois formulaires métier réels de la DREETS :
  - **Demande de sortie hors plages fixes** : autorisation d'arrivée tardive, départ anticipé, pause prolongée (circuit Chef de service → DRH).
  - **Remboursement d'avance de frais** : déplacement, hébergement, repas, fournitures (circuit Chef de service → Comptabilité → Agent financier).
  - **Demande de matériel suite prescription médicale** : aménagement de poste, équipement ergonomique (circuit Médecin de prévention → Chef de service → DSI + Logistique parallèle → DRH).

### Base de données

- Table `form_owners` : relation formulaire ↔ propriétaires avec email et date d'ajout.
- Migration version 6 : création automatique de la table `form_owners`.
- Nouveaux formulaires seedés : `sortie_hors_plages`, `remboursement_avance_frais`, `materiel_prescription` (avec owners pré-configurés).

### Fonctions

- `is_form_owner(string $form_id, ?string $email)` : vérifie si un utilisateur est propriétaire d'un formulaire.
- `get_form_owners(string $form_id)` : retourne la liste des propriétaires d'un formulaire.
- `get_owned_forms(?string $email)` : retourne les formulaires dont un utilisateur est propriétaire.

### Pages

- `form_tracking.php` : tableau de suivi propriétaire avec filtres, pagination, export CSV.
- `admin_forms.php` : nouvelle section "Propriétaires du formulaire" avec ajout/retrait d'owners.
- `confirm_action.php` : support de l'action `remove_owner`.
- `index.php` : liens dynamiques vers les tableaux de suivi des formulaires possédés.

## [4.0.0] — 2026-06-14

### Fonctionnalités majeures

- **Pièces jointes en BLOB SQLite** : Les fichiers uploadés sont désormais stockés directement dans la base de données SQLite sous forme de BLOB, éliminant tout besoin de droits filesystem et garantissant le caractère mono-fichier de l'application. La compatibilité descendante avec les anciens fichiers sur disque est maintenue dans `download.php`.

- **Conformité RGPD complète** : Nouvelle page `rgpd.php` dédiée à la conformité RGPD, incluant : mentions légales configurables, durée de conservation paramétrable, export des données d'un agent (droit d'accès art. 15), suppression/anonymisation des données (droit à l'effacement art. 17), purge automatique des données anciennes, statistiques de volume de données. Consentement RGPD obligatoire à la soumission des formulaires.

- **Statistiques par période** : Nouvelle page `stats.php` avec tableaux de bord visuels : répartition des statuts (donut chart CSS), évolution par semaine/mois/année (barres empilées CSS), performance par formulaire, performance par validateur, volume de données. Aucun JavaScript requis — tous les graphiques sont en CSS pur.

- **Health check** : Nouvelle page `health.php` accessible sans authentification, vérifiant : connectivité SQLite, version PHP, répertoire accessible en écriture, schéma de base initialisé, configuration SMTP. Retourne HTTP 200/503 et JSON pour les outils de monitoring (`?format=json`).

- **Webhooks pour intégration SI** : Configuration d'URL webhook dans les paramètres admin. Notifications automatiques en POST JSON pour les événements `workflow_complete`, `submission_cancelled`, `token_validated`. Format structuré avec événement, timestamp et données. Bouton de test disponible.

- **Historique des relances** : Section dédiée dans le détail de soumission affichant l'historique complet des relances avec dates, validateurs concernés et compteur. Bouton "Rappeler tous les validateurs en attente" pour les administrateurs.

- **Versionnage du schéma de base** : Table `schema_version` pour suivre les migrations applicatives. Chaque migration est versionnée et idempotente, permettant les mises à jour automatiques et sans risque.

### Fonctionnalités

- **Recherche plein texte étendue** : La recherche du dashboard couvre désormais les noms de formulaires en plus des agents et des données JSON.

- **Formulaires pré-configurés supplémentaires** : Trois nouveaux formulaires sont pré-chargés à l'installation : "Demande de congé" (circuit Chef de service → DRH), "Demande de matériel" (Chef de service → DSI), "Signalement interne" (RH + Encadrant en parallèle → Direction).

- **Rate limiting** : Protection contre les abus avec limitation configurable par IP et par action. Appliqué notamment sur les exports et suppressions RGPD.

- **Fonctions de sécurité** : `sanitize_input()` pour le nettoyage des entrées, `validate_email()` pour la validation d'emails, `rate_limit_check()` pour la limitation de débit.

- **Documentation refondue** : Documentation complète et accessible aux non-techniciens avec guide de démarrage rapide (3 étapes), guides détaillés par rôle (agent, validateur, admin), FAQ étendue (18 questions), matrice des permissions, fiche fonctionnalités, et section RGPD.

- **Navigation unifiée** : Tous les bandeaux de navigation incluent désormais des liens vers Statistiques, RGPD et Santé système.

### Base de données

- Table `schema_version` : suivi des versions de schéma de base de données.
- Colonne `file_data BLOB` sur `attachments` : stockage des fichiers en base.
- Colonne `rgpd_consent INTEGER` sur `submissions` : traçabilité du consentement RGPD.
- Table `rate_limits` : suivi des tentatives pour le rate limiting.
- Paramètres `legal_mentions`, `retention_months`, `webhook_url`, `webhook_events` dans la table `settings`.

### Sécurité

- Stockage BLOB des fichiers : plus d'accès filesystem requis, élimination des risques de path traversal.
- Rate limiting sur les actions sensibles (export RGPD, suppression RGPD).
- Fonctions de sanitisation des entrées utilisateur.
- Consentement RGPD obligatoire avant soumission de formulaire.
- Purge automatique des données anciennes configurable.

### Technique

- `APP_VERSION` passé à `4.0.0`.
- Nouvelles fonctions dans `helpers.php` : `rgpd_export_user_data()`, `rgpd_delete_user_data()`, `rgpd_auto_purge()`, `rate_limit_check()`, `sanitize_input()`, `validate_email()`, `search_submissions()`, `get_stats_by_period()`, `get_global_stats()`, `send_webhook()`.
- Webhook calls ajoutés dans `advance_workflow()`, `validate_token()`, `cancel_submission()`.
- Nouveaux fichiers : `health.php`, `rgpd.php`, `stats.php`.

## [3.1.0] — 2026-06-14

### Fonctionnalités majeures

- **Pièces jointes** : Nouveau type de champ `file` permettant aux agents de joindre des fichiers (PDF, images, Office, ZIP) lors de la soumission d'un formulaire. Les fichiers sont stockés de manière sécurisée avec validation du type MIME et de l'extension, protection anti-traversal, et taille maximale de 10 Mo. Le téléchargement sécurisé passe par `download.php` avec contrôle d'accès (admin, propriétaire, validateur). Les pièces jointes sont visibles dans le détail de la soumission et lors de la validation.

- **Délégation de validation** : Un validateur peut désormais déléguer sa validation à un autre agent lorsqu'il est absent ou indisponible. Le mécanisme crée un nouveau token pour le délégataire, marque l'ancien token comme traité, et envoie un email de notification aux deux parties. L'historique des délégations est visible dans le détail de la soumission. La délégation est accessible depuis `my_validations.php` et `submission_view.php`.

- **Rappel manuel** : Nouveau bouton "📧 Rappeler" dans le dashboard admin et dans le détail de soumission permettant d'envoyer un email de rappel à un validateur en attente. Contrairement à la régénération de token, le rappel ne modifie pas le token existant — il envoie simplement un email avec le compteur de relances. Le nombre de relances maximum est configurable (3 par défaut).

### Fonctionnalités

- **Affichage du nombre de relances** : Le compteur de relances (`relance_count`) est désormais visible dans le diagramme de workflow de `submission_view.php`, à côté de l'email du validateur en attente.

- **Recherche dans "Mes validations"** : Nouveau champ de recherche dans la page `my_validations.php` permettant de filtrer les validations en attente par nom de formulaire ou contenu des données.

- **Recherche et filtres dans "Mes demandes"** : Nouveau champ de recherche et filtres par statut (Tous / En cours / Validées / Refusées) dans `my_validations.php` permettant aux agents de retrouver facilement leurs soumissions.

### Base de données

- Nouvelle table `attachments` : stockage des fichiers joints aux soumissions (nom original, nom stocké, type MIME, taille).
- Nouvelle table `delegations` : traçabilité des délégations de validation (depuis/vers email, motif, token associé).
- Protection du répertoire d'upload avec `.htaccess` et `index.php` vide.

### Sécurité

- Validation des fichiers uploadés : vérification du type MIME (via `finfo`), de l'extension, et de la taille.
- Extension whitelist : PDF, JPG, PNG, GIF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, ZIP.
- Protection anti-directory-traversal dans `download.php` avec `realpath()`.
- Contrôle d'accès strict sur le téléchargement : admin, propriétaire de la soumission, ou validateur uniquement.

## [3.0.0] — 2026-06-14

### Changement majeur

- **Suppression complète du JavaScript** : Toutes les fonctionnalités JavaScript ont été remplacées par des alternatives PHP/CSS pures, conformément à la philosophie du projet. Les toggles de détail utilisent désormais des liens directs, les onglets utilisent des paramètres GET, les selects de filtrage ont des boutons de soumission, et les confirmations d'actions destructrices passent par une page de confirmation serveur. L'application est désormais 100% sans JavaScript.

### Fonctionnalités majeures

- **Assistant d'installation (`install.php`)** : Nouvelle page de première installation qui guide l'administrateur à travers la configuration initiale. Vérification automatique des prérequis (PHP 8+, SQLite3, intl, PHPMailer, permissions d'écriture), formulaire de configuration SMTP et administrateur avec test d'envoi d'email, génération automatique du fichier `config.php`. Accessible uniquement si `config.php` n'existe pas encore.

- **Sauvegarde et restauration (`backup.php`)** : Nouvelle page d'administration permettant de télécharger une copie de la base SQLite, restaurer une base depuis un fichier (avec validation et sauvegarde préalable automatique), purger les anciennes données (soumissions clôturées de plus de 6/12/18/24 mois, avec prévisualisation du compte avant exécution), et consulter les statistiques de la base (taille, nombre de lignes par table, âge des données, pages SQLite). Processus de purge en deux étapes pour éviter les erreurs.

- **Page de confirmation serveur (`confirm_action.php`)** : Nouvelle page remplaçant les boîtes de dialogue JavaScript `confirm()` pour les actions destructrices (annulation de soumission, régénération de token, suppression de règle, purge de logs, suppression d'administrateur). Affiche un récapitulatif de l'action et demande confirmation via un formulaire POST avant exécution.

### Fonctionnalités

- **Duplication de formulaires** : Nouveau bouton "📋 Dupliquer" dans le form builder permettant de copier un formulaire existant avec tous ses champs, étapes et destinataires. Le formulaire dupliqué reçoit le suffixe "(copie)" dans son libellé et "-copie" dans son slug.

- **Prévention des doublons** : Lorsqu'un agent remplit un formulaire pour lequel il a déjà une soumission en cours, un avertissement s'affiche avec la date de la soumission existante et un lien pour la consulter. L'agent peut tout de même soumettre une nouvelle demande.

- **Recherche dans le dashboard** : Nouveau champ de recherche dans le dashboard de supervision permettant de filtrer les soumissions par nom ou email d'agent. Le paramètre de recherche est préservé dans les filtres et la pagination.

- **Styles d'impression** : Ajout de règles `@media print` dans le CSS partagé pour permettre l'impression propre des soumissions et du dashboard. Le bandeau, le footer, les boutons et les filtres sont masqués à l'impression. Les URLs des liens sont affichées après le texte du lien.

### Accessibilité (RGAA)

- **Lien d'évitement** : Ajout d'un lien "Aller au contenu principal" (skip link) sur toutes les pages, visible uniquement au focus clavier, permettant de sauter le bandeau de navigation.
- **Focus visible** : Ajout d'un contour bleu de 3px (`:focus-visible`) sur tous les éléments interactifs (liens, boutons, champs, selects) pour une navigation clavier conforme RGAA.
- **Classe `.sr-only`** : Ajout d'une classe utilitaire pour le contenu destiné uniquement aux lecteurs d'écran.

### Nettoyage

- Suppression de toutes les balises `<script>`, attributs `onclick`, `onsubmit`, `onchange` de l'ensemble des fichiers PHP (form.php, dashboard.php, my_validations.php, monitoring.php, submission_view.php, admin_forms.php, admin_alerts.php, admin_access.php, form_preview.php).
- Remplacement des toggles JavaScript par des liens PHP directs.
- Remplacement des onglets JavaScript par un système d'onglets basé sur les paramètres GET.
- Remplacement des selects auto-soumis par des formulaires avec bouton de soumission.
- Remplacement du toggle d'édition des règles d'alerte par un paramètre GET `?edit_rule=X`.

## [2.5.0] — 2026-06-13

### Fonctionnalités majeures

- **Refonte UX du form builder (`admin_forms.php`)** : Amélioration majeure pour rendre la création de formulaires accessible aux non-techniques. Auto-génération du nom technique (`field_name`) à partir du libellé (ex: "Date de prise de poste" → `date_de_prise_de_poste`) via `generate_field_name()`. Saisie des options de sélecteur simplifiée : une option par ligne au lieu du JSON (via `parse_options_input()`). Suggestions des groupes de cartes existants sous forme de liste déroulante. Icônes par type de champ (📝📅📋☑). Étoile rouge pour les champs obligatoires. Diagramme visuel du circuit de validation (flowchart CSS horizontal avec boîtes connectées par des flèches, destinataires affichés dans chaque étape). Bouton "👁 Prévisualiser le formulaire" pour voir le rendu final.

- **Prévisualisation du formulaire (`form_preview.php`)** : Nouvelle page permettant aux administrateurs de voir exactement comment un formulaire apparaîtra pour l'agent. Affiche le formulaire en mode lecture seule avec les champs désactivés, le circuit de validation en diagramme horizontal, et un bandeau "Mode prévisualisation" bien visible.

- **Page de détail soumission (`submission_view.php`)** : Nouvelle page dédiée offrant une vue complète et visuelle d'une soumission. Comprend : barre de progression avec pourcentage, diagramme workflow horizontal (boîtes colorées : vert=validé, orange=en cours, gris=à venir, rouge=refusé), carte deadline avec code couleur urgence, données du formulaire regroupées par section, historique des validations avec commentaires, actions admin (régénération de token, annulation). Accessible depuis le dashboard (lien "voir") et depuis "Mes demandes".

- **Refonte de "Mes demandes" (`my_submissions.php`)** : Amélioration visuelle majeure. Barres de progression par soumission (pourcentage + ratio d'étapes), timeline compacte avec code couleur, badges deadline (🚨 J+, ⚠️ J-2, 📅 J-5), lien "👁 Voir le détail" vers la page de détail, cartes cliquables, mise en page moderne.

- **Page d'accueil par rôle (`index.php`)** : Refonte complète de la page d'accueil pour s'adapter au rôle de l'utilisateur. Pour les agents : statistiques personnelles, formulaires disponibles sous forme de cartes cliquables, accès rapide (Mes demandes, Mes validations, Documentation). Pour les admins : statistiques globales, tokens bloqués, liens d'administration rapide (Dashboard, Monitoring, Formulaires, Alertes, Paramètres). Design moderne avec hero banner et nav tiles.

- **Graphique camembert CSS dans le monitoring** : Ajout d'un diagramme en anneau (donut chart) en CSS pur (`conic-gradient`) dans la page monitoring, montrant la répartition des soumissions par statut (validées / en cours / refusées) avec légende et pourcentages.

### Fonctionnalités

- **Fonction `generate_field_name()`** : Nouvelle fonction dans `helpers.php` qui convertit un libellé français en identifiant technique snake_case, avec suppression des accents (via `transliterator_transliterate` ou fallback manuel).

- **Fonction `parse_options_input()`** : Nouvelle fonction dans `helpers.php` qui accepte les options de sélecteur soit en JSON, soit une par ligne (format beaucoup plus accessible pour les non-techniques).

- **Lien "voir" dans le dashboard** : Chaque ligne du dashboard de supervision a désormais un lien "voir" à côté du bouton "détail", ouvrant la page de détail complète de la soumission.

- **Infrastructure de test automatisé** : Ajout d'un mode test complet activé par le header HTTP `X-Test-Mode: 1`. En mode test : l'authentification utilise le header `X-Test-User` au lieu de `AUTH_USER` (IIS), le CSRF est bypassé, les emails sont interceptés dans une file d'attente au lieu d'être envoyés, la base de données utilise `workflow_test.db` séparée, les réponses POST sont en JSON au lieu de redirections. API de test (`test_api.php`) avec actions : mails, tokens, submissions, cleanup, seeding, stats. Suite de tests HTTP (`test_http.php`) — 12 phases de tests via curl contre un serveur PHP dédié. Suite de tests CLI existante (`test_all.php`) — 47 tests en subprocess isolation.

- **Captures d'écran de l'application** : 17 captures d'écran haute résolution (1440×900, 2x DPI) ajoutées dans `docs/screenshots/` pour la documentation. Couvrent toutes les vues : agent (accueil, formulaires, suivi, détail), validateur (validations, décision), admin (dashboard, monitoring, form builder, alertes, paramètres, accès, aperçu, docs, changelog).

- **Documentation technique refondue** : Réécriture complète de `AGENT.md` (guide technique IA) et `README.md` avec captures d'écran intégrées, tables de référence, diagramme workflow, section mode test détaillée.

---

## [2.4.0] — 2026-06-13

### Fonctionnalités majeures

- **Système d'alerte paramétrable** : Nouveau système complet permettant de configurer des alertes automatiques basées sur la proximité d'une date cible (deadline). Si un onboarding est prévu pour le 20/06 et que le 15/06 toutes les étapes ne sont pas encore faites, une alerte email est envoyée. Comprend : table `alert_rules` (règles par formulaire, nombre de jours avant la deadline, condition de déclenchement, destinataires), table `alert_log` (historique des alertes envoyées, évitement des doublons), script CLI `alert_check.php` (à planifier via Task Scheduler), et interface d'administration complète `admin_alerts.php`.

- **Champ date limite par formulaire** : Nouvelle colonne `deadline_field` sur la table `forms` permettant d'associer un champ de type date du formulaire comme date cible pour les alertes. Pour l'onboarding, c'est `date_prise_poste` ; pour l'outboarding, c'est `date_depart`. Configurable depuis la page d'administration des alertes.

- **Page admin_alerts.php** : Interface d'administration des règles d'alerte avec : configuration du champ date limite par formulaire, création/modification/suppression de règles (J-N jours avant la deadline, condition « étapes incomplètes », destinataires parmi : administrateurs, agent, validateurs en cours, admin+agent, admin+validateurs, ou email personnalisé), activation/désactivation individuelle, historique des alertes envoyées (50 dernières), purge des logs > 90 jours, statut du script `alert_check.php` (dernière exécution, alerte si > 24h).

- **Script alert_check.php** : Script CLI qui vérifie les soumissions en cours, calcule la distance à la date cible, évalue les conditions (étapes incomplètes), détermine les destinataires, envoie les emails d'alerte avec un tableau récapitulatif des étapes, et trace chaque envoi dans `alert_log`. Les doublons sont évités (une seule alerte par règle + soumission + jour). Planification recommandée : toutes les 6h via Windows Task Scheduler.

- **Intégration monitoring** : La page `monitoring.php` affiche désormais une section « Alertes actives » avec les soumissions en cours proches de leur date cible (code couleur : rouge si dépassé, orange si J-2 ou moins, jaune si J-5 ou moins), le compteur d'alertes actives dans les statistiques globales, l'historique des dernières alertes envoyées, et le statut du script `alert_check.php` dans la section scripts automatisés.

### Fonctionnalités

- **Colonne « Date cible » dynamique dans le dashboard** : Le tableau du dashboard affiche désormais la date cible (deadline) de chaque soumission au lieu du champ en dur `date_prise_poste`. La date est colorée en rouge si la deadline est dépassée ou imminente (J-2 ou moins), en orange si proche (J-5 ou moins). La valeur est résolue dynamiquement via le `deadline_field` configuré sur le formulaire.

- **Lien « 🔔 Alertes » dans le bandeau et le dashboard** : Accès direct à la page de configuration des alertes depuis le dashboard et les bandeaux de navigation.

- **Seed des règles d'alerte par défaut** : À l'installation, deux règles sont créées pour chaque formulaire (J-5 et J-2 avant la deadline), et le `deadline_field` est automatiquement configuré (`date_prise_poste` pour l'onboarding, `date_depart` pour l'outboarding).

- **Email d'alerte riche** : L'email d'alerte contient un bandeau coloré selon l'urgence (rouge si dépassé, orange si J-2, bleu si J-5+), les informations de l'agent, la date cible, le nombre de jours restants, l'avancement (validées/total), et un tableau détaillé des étapes avec leur statut.

---

## [2.3.0] — 2026-06-13

### Fonctionnalités majeures

- **Dashboard validateur (`my_validations.php`)** : Nouvelle page dédiée aux validateurs leur permettant de voir toutes leurs tâches de validation en attente et leur historique de validations. Comprend : vue des tokens en attente avec données du formulaire et progression du circuit (mini-workflow), détection des tokens expirés, historique des validations passées avec délai de traitement, onglets En attente / Historique, et lien direct vers la page de validation. Accessible depuis le bandeau de toutes les pages via le lien « ✅ Mes validations ».

- **Progression du workflow dans validate.php** : Quand un validateur clique sur un lien de validation, il voit désormais la progression complète du circuit (étapes validées, en cours, à venir) avant de prendre sa décision. Un lien « ← Mes validations » permet de revenir au dashboard validateur.

- **Régénération de token par l'admin** : Depuis le dashboard de supervision, un administrateur peut régénérer un lien de validation expiré ou perdu pour un validateur. L'ancien token est invalidé, un nouveau est créé avec une nouvelle date d'expiration, et un email de renvoi est envoyé au validateur. L'action est protégée par CSRF et tracée dans l'audit log.

- **Annulation de soumission** : Un agent ou un administrateur peut annuler une soumission en cours depuis le dashboard. La soumission est fermée avec le statut « refusé », tous les tokens en attente sont clôturés, et l'agent est notifié par email. L'action est protégée par confirmation JavaScript et CSRF.

### Refactoring

- **CSS partagé via `style.php`** : Tout le CSS commun (reset, bandeau, cards, boutons, formulaires, tables, badges, stats, timeline, etc.) est désormais dans un fichier `style.php` inclus via `require_once`. Chaque page ne contient plus que son CSS spécifique dans un second bloc `<style>`. Cela élimine la duplication de ~200 lignes de CSS par page et facilite la maintenance.

---

## [2.2.0] — 2026-06-13

### Fonctionnalités majeures

- **Formulaire d'outboarding** : Nouveau formulaire « Outboarding agent » (slug : `outboarding`) pour le départ d'un agent — restitution du matériel, révocation des accès, formalités RH et logistique. 21 champs répartis en 4 groupes (Identité, Informatique, RH, Logistique) avec 4 étapes de validation par défaut (Responsable direct, Service informatique, RH, Logistique). Seed automatique en base.

- **Page monitoring.php** : Nouveau tableau de bord d'observabilité pour les administrateurs. Comprend : métriques globales (total soumissions, taux de validation, temps moyen de traitement), détection des tokens bloqués (en attente depuis plus de 2x le délai de relance), tokens expirés non traités, santé SMTP (test en un clic), suivi du script de relance (dernière exécution, alerte si > 24h), statistiques par formulaire, activité des 7 derniers jours (barres visuelles), et journal d'audit consultable avec filtres.

- **Journal d'audit** : Nouvelle table `audit_log` et fonction `app_log()` qui tracent toutes les actions administratives (création/suppression de formulaires, ajout d'étapes, modification de paramètres, approbation/refus d'accès admin, exécution du script de relance, exports CSV, complétion de workflow). Chaque entrée enregistre l'action, la cible, un détail lisible, l'acteur (email) et l'adresse IP. Le journal est consultable depuis la page monitoring avec filtre par type d'action.

- **Export CSV** : Fonction `export_csv()` permettant d'exporter les soumissions au format CSV (avec BOM UTF-8 pour Excel, séparateur point-virgule). Les colonnes dynamiques du formulaire sont automatiquement ajoutées. Export accessible depuis le dashboard avec conservation des filtres (statut, formulaire). L'export est tracé dans le journal d'audit.

### Sécurité

- **Approbation admin via POST** : Les liens d'approbation/refus dans les emails admin n'ont plus d'effet de bord au GET. Le clic sur un lien email affiche désormais une page de confirmation avec formulaire POST et protection CSRF, empêchant les préfetch de scanners email ou proxys de déclencher une action non intentionnelle.

- **Validation des emails destinataires** : L'ajout d'un destinataire à une étape de validation vérifie désormais le format de l'adresse email via `filter_var(FILTER_VALIDATE_EMAIL)`. Un message d'erreur clair est affiché si le format est invalide.

### Fonctionnalités

- **Notification de validation finale** : L'agent reçoit désormais un email de confirmation quand sa demande est entièrement validée (toutes les étapes du workflow complétées). Auparavant, seul un email de confirmation de soumission et de refus était envoyé.

- **Traçabilité du script de relance** : `remind.php` enregistre désormais sa dernière date d'exécution dans la table `settings` (clé `last_remind_run`) et logue le nombre de relances envoyées et bloquées dans le journal d'audit. La page monitoring affiche cette information et alerte si le script n'a pas été exécuté depuis plus de 24h.

- **Lien Monitoring dans le dashboard** : Le bouton « 🖥 Monitoring » est désormais accessible depuis la barre d'outils du dashboard de supervision.

- **Audit logging des actions admin** : Toutes les actions de modification dans `admin_forms.php` (création/modification/suppression de formulaires, étapes, destinataires, champs) et `admin_settings.php` (modification des paramètres) sont désormais tracées dans le journal d'audit via `app_log()`.

---

## [2.1.0] — 2026-06-11

### Fonctionnalités

- **Footer avec version** : Toutes les pages affichent un footer avec la version de l'application sous forme de lien cliquable vers le journal des modifications
- **Page changelog.php** : Nouvelle page qui parse le fichier `CHANGELOG.md` et l'affiche de manière formatée avec icônes par section, navigation entre versions et couleurs distinctes (sécurité, fonctionnalités, corrections, UX, nettoyage)
- **Constante APP_VERSION** : Version de l'application définie dans `config.php`, utilisée dans le footer et la page changelog
- **Script `update.ps1`** : Script PowerShell de mise à jour automatique qui télécharge les nouveaux fichiers depuis le dépôt GitHub, avec sauvegarde automatique, mode simulation (`-DryRun`), protection des fichiers de configuration et nettoyage des anciens backups

---

## [2.0.0] — 2026-06-11

### Fonctionnalités majeures

- **Formulaire dynamique** : Les champs du formulaire sont désormais configurables en base de données via la table `form_fields`. Un admin peut ajouter/modifier/supprimer des champs (text, date, select, checkbox, textarea) depuis le back office. Le formulaire hardcodé est supprimé au profit d'un rendu 100% dynamique groupé par cartes (`card_group`). Migration automatique des 21 champs existants de l'onboarding.
- **Page « Mes demandes »** : Nouvelle page `my_submissions.php` permettant à l'agent de suivre l'avancement de toutes ses soumissions avec timeline visuelle (✓ validé / ⏳ en cours / ○ à venir), badges de statut, détails du refus le cas échéant, et liens vers les formulaires actifs.

### Fonctionnalités

- **Protection contre la suppression d'éléments actifs** : Impossible de supprimer un formulaire ou une étape si des soumissions en cours y sont rattachées (`has_active_submissions()`, `has_active_step_submissions()`)
- **Plafond de relances** : Nouveau paramètre `relance_max` (défaut 3). Les tokens ont un compteur `relance_count`. Quand le plafond est atteint, les relances sont bloquées et loguées. Configurable depuis les paramètres admin.
- **Erreur conviviale si AUTH_USER absent** : `get_auth_user()` affiche une page 401 stylisée au lieu d'une exception fatale PHP brute

### UX / Accessibilité

- **Erreurs de validation ciblées** : Chaque champ en erreur est mis en surbrillance avec message d'erreur en dessous, scroll automatique vers le premier champ en erreur
- **Pagination** : Le dashboard affiche 25 soumissions par page avec navigation (Précédent/Suivant)
- **Bandeau responsive** : `flex-wrap: wrap; gap: .5rem` sur le bandeau de toutes les pages
- **Lien admin conditionnel** : Le lien « ⚙ Paramètres » n'est visible que pour les utilisateurs admin
- **Labels accessibles** : Attributs `for`/`id` sur tous les labels et inputs, `fieldset`/`legend` à la place de `div`/`h2`
- **ARIA** : `aria-required`, `aria-invalid`, `aria-describedby` sur les champs du formulaire
- **Aide sur les champs date** : Indication « Format : JJ/MM/AAAA » sous les champs date
- **Favicon** : Icône SVG inline (D bleu sur fond #003189) sur toutes les pages

---

## [1.1.0] — 2026-06-11

### Sécurité

- **CSRF** : Ajout de tokens CSRF sur tous les formulaires POST (form, validate, admin_access, admin_forms, admin_settings)
- **Injection SQL** : Remplacement de `$pdo->quote()` par des requêtes préparées dans `dashboard.php`
- **GET/POST** : Séparation stricte dans `validate.php` — les requêtes GET n'ont plus d'effet de bord (plus d'appel à `validate_token()` au GET)
- **Token expiration** : Ajout d'une colonne `expires_at` sur les tokens, vérification dans `validate_token()`
- **Fichiers debug** : Suppression de `temp_fix.php` et `test_migration.php` (exposaient la structure DB et simulaient l'auth)

### Fonctionnalités

- **Paramètres SMTP configurables** : Nouvelle page `admin_settings.php` pour configurer SMTP (hôte, port, auth, TLS/SSL, identifiants, expéditeur) depuis l'interface admin
- **Table `settings`** : Stockage des paramètres en base (clé/valeur) avec `get_setting()` / `set_setting()`
- **Email de confirmation agent** : Envoi automatique d'un email de confirmation après soumission du formulaire
- **Notification de refus** : L'agent reçoit un email quand sa demande est refusée (avec motif si renseigné)
- **Champ `status`** : Nouveau champ `status` sur les soumissions (`en_cours` / `valide` / `refuse`) — fin du hack `REFUSED:` dans `closed_at`
- **Historique des validations** : Chaque validation/refus est enregistrée dans les données JSON de la soumission
- **Page documentation** : Nouvelle page `docs.php` avec guides agent, validateur, admin, FAQ et architecture technique

### Corrections

- `form.php` : Remplacement de `$_SERVER['AUTH_USER']` par `get_auth_user()`
- `form.php` : Suppression de la variable `$current_user` inutilisée
- `dashboard.php` : Suppression de `get_current_user()` (mauvaise fonction, variable inutilisée)
- `dashboard.php` : Affichage du statut basé sur le champ `status` au lieu du hack `REFUSED:`
- `helpers.php` : Vérification du retour de `send_mail()` dans `advance_workflow()` avec log d'erreur
- `remind.php` : Utilisation de `get_setting('delai_relance_h')` au lieu de la constante
- Migration automatique des données `REFUSED:` existantes vers le champ `status`

### Nettoyage

- Suppression de `.history/` du suivi git (46 fichiers de backup VSCode)
- Mise à jour du `.gitignore` : ajout de `/db/`, `*.db`, `/sessions/`, etc.

---

## [1.0.0] — 2026-03-17

### Initial

- Formulaire d'onboarding agent (champs hardcodés : identité, IT, RH, logistique)
- Moteur de workflow séquentiel/parallèle avec tokens
- Validation par email avec lien à usage unique
- Dashboard de supervision des soumissions
- Back office de gestion des formulaires, étapes et destinataires
- Gestion des accès admin avec approbation par email
- Script de relance automatique (cron)
- Base SQLite avec migration automatique
- Authentification Windows (IIS + Kerberos)
