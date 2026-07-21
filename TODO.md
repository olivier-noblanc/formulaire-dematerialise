# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests unitaires | **1225** (1189 unit + 36 TokenRepository, 0 skip, 0 fail) |
| Tests E2E | **96** (1 skip légitime, 0 fail) — **exécutés réellement pour la première fois sur Linux (v10.21.0)** |
| Total tests | **1321** |
| Assertions | **2330** |
| PHPStan erreurs | **0** (level 8) |
| CI | **GitHub Actions** (4 jobs, ~2 min) |
| Remote | **github.com/olivier-noblanc/formulaire-dematerialise** (privé) |
| xdebug | **off** (permanent) |
| Bugs audit | **16 trouvés, 16 fixés** (+ 6 bugs harnais e2e/production trouvés et fixés en v10.21.0) |
| Migration v30 | **CHECK rebuild + 4 triggers** (5 colonnes) |

---

## 📏 Règles de taille

| Règle | Limite | Détail |
|-------|--------|--------|
| **Fichiers PHP** | **350 lignes max** | Toute classe/fichier ne doit pas dépasser 350 lignes. Si plus long, splitter en plusieurs fichiers. |

---

## ✅ Terminé (historique)

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

| Tâche | Effort | Détail |
|-------|--------|--------|
| Reliquat code mort (baseline PHPStan) | Faible | ~13 entrées shipmonk.deadMethod/deadProperty non-Contract encore non triées individuellement : `Config::get/getAppName/getBaseUrl/getDbPath/isTestMode` (classe quasi entièrement inutilisée), `AdminRepository::isAdmin` (dupliqué par `AuthService::isAdminByEmail`, confirmé inerte), `AuditRepository::getLogs`, `FormRenderer::statusFilter`, `InstallRenderer::renderPage`, `NavigationRenderer::breadcrumb`, `SubmissionViewRenderer::renderContent`. `ErrorResponseException::$title/$hint/$backUrl` examinées et laissées telles quelles (partie de l'API publique de l'exception, non lues mais pas gênant). |


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

_Dernière mise à jour : 2026-07-20 (v10.23.0)_
