# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **1287** (0 fail, 0 skip) |
| Assertions | **2309** |
| PHPStan erreurs baseline | **506** (level 8, noDirectPdo: 0, noMagicString: 51) |
| Enums métier | **7** (SubmissionStatus, FieldType, ValidationAction, FilledBy, FieldVisibility, AdminRequestStatus, UrgencyLevel) |
| Repositories | **10** (LazyCronRepository, PersonaRepository supprimés) |
| CI | **GitHub Actions** (4 jobs, ~2 min) |
| Remote | **github.com/olivier-noblanc/formulaire-dematerialise** (privé) |

---

## 📏 Règles de taille

| Règle | Limite | Détail |
|-------|--------|--------|
| **Fichiers PHP** | **350 lignes max** | Toute classe/fichier ne doit pas dépasser 350 lignes. Si plus long, splitter en plusieurs fichiers. |

---

## ✅ Terminé (historique)

### v10.25.0 — Enforcement du repository pattern via PHPStan
| Tâche | Détail |
|-------|--------|
| Règle PHPStan noDirectPdo | `spaze/phpstan-disallowed-calls` configuré : 3 volets (get_pdo, getPdo, prepare/query/exec) avec allowlist repositories/migrations/legacy |
| Migration 14 services | WorkflowEngine, AuthService, StatsService, TokenService, RgpdService, FieldService, PersonaService, CronService, ExportService, MailService, ValidatorDataService, SampleFormsService, NavigationRenderer — 0 accès PDO direct |
| Migration 7 controllers | BackupController, RgpdController, AdminFormsController + 4 handlers — paramètre PDO supprimé du dispatch |
| 3 nouveaux repos | PersonaRepository, LazyCronRepository, MailRepository |
| ~40 méthodes repo | Ajoutées sur FormRepo, SubmissionRepo, TokenRepo, AdminRepo, AttachmentRepo |
| Baseline PHPStan | 676 → 526 erreurs |
| Tests E2E fixés | 2 tests hardcodés (count 8) → dynamiques |

### v10.25.0 — Enums métier + Deptrac + NoMagicStringRule
| Tâche | Détail |
|-------|--------|
| 7 enums créés | SubmissionStatus, FieldType, ValidationAction, FilledBy, FieldVisibility, AdminRequestStatus, UrgencyLevel |
| Migration strings métier | 39 fichiers migrés, 0 raw string restante hors comments/CSS/SQL aliases |
| NoMagicStringRule | 22 strings bloquées par PHPStan, 76 violations dans baseline |
| Deptrac 4.7.1 | 6 layers, 0 violations, branché à GrumPHP |
| rector.php | UP_TO_PHP_85, règle custom ReplaceMagicStringWithEnumRector |
| Baseline PHPStan | 526 → 775 (+ NoMagicStringRule) |

### v10.23.0 — Migration v31 (durcissement SQL) + fix MailService send()/sendDetailed()
| Tâche | Détail |
|-------|--------|
| Migration v31 | 4 colonnes enum-like supplémentaires protégées par CHECK (field_type ×2, filled_by svd, status mail_log). 9 au total avec v30. |
| MailService::send() vs sendDetailed() | Deux implémentations SMTP dupliquées et divergentes — send() (tout le workflow réel) ne configurait ni auth SMTP ni TLS, contrairement à sendDetailed() (bouton test admin uniquement). send() délègue maintenant à sendDetailed(). |
| mail_log | Jamais alimentée malgré l'affichage monitoring déjà construit — corrigé dans le même commit. |

### v10.22.0 — Bug bounty : send_mail() fantôme, fuseau remind.php, code mort
| Tâche | Détail |
|-------|--------|
| send_mail()/build_mail_html()/render_email_template()/format_bytes() | N'existaient qu'en stub PHPStan — Fatal Error au runtime réel. Impact : remind.php (relances jamais envoyées), alert_check.php (script entier plantait), SubmissionViewController (page utilisateur plantait avec pièce jointe). Voir CHANGELOG v10.22.0. |
| remind.php fuseau horaire | Même bug que #12 (alert_check.php), jamais reporté sur ce script jumeau. Fix DateTimeZone('UTC') explicite. |
| MailerService | Code mort confirmé (consolidée dans MailService, jamais supprimée) — supprimée avec son test. |
| BaseController | 4 propriétés jamais lues (fields/mail/workflow/conditions) — supprimées. |

### v10.21.0 — Harnais e2e Linux + bug findBlocked() + couverture TokenRepository
| Tâche | Détail |
|-------|--------|
| E2E "8 vs 18 forms" | Non reproductible sur environnement propre — 3 runs complets consécutifs (1285-1321 tests) donnent 8 forms stable et les 2 tests passent. La vraie cause du blocage : le harnais e2e ne s'exécutait jamais réellement sur Linux (5 bugs en cascade, voir CHANGELOG v10.21.0), masquant l'état réel derrière un skip silencieux. |
| Couverture TokenRepository | 13 méthodes non testées → 36 tests ajoutés (`tests/PHPUnit/Repository/TokenRepositoryTest/`). A révélé un bug de production réel dans `findBlocked()` (comparaison REAL/TEXT, jamais aucun résultat retourné) — voir CHANGELOG. |
| start_server.php + HttpRouteTest.php | 5 bugs harnais e2e corrigés (argument pidfile, environnement proc_open vide, contexte file_get_contents, expose_php, constantes pcntl) — détail complet dans CHANGELOG v10.21.0. |

### Fixes de tests
| Tâche | Détail |
|-------|--------|
| TokenService constructeur | 6 args → 5 (WorkflowEngine supprimé) |
| Test PDO busy_timeout | PRAGMA busy_timeout = 5000 |
| ExportServiceTest slugs | 8 slugs hardcodés → uniqid() |
| GlobalFunctionsTest regex | PCRE2 lookbehind corrigé |
| setAccessible() déprécié | 4 appels supprimés (PHP 8.5) |
| saveValidatorData() INSERT | UUID ajouté (id NOT NULL manquant) |
| addOwner() INSERT | UUID ajouté (id NOT NULL manquant) |
| Migration v28 | tokens.action, admin_requests.reviewed_at/reviewed_by, seed testeur@e2e.test |
| RgpdServiceTest skip | Submission créée dans le test |
| 77 WorkflowEngineTest skips | Pattern DELETE-based cleanup (helpers + tearDown) |
| 4 AuthServiceTest skips | tearDown restaure $_SERVER |
| TokenServiceTest failures | Tests non-admin définissent $_SERVER |
| persona test skip | 3 scénarios testés |

### Fixes de bugs métier (16 bugs audit)
| # | Bug | Fix |
|---|-----|-----|
| 1 | `done_at` double sens (regenerate) | `tokens.invalidated_at` + filtres |
| 2 | Lost update `submissions.data` | `appendToDataJson()` optimistic locking |
| 3 | `rgpd_consent` manquant SELECT | Ajouté au SELECT |
| 4 | Échéances en retard mal classées | `(int)` → `(int) floor()` |
| 5 | Code mort `FieldService::getValidatorStatusBatch` | Supprimé |
| 6 | StatsService `invalidated_at` | Filtre ajouté |
| 7 | Checkbox required jamais vérifiée | Suppression exclusion |
| 8 | `delegate()` reproduit bug #1 | `invalidated_at = NOW` |
| 9 | Checkbox required (FormController) | Suppression exclusion |
| 10 | Dead code `SubmissionRepository::create()` | Tests migrés vers `createWithRgpd()` |
| 11 | Sujet email alerte trompeur | `abs()` remplacé par condition |
| 12 | Double alerte UTC/Paris | `gmdate()` pour comparaison |
| 13 | `delai_relance_h` sans défaut | Ajout `'48'` par défaut |
| 14 | `audit_log.ip` toujours NULL | Capture `REMOTE_ADDR` |
| 15 | Condition étape réfère champ demandeur | Vérification dans `FormJsonValidator` |
| 16 | Email "Refuser" affiche "Approuver" | Titre dynamique |

### Infrastructure
| Tâche | Détail |
|-------|--------|
| E2E tests Windows | Wrapper PHP + PowerShell Start-Process |
| Health check | sqlite3 → pdo_sqlite |
| testConnection() | Loose comparison pour SQLite |
| xdebug off permanent | xdebug.mode=off |
| CI PHPUnit + E2E | Ajoutés au pipeline Woodpecker |
| gate.sh sync check.ps1 | 12 étapes synchronisées |
| AGENTS.md | 9 règles d'audit ajoutées |
| gitignore shipmonk rules.neon | `vendor/shipmonk/dead-code-detector/rules.neon` ajouté au gitignore allow-list — manquait côté prod, PHPStan échouait au deploy |

---

## 🎯 Ce qui reste

### Baseline PHPStan (506 erreurs)

| Catégorie | Count | Priorité | Détail |
|-----------|-------|----------|--------|
| `empty.notAllowed` | 52 | **LOW** | `empty()` interdit par phpstan-strict-rules → remplacer par `=== ''` / `=== null` / `=== []` |
| `noMagicString` | 51 | **HIGH** | Strings métier dans comments, SQL aliases, CSS — à migrer ou valider comme non-applicables |
| `ternary.shortNotAllowed` | 28 | **LOW** | Ternaires courts (`?:`) interdits → `??` ou `if/else` |
| `booleanNot.exprNotBoolean` | 27 | **LOW** | Expressions non-booléennes dans des `!` → caster en bool |
| `if.condNotBoolean` | 25 | **LOW** | Conditions non-booléennes dans les `if` |
| `in_array strict` | 13 | **LOW** | `in_array()` sans 3ème paramètre `true` |
| `function.strict` | 13 | **LOW** | Appels non stricts |
| `equal.notAllowed` | 9 | **LOW** | Comparaisons `==` au lieu de `===` |
| `cast.useless` | 5 | **LOW** | Casts inutiles |
| `booleanAnd/rightNotBoolean` | 4 | **LOW** | Opérandes non-booléennes dans `&&` |
| Autres | ~179 | **LOW** | Divers (arrayFilter.strict, deadProperty, deadEnumCase, etc.) |

### Bug backlog audit — 29/29 vérifiés, 29 fixés

Audit complet des 29 bugs fonctionnels identifiés lors de l'audit initial :
- **29 confirmés fixés** par les sessions précédentes
- **0 reste à corriger**
- **1 faux positif** : #26 (JarnationService) — le service est vivant (81 références), le TODO avait tort



**Encore présent (1)** : #26 — JargonService entier mort, à supprimer

### Éléments conservés (décision antérieure)

| Élément | Décision | Raison |
|---|---|---|
| `App\Core\Config` (classe entière) | **Supprimé** | Enregistrée dans 3 bootstraps parallèles, jamais consultée nulle part |
| `NavigationRenderer::breadcrumb()` | **Supprimé** | Aucun appelant — breadcrumbs supprimés de l'UI |
| `FormRenderer::statusFilter()` | **Supprimé** | Aucun appelant |
| `InstallRenderer::renderPage()` | **Conservé** | Faux positif — utilisée par `install.php` |
| `AuditRepository::getLogs()` | **Conservé** | Sert à vérifier `log()` dans les tests |
| `AdminRepository::isAdmin()` | **Conservé** | Utilisée par 6 tests |
| `SubmissionViewRenderer::renderContent()` | **Conservé** | Testée par CssCoverageTest |


---

## ⚠️ Leçons apprises

1. **TOUJOURS commit avant d'écraser un fichier existant.**
2. **Quand un test modifie `$_SERVER`, le restaurer en tearDown.**
3. **Quand un test dépend d'un état non-admin, le définir explicitement.**
4. **16 bugs trouvés manuellement — PHPStan n'en détecte qu'un seul.** Lire le code, pas juste l'analyser statiquement.
5. **never reuse field for new semantics** — créer une colonne dédiée, pas réutiliser `done_at`.
6. **grep transversal avant de clore une tâche** — un bug correct en isolation peut être incohérent avec le reste du système.
7. **paramètre lié PDO comparé à une expression calculée (pas une colonne) → caster explicitement.** `execute([$val])` lie en TEXT par défaut ; SQLite n'applique l'affinité numérique qu'au contact d'une colonne à affinité définie, pas d'une expression arithmétique. `WHERE CAST(...) - CAST(...) > ?` échoue silencieusement (0 résultat, jamais d'erreur) — `> CAST(? AS REAL)` corrige. Trouvé dans `TokenRepository::findBlocked()`, jamais détecté car aucun test n'existait avant v10.21.0.

---

## 📏 Règles AGENTS.md (addendum audit)

1. Grep le champ/colonne/clé dans tout le dépôt
2. Pas de liste de valeurs dupliquée
3. Pas de réutilisation de champ existant pour un nouveau sens
4. Pas de méthode ancienne inutilisée laissée à côté
5. Dates avec fuseau explicite
6. Texte utilisateur dérivé du même calcul que la logique
7. Test du cas négatif/limite ajouté
8. Colonne enum → contrainte SQL
9. Catch sur chemin critique → relance ou surfacer

---

_Dernière mise à jour : 2026-07-25 (v10.26.0)_
