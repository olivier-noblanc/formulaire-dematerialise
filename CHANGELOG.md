# Changelog — Formulaire Dématérialisé DREETS

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
