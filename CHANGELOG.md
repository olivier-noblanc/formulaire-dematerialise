# Changelog — Formulaire Dématérialisé DREETS

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
