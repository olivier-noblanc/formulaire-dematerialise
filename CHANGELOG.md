# Changelog — Formulaire Dématérialisé DREETS

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
