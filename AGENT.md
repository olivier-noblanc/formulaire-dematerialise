# AGENT.md

Instructions pour un agent IA intervenant sur ce projet.

---

## Contexte

Application PHP de gestion de workflows de validation de formulaires pour la DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités), administration publique française.

Hébergée sur Windows Server 2025 bare metal, IIS, PHP 8 FastCGI, SQLite.
Auth Windows (Kerberos) fournie par IIS — `$_SERVER['AUTH_USER']` = `DOMAINE\login`.

### Répertoire de base : `C:\inetpub\wwwroot\workflow\`

**Tous les fichiers sont dans ce répertoire racine — il n'y a aucun sous-dossier.**
Tous les `require_once` doivent utiliser `__DIR__ . '/fichier.php'` sans jamais remonter d'un niveau (pas de `../`).

---

## Philosophie du projet — à respecter absolument

- **Zéro framework PHP** : pas de Laravel, Symfony, Slim. PHP procédural pur.
- **Zéro JS framework** : pas de React, Vue, Alpine. HTML5 natif, JS uniquement si strictement nécessaire (toggle de formulaires d'édition dans admin_alerts.php, confirmation d'annulation).
- **Zéro CDN** : aucune ressource externe. Tout est local.
- **Zéro dépendance inutile** : PHPMailer est la seule dépendance Composer. Ne pas en ajouter.
- **Future-proof** : le code doit pouvoir tourner sans modification dans 10 ans. Éviter toute API ou syntaxe susceptible d'être dépréciée.
- **KISS** : chaque fichier fait une chose. Pas d'abstraction inutile.
- **CSS partagé** : `style.php` contient tout le CSS commun, inclus via `require_once`. Chaque page n'a que son CSS spécifique dans un second bloc `<style>`.

---

## Architecture

### Fichiers clés

| Fichier | Rôle |
|---|---|
| `config.php` | Constantes globales + `APP_VERSION`. **Fichier protégé** — ne jamais écraser lors d'une mise à jour (`update.ps1` le préserve). |
| `helpers.php` | Toutes les fonctions partagées. Moteur workflow, base de données, envoi de mails, audit, export CSV. |
| `style.php` | CSS partagé pour toutes les pages. Inclus via `require_once` dans le `<head>`. Reset, bandeau, cards, boutons, formulaires, tables, badges, stats, timeline, workflow diagram, etc. |
| `index.php` | Page d'accueil adaptée au rôle (agent / admin). Redirige les non-connectés. |
| `form.php` | Formulaire dynamique — rendu à partir de la table `form_fields`. Plus aucun champ hardcodé. |
| `form_preview.php` | Prévisualisation d'un formulaire par l'admin (mode lecture seule, circuit de validation visible). |
| `validate.php` | Endpoint de validation par token. Appelle `validate_token()` puis `advance_workflow()`. Affiche la progression du workflow au validateur. |
| `dashboard.php` | Vue de supervision des workflows pour les admins. Filtres, pagination, export CSV, deadline colorée, régénération de token, annulation. |
| `submission_view.php` | Page de détail complète d'une soumission : barre de progression, diagramme workflow, deadline, données, historique validations, actions admin. |
| `my_submissions.php` | Page « Mes demandes » pour l'agent : timeline visuelle, barres de progression, badges deadline. |
| `my_validations.php` | Dashboard validateur : tokens en attente, historique des validations, tokens expirés. |
| `monitoring.php` | Tableau de bord d'observabilité : métriques, tokens bloqués, santé SMTP, graphique donut CSS, alertes actives, journal d'audit. |
| `admin_access.php` | Gestion des accès admin : demande, approbation, révocation. |
| `admin_forms.php` | Back office CRUD : gestion des formulaires, champs, étapes et destinataires. Auto-génération du nom technique, options simplifiées, diagramme visuel du circuit. |
| `admin_alerts.php` | Configuration des règles d'alerte (J-N, condition, destinataires), champ date limite, historique des alertes. |
| `admin_settings.php` | Paramètres SMTP, délai de relance, plafond de relances. |
| `docs.php` | Documentation utilisateur : guides agent, validateur, admin, FAQ, architecture technique. |
| `changelog.php` | Journal des versions — parse `CHANGELOG.md` et l'affiche formaté. |
| `alert_check.php` | Script CLI : vérifie les deadlines et envoie les alertes configurées. À planifier toutes les 6h. |
| `remind.php` | Script CLI de relance automatique. Appelé par Windows Task Scheduler. |
| `update.ps1` | Script PowerShell de mise à jour automatique (télécharge, sauvegarde, préserve config.php, nettoie). |

### Navigation entre pages

```
index.php ──→ form.php (agent)
          ├──→ my_submissions.php (agent)
          ├──→ my_validations.php (validateur)
          ├──→ dashboard.php (admin)
          │       ├──→ submission_view.php
          │       └──→ admin_alerts.php
          ├──→ admin_forms.php (admin)
          │       └──→ form_preview.php
          ├──→ admin_settings.php (admin)
          ├──→ monitoring.php (admin)
          ├──→ admin_access.php (admin)
          └──→ docs.php (tous)
```

Il n'existe pas de dossier `admin/`. Ne jamais générer de liens `href="admin/"`.

### Schéma SQLite

```
forms           (id, slug, label, description, actif, deadline_field, created_at)
steps           (id, form_id, label, ordre, actif)
step_recipients (id, step_id, email)
submissions     (id, form_id, data JSON, submitted_by, submitted_at, closed_at, status)
tokens          (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count)
form_fields     (id, form_id, label, field_type, field_name, options, required, ordre, card_group)
admins          (id, email, added_at)
admin_requests  (id, email, requested_at, status, token)
settings        (key, value, updated_at, updated_by)
audit_log       (id, action, target, detail, actor, ip, created_at)
alert_rules     (id, form_id, days_before, condition_type, notify_who, label, actif, created_at)
alert_log       (id, rule_id, submission_id, sent_at, message)
```

Colonnes ajoutées par ALTER TABLE (migration automatique) :
- `submissions.status` (TEXT DEFAULT 'en_cours') — v1.1.0
- `tokens.expires_at` (DATETIME) — v1.1.0
- `tokens.relance_count` (INTEGER DEFAULT 0) — v2.0.0
- `forms.deadline_field` (TEXT DEFAULT '') — v2.4.0

### Moteur workflow — `helpers.php`

Deux fonctions centrales :

**`advance_workflow(int $submission_id)`**
- Récupère toutes les étapes actives du formulaire triées par `ordre ASC`
- Groupe par `ordre` : même ordre = parallèle
- Cherche le premier groupe sans tokens générés → génère les tokens et envoie les mails
- Si le groupe précédent n'est pas entièrement `done` → attend
- Si tous les groupes sont `done` → met `closed_at` sur la soumission + notifie l'agent par email

**`validate_token(string $token)`**
- Met à jour `done_at` sur le token
- Appelle `advance_workflow()` pour déclencher l'étape suivante si besoin
- Retourne un tableau `['status' => 'ok|invalid|already_done|closed', 'data' => [...]]`

### Authentification

`get_auth_user()` dans `helpers.php` :
- Lit `$_SERVER['AUTH_USER']` fourni par IIS Windows Auth
- Normalise `DOMAINE\login` → `login@dreets.gouv.fr`
- Affiche une page 401 stylisée si `AUTH_USER` est vide (plus d'exception fatale)

Hiérarchie des rôles :
- `is_super_admin()` : vérifie si l'email correspond à `ADMIN_EMAIL` dans `config.php`
- `is_admin_user()` : vérifie la présence de l'email dans la table `admins`

### Système d'alertes

Deux composants :
1. **`admin_alerts.php`** — Interface de configuration : champ date limite par formulaire, règles (J-N jours, condition, destinataires), activation, historique.
2. **`alert_check.php`** — Script CLI qui vérifie les soumissions en cours, calcule la proximité à la deadline, évalue les conditions, envoie les emails d'alerte, et trace dans `alert_log`. Déduplication : une seule alerte par règle + soumission + jour.

6 cibles de notification : admin, agent, validateurs en cours, admin+agent, admin+validateurs, email personnalisé.

---

## Conventions de code

- Fonctions en `snake_case`
- Pas de classes, pas d'objets sauf PDO et PHPMailer
- Toujours échapper les sorties HTML avec `h()` (wrapper de `htmlspecialchars`)
- Toujours utiliser des requêtes préparées PDO — jamais d'interpolation SQL
- Constantes de config via `define()` dans `config.php`, jamais hardcodées
- `APP_VERSION` dans `config.php` — source de vérité pour la version
- CSS commun dans `style.php` inclus via `require_once` — pas de fichier `.css` séparé
- Chaque page a son CSS spécifique dans un second bloc `<style>` après l'inclusion de `style.php`
- Protection CSRF sur tous les formulaires POST via `csrf_field()` / `csrf_check()`

---

## Ce qu'il ne faut pas faire

- Ne pas créer de sous-dossiers — tous les fichiers sont à la racine
- Ne pas générer de liens `href="admin/"` ou `href="index.php"` comme destination finale
- Ne pas utiliser `__DIR__ . '/../'` — il n'y a pas de niveau supérieur dans ce projet
- Ne pas introduire de routing (pas de `.htaccess` avec rewrite, pas de front controller)
- Ne pas introduire de templating (pas de Twig, pas de Blade)
- Ne pas introduire d'ORM (pas d'Eloquent, pas de Doctrine)
- Ne pas modifier le schéma SQLite sans mettre à jour la fonction `init_db()` dans `helpers.php`
- Ne pas ajouter de dépendances Composer sans validation explicite
- Ne pas utiliser `$_GET` ou `$_POST` sans validation/échappement
- Ne pas exposer `alert_check.php`, `remind.php` ou `init_db.php` via le web (CLI uniquement)
- Ne pas écraser `config.php` lors d'une mise à jour — c'est un fichier protégé
- Ne pas dupliquer le CSS commun — utiliser `require_once __DIR__ . '/style.php'`
- Ne pas utiliser de JavaScript sauf si strictement nécessaire (confirms, toggles minimes)

---

## Ajouter un nouveau formulaire

1. En back office (`admin_forms.php`), créer le formulaire (slug, libellé, description)
2. Ajouter les champs via l'interface — le nom technique est auto-généré à partir du libellé
3. Configurer les étapes de validation et les destinataires
4. Le formulaire est automatiquement disponible sur la page d'accueil des agents
5. Aucune modification de code nécessaire — tout est dynamique via `form_fields`

---

## Ajouter une section au formulaire existant

1. En back office (`admin_forms.php`), ajouter les champs dans le formulaire
2. Utiliser un `card_group` existant ou nouveau pour le regroupement visuel
3. Les données sont stockées en JSON — aucune migration SQLite nécessaire
4. Les mails et le dashboard affichent automatiquement les nouveaux champs

---

## Scripts CLI à planifier

| Script | Fréquence recommandée | Rôle |
|---|---|---|
| `remind.php` | Toutes les 12h | Relance les validateurs qui n'ont pas répondu |
| `alert_check.php` | Toutes les 6h | Vérifie les deadlines et envoie les alertes configurées |

Les deux scripts utilisent `set_setting()` pour tracer leur dernière exécution. La page monitoring alerte si un script n'a pas tourné depuis plus de 24h.

---

## Environnement de développement

- VSCode avec extension PHP Intelephense
- PHP 8.x local ou via XAMPP
- Xdebug pour le debug pas à pas
- Tester `remind.php` en CLI : `php remind.php`
- Tester `alert_check.php` en CLI : `php alert_check.php`
- Base SQLite dans `db/workflow.db` (créée automatiquement au premier accès)

---

## Points d'attention

- **SQLite et concurrence** : `PRAGMA journal_mode=WAL` est activé — les lectures simultanées sont correctes. Les écritures sont séquentielles. Suffisant pour le volume attendu (< 100 soumissions/jour).
- **Tokens** : générés avec `random_bytes(32)` — cryptographiquement sûrs, non rejouables.
- **Auth AD** : `$_SERVER['AUTH_USER']` peut être vide si IIS n'est pas configuré en Windows Auth. Dans ce cas `get_auth_user()` affiche une page 401 stylisée.
- **SMTP sans TLS** : configuré pour un SMTP intranet sans authentification. Si le SMTP requiert TLS ou auth, configurer via `admin_settings.php`.
- **Config protégée** : `config.php` n'est jamais écrasé par `update.ps1` — le déploiement préserve la configuration locale.
- **Zéro JS** : le projet ne dépend pas de JavaScript côté client. Les rares usages (toggle, confirm) sont du JS natif inline minimal.
