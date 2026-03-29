# AGENT.md

Instructions pour un agent IA intervenant sur ce projet.

---

## Contexte

Application PHP de gestion de workflows de validation formulaire pour la DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités), administration publique française.

Hébergée sur Windows Server 2025 bare metal, IIS, PHP 8 FastCGI, SQLite.
Auth Windows (Kerberos) fournie par IIS — `$_SERVER['AUTH_USER']` = `DOMAINE\login`.

### Répertoire de base : `C:\inetpub\wwwroot\workflow\`

**Tous les fichiers sont dans ce répertoire racine — il n'y a aucun sous-dossier.**
Tous les `require_once` doivent utiliser `__DIR__ . '/fichier.php'` sans jamais remonter d'un niveau (pas de `../`).

---

## Philosophie du projet — à respecter absolument

- **Zéro framework PHP** : pas de Laravel, Symfony, Slim. PHP procédural pur.
- **Zéro JS framework** : pas de React, Vue, Alpine. HTML5 natif, JS uniquement si strictement nécessaire (toggle de ligne dans le dashboard).
- **Zéro CDN** : aucune ressource externe. Tout est local.
- **Zéro dépendance inutile** : PHPMailer est la seule dépendance Composer. Ne pas en ajouter.
- **Future-proof** : le code doit pouvoir tourner sans modification dans 10 ans. Éviter toute API ou syntaxe susceptible d'être dépréciée.
- **KISS** : chaque fichier fait une chose. Pas d'abstraction inutile.

---

## Architecture

### Fichiers clés

| Fichier | Rôle |
|---|---|
| `config.php` | Constantes globales. Seul fichier à modifier pour un déploiement. |
| `helpers.php` | Toutes les fonctions partagées. Contient le moteur workflow. |
| `init_db.php` | Script CLI d'initialisation. Ne jamais appeler depuis le web. |
| `form.php` | Formulaire public. Rendu statique — les fields sont hardcodés par formulaire. |
| `validate.php` | Endpoint de validation par token. Appelle `validate_token()` puis `advance_workflow()`. |
| `dashboard.php` | Vue de supervision des workflows. Lecture seule. Accès réservé aux admins. |
| `admin_access.php` | Gestion des accès admin : demande, approbation, révocation. Point d'entrée du back office. |
| `admin_forms.php` | Back office CRUD : gestion des formulaires, étapes et destinataires. |
| `index.php` | Redirige vers `admin_access.php`. |
| `remind.php` | Script CLI de relance. Appelé par Windows Task Scheduler. |

### Navigation entre pages

```
index.php → admin_access.php → dashboard.php
                             → admin_forms.php
```

Il n'existe pas de dossier `admin/`. Ne jamais générer de liens `href="admin/"`.

### Schéma SQLite

```
forms           (id, slug, label, description, actif, created_at)
steps           (id, form_id, label, ordre, actif)
step_recipients (id, step_id, email)
submissions     (id, form_id, data JSON, submitted_by, submitted_at, closed_at)
tokens          (id, submission_id, step_id, email, token, sent_at, done_at, relance_at)
admins          (id, email, added_at)
admin_requests  (id, email, requested_at, status, token)
```

### Moteur workflow — `helpers.php`

Deux fonctions centrales :

**`advance_workflow(int $submission_id)`**
- Récupère toutes les étapes actives du formulaire triées par `ordre ASC`
- Groupe par `ordre` : même ordre = parallèle
- Cherche le premier groupe sans tokens générés → génère les tokens et envoie les mails
- Si le groupe précédent n'est pas entièrement `done` → attend
- Si tous les groupes sont `done` → met `closed_at` sur la soumission

**`validate_token(string $token)`**
- Met à jour `done_at` sur le token
- Appelle `advance_workflow()` pour déclencher l'étape suivante si besoin
- Retourne un tableau `['status' => 'ok|invalid|already_done|closed', 'data' => [...]]`

### Authentification

`get_auth_user()` dans `helpers.php` :
- Lit `$_SERVER['AUTH_USER']` fourni par IIS Windows Auth
- Normalise `DOMAINE\login` → `login@dreets.gouv.fr`
- **Lève une `RuntimeException` si `AUTH_USER` est vide** — ne jamais retourner silencieusement une valeur par défaut

Hiérarchie des rôles :
- `is_super_admin()` : vérifie si l'email correspond à `ADMIN_EMAIL` dans `config.php`
- `is_admin_user()` : vérifie la présence de l'email dans la table `admins`

---

## Conventions de code

- Fonctions en `snake_case`
- Pas de classes, pas d'objets sauf PDO et PHPMailer
- Toujours échapper les sorties HTML avec `h()` (wrapper de `htmlspecialchars`)
- Toujours utiliser des requêtes préparées PDO — jamais d'interpolation SQL
- Constantes de config via `define()` dans `config.php`, jamais hardcodées
- Les fields du formulaire utilisent des préfixes par section : `it_`, `rh_`, `log_` pour les champs métier, pas de préfixe pour l'identité agent

---

## Ce qu'il ne faut pas faire

- Ne pas créer de sous-dossiers — tous les fichiers sont à la racine
- Ne pas générer de liens `href="admin/"` ou `href="index.php"` comme destination finale
- Ne pas utiliser `__DIR__ . '/../'` — il n'y a pas de niveau supérieur dans ce projet
- Ne pas introduire de routing (pas de `.htaccess` avec rewrite, pas de front controller)
- Ne pas introduire de templating (pas de Twig, pas de Blade)
- Ne pas introduire d'ORM (pas d'Eloquent, pas de Doctrine)
- Ne pas modifier le schéma SQLite sans mettre à jour `init_db.php`
- Ne pas ajouter de dépendances Composer sans validation explicite
- Ne pas utiliser `$_GET` ou `$_POST` sans validation/échappement
- Ne pas exposer `init_db.php` ou `remind.php` via le web (ces fichiers sont CLI uniquement)

---

## Ajouter un nouveau formulaire

1. Créer un fichier `form_[slug].php` en copiant `form.php`
2. Adapter les sections HTML et les champs `required`
3. Enregistrer le formulaire en back office (`admin_forms.php`)
4. Configurer les étapes et destinataires en back office
5. Aucune modification de `helpers.php` ou `init_db.php` nécessaire

---

## Ajouter une section au formulaire existant

1. Ouvrir `form.php`
2. Ajouter une `<div class="card">` avec les inputs HTML5 souhaités
3. Préfixer les `name` des inputs avec le code équipe (`it_`, `rh_`, `log_`, ou nouveau préfixe)
4. Les données sont stockées en JSON — aucune migration SQLite nécessaire
5. Les mails et le dashboard affichent automatiquement les nouveaux champs

---

## Environnement de développement

- VSCode avec extension PHP Intelephense
- PHP 8.x local ou via XAMPP
- Xdebug pour le debug pas à pas
- Tester `remind.php` en CLI : `php remind.php`
- Tester `init_db.php` en CLI : `php init_db.php`

---

## Points d'attention

- **SQLite et concurrence** : `PRAGMA journal_mode=WAL` est activé — les lectures simultanées sont correctes. Les écritures sont séquentielles. Suffisant pour le volume attendu (< 100 soumissions/jour).
- **Tokens** : générés avec `random_bytes(32)` — cryptographiquement sûrs, non rejouables.
- **Auth AD** : `$_SERVER['AUTH_USER']` peut être vide si IIS n'est pas configuré en Windows Auth. Dans ce cas `get_auth_user()` lève une `RuntimeException` — ne pas silencier cette erreur.
- **SMTP sans TLS** : configuré pour un SMTP intranet sans authentification. Si le SMTP requiert TLS ou auth, activer `$mail->SMTPSecure` et `$mail->SMTPAuth` dans `helpers.php`.
