# Agent Guide — CircuitDémat

Guide technique pour agents IA travaillant sur le codebase CircuitDémat.

## KISS — Projet petit intranet

Ce projet est un **petit site intranet DREETS BFC** avec une charge utilisateur faible. **Exclusif Windows** (NTFS, IIS, PowerShell). Appliquer le principe KISS en permanence :

- **Pas de sur-architecture** : pas de cache superflu, pas de couches d'abstraction inutiles, pas de patterns lourds
- **Code court et direct** : préférer la simplicité même si c'est "moins optimal"
- **Sécurité gérée par IIS** : authentification Windows (AUTH_USER), autorisation IIS, rate limiting IIS. Le code PHP n'a pas besoin de gérer la session, le login, le logout, ni le rate limiting
- **Pas de features inutiles** : webhooks supprimés, features qui ne servent pas sont retirées
- **Quand c'est bon, c'est bon** : ne pas refactorer pour le plaisir, ne pas ajouter de tests edge-cases improbables

---

## Outils disponibles (scoop shims)

| Outil | Usage | Emplacement |
|-------|-------|-------------|
| **phive** | Installer des phars (phpstan, grumphp...) | `~/scoop/shims/phive.bat` |
| **grumphp.phar** | Quality gate (lint, phpstan, phpunit, php-cs-fixer) | `~/scoop/shims/grumphp.phar` |
| **phpstan** | Analyse statique level 8 | `~/scoop/shims/phpstan.phar` |
| **rector** | Modernisation PHP auto | `vendor/bin/rector` (via composer) |
| **pwsh** | Scripts gate (check.ps1, update.ps1) | PATH |

Usage : `php ~/scoop/shims/<outil>.phar <commande>` ou directement `<outil>` si dans le PATH.

---

## Repository Pattern

Les repositories centralisent l'accès aux données. Ne pas utiliser `get_pdo()` directement.

### Fichiers
- `src/Repository/BaseRepository.php` — Abstract avec helpers `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`
- `src/Repository/FormRepository.php` — forms + form_fields + form_owners
- `src/Repository/SubmissionRepository.php` — submissions + validator_data
- `src/Repository/TokenRepository.php` — tokens + delegations
- `src/Repository/SettingsRepository.php` — settings
- `src/Repository/AdminRepository.php` — admins + admin_requests
- `src/Repository/AuditRepository.php` — audit_log + security_log
- `src/Repository/AttachmentRepository.php` — attachments

### Usage

```php
// Via DI
$repo = App::getInstance()->get(FormRepository::class);
$form = $repo->findById($id);

// Dans un service
public function __construct(private FormRepository $forms) {}
```

---

## Services (via DI container)

Tous les services sont enregistrés dans `src/bootstrap.php` et accessibles via `App::serviceName()`.

### Services existants
| Service | Classe | Static accessor |
|---------|--------|----------------|
| Auth | `App\Auth\AuthService` | `App::auth()` |
| Settings | `App\Settings\SettingsService` | `App::settings()` |
| Security | `App\Security\SecurityService` | `App::security()` |
| Mail | `App\Mail\MailService` | `App::mail()` |
| Audit | `App\Audit\AuditLogService` | `App::audit()` |
| Cache | `App\Cache\CacheService` | `App::cache()` |
| Html | `App\Render\HtmlService` | `App::html()` |
| Workflow | `App\Workflow\WorkflowEngine` | `App::workflow()` |
| Token | `App\Token\TokenService` | `App::token()` |
| ValidatorData | `App\Forms\ValidatorDataService` | `App::validatorData()` |
| Attachment | `App\Attachment\AttachmentService` | `App::attachment()` |
| Cron | `App\Cron\CronService` | `App::cron()` |
| Webhook | `App\Webhook\WebhookService` | — | Supprimé (getDbSize() conservé) |
| Fields | `App\Forms\FieldService` | `App::fields()` |

### Nouveaux services (v10.5.0)
| Service | Classe | Static accessor | Description |
|---------|--------|----------------|-------------|
| Validation | `App\Validation\ValidationService` | `App::validation()` | Validation/sanitisation d'entrées (uuid, email, slug, action, status, int, date, token) |
| EmailVerification | `App\Email\EmailVerificationService` | `App::emailVerify()` | Vérification email LDAP + SMTP |
| Export | `App\Export\ExportService` | `App::export()` | Export CSV streamé des soumissions |

### Règle
Toujours utiliser `App::serviceName()` ou injecter via constructeur. Ne jamais instancier un service directement (`new XxxService(...)`) hors de `src/bootstrap.php`.

---

## Documentation obligatoire

**Début de session** : TOUJOURS lire `CHANGELOG.md` et `TODO.md` pour connaître l'état actuel du projet.

**Fin de session** : TENIR À JOUR ces fichiers :

- **`CHANGELOG.md`** — Ajouter une entrée `[x.y.z]` avec la date, un résumé, et les sections (features, fixes, refactor, tests)
- **`TODO.md`** — Mettre à jour les métriques, cocher les tâches terminées, ajouter les nouvelles tâches restantes

Ces fichiers sont la source de vérité de l'état du projet. Ne jamais les oublier. Ils doivent toujours être dans le repo (pas dans le .gitignore).

---

## Contraintes réseau

- **SSH coupé** sur le réseau de l'utilisateur — ne jamais tenter `git push` via SSH
- **Proxy** : `http://127.0.0.1:3128` (si besoin pour curl/fetch)
- **Codeberg** : subit des erreurs 500/504 intermittentes (issue #2596) — le push HTTPS peut échouer, réessayer plus tard
- **Remote** : `https://codeberg.org/oliviernoblanc/formulaire-dematerialise.git` (HTTPS uniquement)
- **IIS prod** : pas d'accès web — vendor/ doit être commit, pas de `composer install` possible en prod. Les fichiers d'autoload doivent être à jour dans le repo.

---

## SQLite — piège intra-processus (Windows/NTFS)

**SQLITE_LOCKED (error 6) ≠ SQLITE_BUSY (error 5).** `busy_timeout` ne résout que SQLITE_BUSY (conflit inter-processus). SQLITE_LOCKED est un conflit **intra-processus** : un PDOStatement non fermé tient un verrou de lecture sur la table/sqlite_master, ce qui bloque tout DDL (DROP TABLE, ALTER TABLE) sur la même connexion.

**Règle** : tout `$stmt = $pdo->query(...)` ou `$pdo->prepare(...)` qui retourne un PDOStatement doit être explicitement nettoyé (`$stmt = null`) après le dernier `fetch*()`, AVANT toute opération DDL sur la même connexion. PHP ne libère pas les statements immédiatement — le garbage collector peut tarder.

```php
// FAUX — le statement reste ouvert, tient un lock sur la table
$stmt = $pdo->query("SELECT name FROM sqlite_master ...");
$value = $stmt->fetchColumn();
// $stmt est toujours vivant ici → SQLITE_LOCKED si DDL suivant

// CORRECT
$stmt = $pdo->query("SELECT name FROM sqlite_master ...");
$value = $stmt->fetchColumn();
$stmt = null; // libère le lock avant le DDL
```

---

## Règles de test

Après CHAQUE modification de code, TOUJOURS lancer les tests completset vérifier :
1. `vendor/bin/phpunit` — 0 failures
2. Vérifier que `vendor/composer/autoload_psr4.php` contient les nouvelles classes
3. Vérifier que aucun fichier supprimé n'est encore requis (grep)
4. Si modification d'un service/controller : vérifier aussi les contrôleurs enfants
5. Ne JAMAIS claim que c'est fini sans avoir lancé les tests

---

## PHPDoc & PHPStan — Typage strict obligatoire

PHPStan est configuré au **niveau 8** (max) avec `treatPhpDocTypesAsCertain: false`.

### Problème : `array<string, mixed>` est trop vague

```php
// FAUX — PHPStan ne peut PAS détecter les mauvaises clés
/** @return array<int, array<string, mixed>> */
public function getWorkflowSteps(string $formId): array
```

Avec `array<string, mixed>`, PHPStan ne sait pas quelles clés existent. `$step['id']` au lieu de `$step['step_id']` passe silencieusement.

### Solution : array shapes

```php
// CORRECT — PHPStan flagguera $step['id'] comme "undefined offset"
/**
 * @return array<int, array{
 *   step_id: string,
 *   step_label: string,
 *   ordre: int,
 *   actif: int,
 *   condition: string,
 *   recipient_emails: string
 * }>
 */
public function getWorkflowSteps(string $formId): array
```

### Règle

**Toute méthode qui retourne un tableau de données SQL** DOIT utiliser des array shapes précises, pas `array<string, mixed>`. Les clés doivent correspondre exactement aux aliases SQL (`AS` clause) ou aux noms de colonnes.

Cela s'applique à :
- Tous les méthodes de Repository (`fetchOne`, `fetchAll`)
- Tous les méthodes de Service qui retournent des données
- Les méthodes de WorkflowEngine, TokenService, etc.

### Pourquoi les tests n'ont rien vu

Les tests unitaires vérifient le **comportement** (bonne/mauvaise donnée retournée), mais pas la **cohérence des clés PHPDoc**. PHPStan est le seul outil capable de détector les accès à des clés inexistantes — mais seulement si les types sont explicites.

---

## Cohérence HTML/CSS

Le codebase a des fichiers CSS dans `lib/` et des renderers dans `src/Render/`. Les classes CSS utilisées dans le HTML doivent correspondre exactement à celles définies dans les CSS.

### Pattern dangereux

```php
// FAUX — génère du HTML inline avec les mauvaises classes
echo '<div class="wf-step-label">';  // devrait être wf-label
echo '<div class="wf-step done">';   // devrait être wf-step validated
```

### Pattern sûr

```php
// CORRECT — délègue au renderer qui produit le bon HTML
$renderer = new SubmissionViewRenderer();
echo $renderer->renderWorkflowDiagram($steps, $status);
```

### Règle

Ne **jamais** générer de HTML inline dans un controller pour des sections qui ont un renderer dédié dans `src/Render/`. Utiliser le renderer. Ajouter un test qui vérifie les classes CSS dans le HTML produit.

---

## Tests cross-platform

### Paths

- **JAMAIS** de paths hardcodés Linux dans les tests : `/tmp/`, `/home/z/`, `lsof`
- Utiliser `sys_get_temp_dir()` pour les fichiers temporaires
- Utiliser `php` (dans le PATH) au lieu de `/home/z/php/php`
- Pour tuer un process par port : la fonction `kill_port()` dans `test_bootstrap.php` est cross-platform (lsof sur Linux, netstat+taskkill sur Windows) et limitée à la plage 8760-8799

### curl dans les tests

- Toujours ajouter `CURLOPT_NOPROXY => 'localhost,127.0.0.1'` sur les handles curl — le proxy corporate peut intercepter les appels vers localhost

### test_form_render_html.php — pièges connus

Ce test invoque `FormController::handle()` dans un sous-processus PHP séparé. Pièges :

1. **TEST_MODE** : `core_bootstrap.php` définit `TEST_MODE` via `define()` (une seule fois). Si `helpers.php` est chargé, `TEST_MODE` est fixé. Pour tester le rendu HTML (pas JSON), il faut que `TEST_MODE=false` — ne PAS définir `APP_TEST_MODE` ni le header `HTTP_X_TEST_MODE`.
2. **CSRF** : en CLI, la session ne persiste pas entre sous-processus. Il faut peupler `$_SESSION['csrf_token']` dans le subprocess AVANT le controller, et `$_POST['csrf_token']` doit correspondre.
3. **SMTP** : `MailService::send()` tente une connexion SMTP en mode normal. Activer `mail_dry_run=1` via `\App\Core\App::settings()->set('mail_dry_run', '1', 'test')` pour éviter le blocage.
4. **POST data** : passer les données POST via `argv` (pas stdin) pour éviter que `stream_get_contents(STDIN)` n'écrase `$_POST`.
5. **lib_wrappers.php** : contient `test_json_response()` qui appelle `exit()`. En mode TEST_MODE=true, le controller l'appelle et le script meurt. En mode TEST_MODE=false, cette fonction n'est pas appelée.

### Règle

Après avoir corrigé un test, TOUJOURS lancer `php tests/<fichier>.php` directement pour vérifier, puis la gate complète (`pwsh -NoProfile -File scripts/check.ps1`). Ne jamais claim "c'est fini" sans avoir lancé les tests.

### Playwright — Firefox uniquement

Toujours utiliser **Firefox** pour les tests Playwright, jamais Chromium. Installer avec `rtk playwright install firefox`.

---

## Règle absolue — Pas de laisser-aller sur les bugs

**TOUS les bugs trouvés doivent être fixés.** Ne JAMAIS :
- Classer un échec de test comme "pré-existant" pour ne pas le fixer
- Dire "pas lié à mes changements" pour esquiver un fix
- Laisser des échecs de test dans la gate sans les corriger
- Prendre des raccourcis en marquant des tests comme skipped

Si un test échoue, c'est un bug. Point. Le corriger immédiatement, même s'il existait avant.

---

## Addendum — Enseignements de l'audit manuel (juillet 2026)

Un audit manuel complet a trouvé 16 bugs réels. PHPStan niveau 5 n'en a détecté qu'un seul. Les 15 autres partagent des causes communes non détectables par le typage.

### 1. Discipline de grep avant de clore une tâche

**Règle** : avant de considérer une tâche terminée, si elle touche une colonne DB, une clé JSON, ou un champ partagé entre plusieurs fichiers, exécuter un grep de ce nom sur tout le dépôt et vérifier que chaque usage reste cohérent avec le nouveau comportement — pas seulement le fichier modifié.

**Pourquoi** : la moitié des bugs trouvés (`done_at` réutilisé pour "invalidé" par `regenerate()` puis `delegate()`, alors que 3 autres fichiers le lisent comme "validé par l'utilisateur" — `findDoneByEmail`, `getValidatorStats`, `RgpdService::exportUserData`) viennent d'une fonction correcte en isolation, mais incohérente avec le reste du système. Un test unitaire de la fonction modifiée ne peut pas voir ça — seule une recherche transversale le peut.

```bash
grep -rn "<nom_du_champ>" --include="*.php" .
```

### 2. Une seule source de vérité pour les listes de valeurs valides

**Règle** : ne jamais dupliquer une liste de valeurs autorisées (enum-like) dans plusieurs fichiers. Si une liste existe déjà (ex. opérateurs de condition, types de champs, statuts), la référencer, pas la recopier.

**Pourquoi** : la liste des opérateurs de condition existait en 3 exemplaires légèrement différents (`ConditionEvaluator` en supporte 8, `AdminStepCrudHandler` et `FormJsonValidator` n'en acceptent que 5) — désynchronisation qui bloque silencieusement l'import de JSON générés par IA utilisant des opérateurs pourtant valides à l'exécution.

**Action** : quand tu écris une deuxième occurrence d'une liste de constantes qui existe déjà ailleurs, extrais-la en constante publique du premier endroit (`ConditionEvaluator::VALID_OPS` par exemple) et importe-la partout.

### 3. Ne jamais réutiliser un champ existant pour un nouveau sens

**Règle** : si une colonne/clé a déjà une sémantique établie et lue à plusieurs endroits (ex. `done_at` = "traité par l'utilisateur"), ne jamais l'utiliser dans une nouvelle fonction pour signifier autre chose ("invalidé par un admin"), même si ça évite une migration. Ajouter une colonne dédiée.

**Pourquoi** : ce chevauchement de sens est la cause racine de 4 des 16 bugs trouvés (bug #1 et son extension dans `delegate()`, `getValidatorStats()`, l'export RGPD).

**Action** : si tu hésites à réutiliser un champ existant pour un cas proche mais différent, arrête-toi et pose la question plutôt que de trancher seul — ou par défaut, crée une colonne séparée nommée explicitement (`invalidated_at`, pas `done_at`).

### 4. Hygiène du code mort

**Règle** : avant d'écrire une nouvelle méthode, cherche si une méthode équivalente existe déjà (`grep -rn "function nomSimilaire"` et `grep -rn "->methode("`). Si tu remplaces une implémentation par une meilleure, supprime l'ancienne au lieu de la laisser à côté.

**Pourquoi** : trouvé 3 fois — `FieldService::getValidatorStatusBatch()` (buguée, jamais appelée) coexistant avec `ValidatorDataService::getValidatorStatusBatch()` (correcte, utilisée) ; `SubmissionRepository::create()` (incomplète) à côté de `createWithRgpd()` ; un mode `'both'` de vérification email jamais réellement câblé dans le chemin de production.

**Action** : en fin de tâche, si tu constates qu'une ancienne méthode devient inutilisée suite à ton changement, propose explicitement de la supprimer plutôt que de la laisser.

### 5. Discipline temporelle : jamais de DateTime sans fuseau explicite

**Règle** : toute création de `DateTimeImmutable`/`DateTime` à partir d'une chaîne stockée en base doit préciser explicitement le fuseau (`new DateTimeImmutable($str, new DateTimeZone('UTC'))`), jamais s'appuyer sur le fuseau par défaut du serveur. Toute comparaison de dates entre deux sources (PHP et SQLite `datetime('now')`) doit utiliser le même référentiel (UTC partout, ou conversion explicite documentée).

**Pourquoi** : `alert_check.php` compare `$now` (Europe/Paris, calculé en PHP) à `alert_log.sent_at` (UTC, calculé par SQLite `datetime('now')`) dans le dédoublonnage journalier — fenêtre de double-alerte de 1 à 2h par jour.

**Action** : grep `new DateTime` et `new DateTimeImmutable` sans `DateTimeZone` explicite à côté doit être traité comme suspect.

### 6. Cohérence du texte utilisateur avec la logique métier

**Règle** : quand un texte affiché à l'utilisateur (sujet d'email, libellé, statut) dépend d'une condition ou d'un signe, ce texte doit être dérivé de la **même** variable/fonction que le contenu principal, jamais recalculé séparément avec une logique dupliquée.

**Pourquoi** : le sujet d'email d'alerte utilise `abs($days_remaining)` et affiche toujours "J-X avant la date cible", y compris quand l'échéance est dépassée depuis plusieurs jours — alors que le corps de l'email calcule correctement "DATE DÉPASSÉE" à partir de la même variable, juste dans une fonction différente.

**Action** : si un sujet, un titre ou un label est construit dans une ligne différente de celle qui détermine le statut/l'urgence, factorise le calcul en une seule fonction/variable utilisée aux deux endroits.

### 7. Tests à écrire systématiquement

Pour toute nouvelle fonctionnalité impliquant un champ obligatoire, un import/export, ou une donnée partagée entre fonctionnalités, ajouter un des tests suivants selon le cas :

- **Matrice type de champ × obligatoire** : pour chaque `field_type` supporté (text, email, date, select, checkbox, textarea, file), un test qui vérifie que `required=1` bloque bien la soumission si le champ est vide/non coché. (Aurait attrapé le bug checkbox jamais vérifiée.)
- **Round-trip import/export** : exporter une configuration, la réimporter, vérifier l'égalité — en particulier avec chaque valeur valide d'un champ enum, pas seulement les valeurs les plus courantes.
- **Invariant multi-fonctionnalités** : après une action A (ex. `delegate()`, `regenerate()`), vérifier qu'une fonctionnalité B qui lit la même donnée donne un résultat cohérent avec l'intention métier de A.
- **Comparaison texte/statut** : si un email ou une page affiche un statut dérivé d'un signe/condition, un test avec une valeur qui produit chaque branche (positive, nulle, négative) et une assertion sur le texte affiché.

### 8. Pousser la validation vers la contrainte SQL

**Règle** : pour toute colonne à valeurs limitées (statut, type, enum-like), ajouter une contrainte `CHECK` en base en plus de la validation PHP. Pour tout invariant "au plus un X actif" (un seul token actif par étape, une seule soumission en cours par formulaire+demandeur, etc.), évaluer systématiquement un index unique partiel plutôt que de compter uniquement sur une vérification applicative avant écriture.

**Limite à connaître** : une contrainte NOT NULL ne protège que si rien n'avale l'exception qu'elle déclenche (voir règle 9), et un `DEFAULT` ajouté pour ne pas casser des lignes existantes lors d'une migration ne doit jamais devenir un filet de sécurité permanent qui masque un oubli d'INSERT côté code.

**Action** : à chaque nouvelle colonne enum-like ou nouvel invariant d'unicité, poser la question "est-ce que la base l'empêche, ou seulement le code qui l'appelle aujourd'hui ?" avant de considérer la tâche terminée.

### 9. Ne jamais avaler une exception sur un chemin critique

**Règle** : distinguer trois usages du `try/catch`, ne traiter que le troisième comme un problème :
1. **Nettoyage puis relance** (rollback de transaction avant de laisser l'exception remonter) — légitime ; préférer `try { } finally { }` sans `catch` quand c'est fonctionnellement équivalent.
2. **Panne externe attendue retournée en valeur structurée** (SMTP, LDAP, réseau) — légitime tel quel.
3. **Catch qui avale l'erreur et continue silencieusement**, sur un chemin d'écriture, d'audit, ou de conformité — à proscrire. Une opération qui échoue sur ce type de chemin doit soit remonter, soit surfacer l'échec de façon visible pour l'appelant — jamais `error_log()` seul comme unique trace.

**Pourquoi** : `AuditLogService::log()` avale toute exception d'écriture dans `audit_log` sans la relancer. La contrainte SQL (règle 8) et la discipline try/catch (règle 9) doivent avancer ensemble — l'une sans l'autre laisse un trou.

**Action** : avant de clore une tâche qui ajoute ou modifie un `catch`, classer explicitement dans laquelle des 3 catégories il tombe. Ne jamais ajouter un `catch (\Throwable $e) { error_log(...); }` sans `throw` ni valeur de retour explicite sur un chemin qui écrit des données ou journalise une action.

### 10. Vérifier une affirmation technique avant de l'utiliser pour justifier un choix

**Règle** : quand tu justifies un choix d'implémentation par "X n'est pas possible" ou "X est incompatible avec Y", vérifie-le par une reproduction minimale avant de l'écrire — ne le présente jamais comme un fait établi sur la seule base de ce qui a semblé échouer pendant une tentative.

**Pourquoi** : l'affirmation "SQLite refuse DROP TABLE dans la même session PDO" s'est avérée fausse à la vérification — reproduite avec le schéma réel, `DROP TABLE` fonctionne sans erreur dans la même connexion. Le vrai problème rencontré était différent : avec `foreign_keys=ON`, dropper une table parente référencée en cascade supprime silencieusement les lignes filles — pas une impossibilité, un piège documenté et contournable (`PRAGMA foreign_keys=OFF` pendant le rebuild).

**Action** : avant d'écrire qu'une chose "n'est pas possible" avec l'outil/la plateforme utilisée, isole le cas dans un script minimal et montre le message d'erreur réel plutôt que de le paraphraser de mémoire. Si tu ne peux pas reproduire l'échec isolément, ne présente pas la conclusion comme acquise.

**Corollaire découvert en pratique** : la règle s'applique de façon récursive — une correction n'est pas vérifiée du seul fait qu'elle corrige le diagnostic initial. Après avoir identifié la vraie cause d'un échec, le correctif proposé doit lui-même être exécuté, pas seulement écrit et jugé plausible. Une explication de ce qui n'a pas marché n'est pas une preuve que la solution proposée, elle, marche.

### Checklist avant de clore une tâche

1. Grep le champ/colonne/clé dans tout le dépôt
2. Pas de liste de valeurs dupliquée
3. Pas de réutilisation de champ existant pour un nouveau sens
4. Pas de méthode ancienne inutilisée laissée à côté
5. Dates avec fuseau explicite
6. Texte utilisateur dérivé du même calcul que la logique
7. Test du cas négatif/limite ajouté
8. Colonne enum-like ou invariant d'unicité → contrainte SQL (pas seulement PHP)
9. Tout `catch` sur chemin d'écriture/audit relance l'exception ou surface l'échec — jamais `error_log()` muet
