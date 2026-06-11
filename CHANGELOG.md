# Changelog — Formulaire Dématérialisé DREETS

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
