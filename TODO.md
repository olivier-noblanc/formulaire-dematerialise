# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **1419** (0 fail, 0 errors) |
| Assertions | **4164** |
| `noUntypedArray` PHPStan | **0** ✅ (157 → 0 — Wave 2 shapes/aliases, v10.42.15) |
| Coverage | **33.5%** (codecov.io) — cible 60% |
| Infection MSI | **30%** min — cible 50% |
| PHPStan erreurs baseline | **479** (level 8) — templates + règles shipmonk (régénéré après PII rewrite) |
| Style "" inline | **0** (zéro — cleanup complet 2026-08-01, 84 style="" migrés) |
| Classes CSS sémantiques | **384** (style_utility.css — cleanup complet + progress-0 à 100) |
| Enums métier | **8** (SubmissionStatus, FieldType, ValidationAction, FilledBy, FieldVisibility, AdminRequestStatus, UrgencyLevel, **AssetType**) |
| Repositories | **10** |
| Fichiers > 350 lignes | **0** (FormRenderer ✅ 460→344 — 8 templates extraits) |
| CI | **GitHub Actions** (15 jobs bloquants + CSP Check) — CI + CSP Check + Dependabot |
| Remote | **github.com/olivier-noblanc/formulaire-dematerialise** (**public**) |

---

## 📏 Règles de taille

| Règle | Limite | Détail |
|-------|--------|--------|
| **Fichiers PHP** | **350 lignes max** | Toute classe/fichier ne doit pas dépasser 350 lignes. Si plus long, splitter en plusieurs fichiers. |

---

## ✅ Terminé (historique)

### v10.42.15 — Durcissement typage : `array<string, mixed>` → array shapes précises
| Tâche | Détail |
|-------|--------|
| Contexte | PHPStan level 8 : règle `NoUntypedArrayParameterRule` flagge les `array<string, mixed>` trop vagues (clés indétectables) — voir AGENTS.md "array shapes" |
| Triage | exp-3 : 101 occ / 43 fichiers, catégorie A=43 à corriger, B=JSON/tolérance légitime, C=frontière (à ne pas toucher) |
| Verdict | ora-1 : shapes majoritaires + `@phpstan-type` aliases dans les fichiers sources (pas de src/Type/), DTO ponctuel uniquement si agrégat ≥2 sources ; pas de DTO pour rows SQL |
| Correction | Export/Attachment/ValidatorData/FormJsonValidator + PageRenderer/FormRenderer/MySubmissionsRenderer/IndexRenderer/NavigationRenderer/ErrorRenderer/ConfirmActionRenderer/ValidateRenderer + 4 Admin handlers CRUD |
| Leçon règle | `NoUntypedArrayParameterRule` utilise une regex qui ne matche PAS les shapes imbriqués inline ni les aliases sur param → utiliser un shape inline simple ou un alias au **class docblock** (ex. `FormFieldRow`) |
| Résultat | phpstan niveau 8 projet complet : **0 erreur** ✅ — grep de contrôle : 59 `array<string, mixed>` restants, tous catégorie B/C/N-A, 0 fuite catégorie A |
| Wave 3 (ValidateRenderer) | Le `@param` manuel à 8 clés (shape dupliquée, dérivante de l'alias canonique) remplacé par `@phpstan-import-type FormFieldRow` — **pas un bug de rendu** (appel L206 ordre correct, toutes les clés lues par `field()` présentes/typées) ; simple imprécision PHPDoc, alignement sur la source de vérité (règle AGENTS.md 1) |
| `reportUnmatchedIgnoredErrors` | exp-2 : 46 ignores inline audités, tous légitimes → flag activé puis **laissé à `false`** sur `phpstan.neon` (l'activer remonterait 572 entrées baseline stale + 14 faux positifs `shipmonk.deadMethod`). `tests/phpstan.neon` : flag inchangé — bloc ignoreErrors massif non audité. Décision : nettoyer la baseline d'abord (voir Résidu) |
| Hygiène | sta-1 : artefacts untracked nettoyés (5 `tmp-*.zip` + 4 dossiers cache Composer dans `vendor/composer/`) + `edenai-llm-cache-pricing.*` ajouté à `.gitignore` |
| Résidu / à faire | `phpstan-baseline.neon` : 572 entrées stale (`@phpstan-ignore`/ignoreErrors ne matchant plus après cleanups Waves 1/2, dont 14 faux positifs `shipmonk.deadMethod`) → régénérer `phpstan --generate-baseline` puis seulement réévaluer `reportUnmatchedIgnoredErrors: true` |
| Tests | PHPUnit 1419 tests / 4164 assertions / 0 failure (5W/3D préexistants) ✅ |

### v10.42.14 — Formulaire "Pense-bête" (self-reminder)
| Tâche | Détail |
|-------|--------|
| Nouveau formulaire | "Pense-bête" — self-reminder avec date cible |
| Champs | `note_objet_du_rappel` (Zone de texte, obligatoire), `date_cible` (Date, obligatoire) |
| Circuit | Étape unique "Auto-validation" avec destinataire `{{owner}}` |
| ID formulaire | `ea8f7387-1676-43a1-90ba-a61ea8824e8c` |
| Création | Via UI admin (pas de code) — prévisualisation OK ✅ |

### v10.42.13 — Enum `SubmissionField` pour éliminer les magic strings
| Tâche | Détail |
|-------|--------|
| Bug PHP 8.5 | `MyValidationsRenderer.php` : accès directs `$data['prenom']`, `$data['affectation']`... sans garde-fou → warnings « Undefined array key » sur formulaires sans ces champs |
| Fix initial | Accès null-safe `?? ''` (lignes 91-92) — appliqué manuellement |
| Refactor | Helper `src/Forms/SubmissionData.php` (NOUVEAU) : `get()` (extraction chaîne safe) + `has()` (vérification existence + non-vide) |
| Migration | `MyValidationsRenderer.php` : 4 accès remplacés (lignes 82, 92-93, 175, 182-191) vers le helper |
| Tests | `--filter MyValidations` : 2 tests, 3 assertions ✅ ; PHPStan level 8 : No errors ✅ |
| Leçon | Le fix initial était un travail @stagiaire (1 ligne, pattern mécanique) — à déléguer systématiquement |

### v10.42.11 — Cache-busting assets par version (enum AssetType) + persona JS externalisé
| Tâche | Détail |
|-------|--------|
| Bug prod | Assets servis avec `max-age=86400` sans version d'URL → cache navigateur périmé 24 h après déploiement (CSS `v10.42.7` encore servi alors que le site était en `v10.42.10`, vérifié en prod : bouton « Supprimer » + barre d'action my_submissions en style brut) |
| Enum | `src/Enum/AssetType.php` : `Css`/`Js` — liste fermée portée par enum (règle AGENTS.md #12) ; `assets.php` valide via `AssetType::tryFrom()` sans `helpers.php` (pas de `session_start()`) |
| Cache-busting | `HtmlService::assetUrl(AssetType, $file)` → `assets.php?type=…&v=<version CHANGELOG>` ; appliqué dans `PageRenderer` (css + app.js) et `form_content.php` (form-progress, form-conditions) |
| Persona JS | JS inline du dropdown persona extrait de `templates/persona_js.php` (supprimé) vers `assets/persona.js`, servi avec nonce CSP + cache-busting via `NavigationRenderer::footer()` |
| Tests | `test_assets_cache.php` : checks index/form.php passés en regex avec `&v=X.Y.Z` |
| Docs | AGENTS.md : règle ### 12 « Pas de string magiques pour une liste fermée — enum obligatoire » + item 10 de checklist (rédigée et appliquée par le stagiaire) |
| Vérifs | PHPUnit 0 fail, test_assets_cache OK |

### v10.42.9 — Fix régression admin_forms (sections vides) + Playwright sur MS Edge
| Tâche | Détail |
|-------|--------|
| Fix sections vides | `AdminFormsController` recharge steps/steps_by_ordre/form_fields/owners/existing_groups + `validation_html`/`preserved_json` du dispatch (régression 828a54f) |
| Nouvelle méthode repo | `FormStepsTrait::getStepsWithRecipientObjects()` — steps + recipients en objets `{id, email}` (batch `IN`) |
| Test unitaire | `FormRepositoryTest` : 2 tests recipients objects (2 steps/2 recipients + étape vide + form inexistant) |
| Test e2e | `tests/test_e2e_admin_forms.js` : 11 assertions — pas de « Aucune étape/champ/propriétaire », 4 steps, 4 chips, 22 champs, 2 owners |
| Playwright Edge | 4 fichiers JS migrés `firefox` → `chromium.launch({channel:'msedge'})` + AGENTS.md mis à jour |
| Vérifs | PHPUnit 1417/0 fail, PHPStan level 8 OK fichiers modifiés, e2e admin_forms 11/11 + full_flow 5/5 |

### v10.42.8 — Purge PII complète (git filter-repo + force-push) + baseline PHPStan régénérée
| Tâche | Détail |
|-------|--------|
| Audit PII | Repo public (isPrivate: false) — ~45 occurrences nominatives + adresses service réelles dans 87 fichiers trackés |
| Nettoyage HEAD+historique | git-filter-repo --replace-text : `olivier.noblanc@dreets.gouv.fr` → `admin.local@exemple.invalid`, `DREETS\olivier.noblanc` → `DREETS\admin.local`, domaine `dreets.gouv.fr` → `exemple.invalid` (case-insensitive), `dreets-bfc.supportesic` → `service.support` |
| Mailmap auteurs | NOBLANC/Olivier Noblanc/onoblanc unifiés vers `oliviernoblanc@users.noreply.codeberg.org` |
| Force-push | master réécrit (SHA 54d781b), backup bundle conservé |
| Artefacts CI | 31 artefacts coverage supprimés via API — 245 plus anciens restants (expiration GitHub progressive) |
| Baseline PHPStan | Régénérée : 479 erreurs (vs 491, -12 grâce aux strings réécrites) |
| Résidu | phpstan-baseline.neon : 3 `@dreets` dans patterns regex non matchés par règles littérales (généré, se régénère au prochain phpstan --generate-baseline) |

### v10.42.7 — Filet e2e anti-warnings PHP (index) + 2 bugs templates + gitignore SQLite
| Tâche | Détail |
|-------|--------|
| Nouveau e2e | `tests/e2e/index_pages_no_warning.spec.js` : 7 pages (/, health, docs, form onboarding, admin settings, monitoring, mes soumissions) — 200 + corps sans warning + stderr sans erreur. Header `AUTH_USER` + env `E2E_ADMIN_AUTH` (les admins locaux sont seedés par email ; `DREETS\admin` n'existe qu'en CI). 21 assertions, 0 échec local. Enregistré dans run_all.js |
| Bug FormRenderer | `$aria_attr` calculée mais absente du tableau `$vars` → warning « Undefined variable: aria_attr » sur chaque champ. Fix : `'aria_attr' => $aria_attr` ajouté |
| Bug DocumentationService | `renderRgpd()` sans `$legal_mentions` + `loadTemplate()` sans param vars → warning sur la page docs. Fix : signature `loadTemplate($filename, array $vars = [])` + `extract($vars)` |
| gitignore | Patterns `*.db-shm`/`*.db-wal` ajoutés (artefacts SQLite WAL/SHM non couverts par `*.db`) |

### v10.42.6 — Fix CSS corrompu (filemtime warning) + tests durcis
| Tâche | Détail |
|-------|--------|
| Bug | `assets.php` : `filemtime()` avant `is_file()` → warning PHP dans le corps CSS au 1er hit après déploiement (cache froid). Fix : `is_file()` d'abord |
| Trous tests | test_assets_cache.php ne vérifiait que status+headers (jamais le corps) ; cache froid jamais garanti (pas de purge) ; display_errors=Off en CI masquait le warning |
| test_assets_cache.php | Purge cache avant démarrage + `-d display_errors=1 -d error_reporting=E_ALL` + assertion « corps CSS pur » (début `/*`, aucun pattern erreur PHP) |
| Nouveau e2e | `tests/e2e/assets_css_pure.spec.js` : corps pur + stderr sans erreur sur cache froid ET cache chaud — enregistré dans run_all.js |
| helpers.js | `killExistingServer()` cross-platform (netstat+taskkill Windows) ; exit codes du kill volontaire non loggés comme crashs (flag `stopping`) |
| Env dev | PHP scoop : mbstring/pdo_sqlite/sqlite3 activés dans php.ini principal (le `php -S` sans PHP_INI_SCAN_DIR n'avait pas les extensions → 500 health) ; doublons retirés de cli\php.ini. ⚠️ php.ini principal non persisté (perdu à l'update scoop) |

### v10.42.2 — Correction PHPStan massive (959 → 489 erreurs)
| Tâche | Détail |
|-------|--------|
| Rector | ~80 fichiers corrigés automatiquement (TYPE_DECLARATION, CODE_QUALITY, DEAD_CODE, STRICT_BOOLEANS, EARLY_RETURN) |
| phpstan-rules | 4 erreurs corrigées (types, return, regex rule fix) |
| Controllers | ~50 erreurs (boolean checks, unsafeArrayKey, argument.type) |
| Repositories | ~30 erreurs (array shapes ajoutées) |
| Services | CacheService, AuthService, ValidationService, WorkflowEngine corrigés |
| MonitoringController | 7 erreurs `list<...>` vs `array<int, ...>` |
| offsetAccess.notFound | 15 erreurs corrigées avec guards null |
| Templates variable.undefined | ~250 erreurs restantes — nécessite lecture Context/Renderer |
| Tests | 1415 tests, 0 failures, 0 errors |

### v10.42.1 — Nettoyage duplications CI (988 lignes)
| Tâche | Détail |
|-------|--------|
| FormRepository | -376 lignes → délègue à FormStepsTrait, FormFieldsTrait, FormOwnersTrait |
| WorkflowEngine | -274 lignes → délègue à WorkflowAdvancer |
| AuthService | -154 lignes → délègue à AdminRequestManagementTrait |
| SubmissionRepository | -137 lignes → délègue à SubmissionPurgeTrait |
| DashboardRenderer | -70 lignes → template submission_detail.php |
| Bugs fixés | `updateField()` et `updateStep()` (check falsy incorrect) |
| Tests | PHPUnit + E2E OK |

### v10.42.0 — H-01 Refactor 14 fichiers > 350 lignes
| Tâche | Détail |
|-------|--------|
| AdminFormsRenderer | 1331 → 281 lignes (-79%) — 10 templates extraits + AdminFormsContext DTO |
| MonitoringRenderer | 698 → 233 lignes (-67%) — 7 sections en templates |
| InstallRenderer | 402 → 165 lignes (-59%) — wizard en templates |
| TokenRepository | 578 → 17 lignes (-97%) — délègue à TokenReadQueriesTrait + TokenWriteQueriesTrait |
| lib_wrappers.php | 573 → 67 lignes (-88%) — splitté en 12 fichiers spécialisés |
| SubmissionRepository | 799 → 733 lignes (en cours) — traits en cours |
| FormRepository | 549 → 604 lignes (refactoré avec traits) |
| AuthService | 401 → 451 lignes (refactoré avec trait) |
| FormController | 465 → 504 lignes (templates) |
| AdminSettingsRenderer | 517 → 280 lignes (en cours) |
| DashboardRenderer | 437 → 475 lignes (templates) |
| NavigationRenderer | 396 → 426 lignes (templates) |

### v10.38.2 — H-01 DocumentationService refactorisé (1754→83)
| Tâche | Détail |
|-------|--------|
| DocumentationService | 1754 → 83 lignes (-95%) — 11 méthodes render* câblées sur templates existants via `loadTemplate()` |
| Templates vérifiés | 11 templates `src/Docs/templates/*.php` mis à jour avec contenu extrait des méthodes |
| `admin_section.php` | 388 lignes — template HTML, pas du code (hors scope règle 350) |

### v10.41.0 — Refactor DocumentationService (templates créés) + SampleFormsService
| Tâche | Détail |
|-------|--------|
| DocumentationService | Templates créés dans `src/Docs/templates/` (11 fichiers) mais classe inchangée |
| SampleFormsService | Emails `@exemple.invalid` rendus configurables via setting `sample_form_domain` |
| H-08 | AuthServiceTest : 11 markTestSkipped supprimés (seed phpunit_bootstrap) |

### v10.38.0 — Fix isolation tests + suppression encryption morte
| Tâche | Détail |
|-------|--------|
| Isolation tests | BackupControllerTest ne supprime plus `testeur@e2e.test` de admins — full suite passe (1415 tests, 0 fail) |
| Encryption supprimée | 11 tests retirés (feature morte, APP_ENCRYPTION_KEY jamais en prod) |
| Tests obsolètes | 3 tests retirés (c44f21b, properties `$database` supprimées des services) |
| Admin routes e2e | `admin@ci.test` → `testeur@e2e.test` (30 failures fixées) |
| PHPStan | 0 erreur, level 8 |

### v10.28.0 — Persona self-agent, suppression confirmation
| Tâche | Détail |
|-------|--------|
| Persona self-agent | L'admin voit l'interface avec ses propres droits réduits (pas un autre utilisateur) |
| Suppression confirmation | Flow persona en 1 clic (avant : 3 étapes). ConfirmActionController allégé. |
| Code mort supprimé | `findDistinctSubmitters()` (SubmissionRepository), `persona_start`/`persona_stop` (ConfirmActionController) |
| Tests TDD | `testPersonaGetDoesNotRedirectToConfirmation`, `testPersonaStopDoesNotRedirectToConfirmation` |

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

### Audit CTO (rapport : download/CTO_AUDIT_REPORT.md)

55 problèmes identifiés (10 CRITICAL, 13 HIGH, 17 MEDIUM, 15 LOW).
Corrigés : C-06 (email CI), C-08 (README), C-10 (jobs CI), DTO taux_validation, Rector.
Décision projet non négociable : C-04 (display_errors=1) et C-05 (SMTPDebug=3) — voulu.

**Reste à traiter (0 CRITICAL) :**

✅ **Tous les problèmes CRITICAL sont corrigés !**

| # | Problème | Statut |
|---|----------|--------|
| C-03 | `AuditLogService::log()` avale exceptions | ✅ **Corrigé** — suppression du `try/catch`, exceptions se propagent naturellement + fix `!== false` → `!== ''` |
| C-07 | `disallowed-calls.neon` non chargé | ✅ **Déjà inclus** — vérifié, présent dans `phpstan.neon` ligne 4 |

**Corrigés :**
| # | Problème | Statut |
|---|----------|--------|
| C-09 | `schema_initial.php` avale erreurs seeding | ✅ **Corrigé** — exceptions rethrow après `error_log()` |
| H-08 | 45 markTestSkipped | ✅ **Corrigé** — 42 skips légitimes conservés, `SubmissionViewRenderer` créée |
| Encryption (C-01/C-02) | Code encryption | ✅ **Supprimé** — décision projet : zéro encryption (175 lignes de code retirées) |
| H-01 | 13 fichiers > 350 lignes | ✅ **Corrigé** — 0 fichier restant (tous refactorisés) |

**Non-applicable (décision projet) :**
| # | Problème | Décision |
|---|----------|----------|
| C-01 | `SettingsService::encrypt()` silent plaintext | **Zéro encryption** — feature inutile, jamais activée en prod |
| C-02 | AES-256-CBC sans HMAC/GCM | **Zéro encryption** — idem |

**Reste à traiter (HIGH/MEDIUM/LOW) :**
- ~~H-01 : 13 fichiers > 350 lignes~~ ✅ **TERMINÉ** — 0 fichier > 350 lignes !
  - Top 5 : FormJsonValidator (347), FormRenderer (345), TokenService (343), WorkflowAdvancer (340), AdminAlertsRenderer (334)
- ~~H-08 : 45 markTestSkipped~~ ✅ **TERMINÉ** — 42 skips légitimes conservés, `SubmissionViewRenderer` créée
- CS Fixer : 2 fichiers avec heredoc à réindenter (AdminFormsRenderer, NavigationRenderer)
- 17 MEDIUM + 15 LOW (cf. rapport complet)

### Baseline PHPStan (816 erreurs — toutes LOW, baseline regenerée)

Toutes les erreurs restantes sont des règles strictes de `phpstan-strict-rules` (style, pas des bugs) ou des faux positifs shipmonk.

Progrès : toutes les `empty()` dans `src/` remplacées (plus que 5 dans alert_check.php, hors scope).
Baseline régénérée de 2 → 816 erreurs (intègre maintenant toutes les erreurs ignorées).

| Catégorie | Count |
|-----------|-------|
| `variable.undefined` | ~224 |
| `noUntypedArray` | ~179 |
| `booleanNot.exprNotBoolean` | ~62 |
| `if.condNotBoolean` | ~37 |
| `shipmonk.deadMethod` | ~36 |
| `missingType.iterableValue` | ~27 |
| `ternary.condNotBoolean` | ~26 |
| Autres | ~225 |

### DTOs Renderer — migration array $ctx → DTOs typés

Migration terminée pour les catch-all `array<string, mixed> $ctx`/`$state`.
Reste : paramètres individuels déjà documentés en array shapes PHPDoc — bénéfice marginal (DTO seulement si on touche au fichier par ailleurs).

| DTO | Renderer | Propriétés | Statut |
|-----|----------|-----------|--------|
| `AdminFormsContext` | `AdminFormsRenderer` | 14 | **Fait** |
| `SubmissionViewContext` | `SubmissionViewRenderer` | 27 | **Fait** |
| `MonitoringContext` | `MonitoringRenderer` | 27 | **Fait** |
| `AdminSettingsContext` | `AdminSettingsRenderer` | 4 | **Fait** |

### Bug backlog audit — 29/29 vérifiés, 29 fixés

Audit complet des 29 bugs fonctionnels identifiés lors de l'audit initial :
- **29 confirmés fixés** par les sessions précédentes
- **0 reste à corriger**
- **1 faux positif** : #26 (JargonService) — le service est vivant (81 références via `t_jargon()` → `JargonService::translate()`), le TODO avait tort


### Relance personnalisable (feature demandée 2026-08-07)

Permettre de personnaliser **l'intervalle de rappel** et **le nombre de rappels** par formulaire/workflow (aujourd'hui globaux : `delai_relance_h` = 48h, `relance_max` = 3, settings admin).

- Objectif : configurable par formulaire (ou par workflow), pas seulement global
- **Contrainte : garder un cap** (large mais borné) — pas de relances illimitées
- Contexte : self-reminder via formulaire « Demande de télétravail » (PoC admin_forms)

### CSP — zéro inline (décision 2026-07-30)

**Fait** — cleanup complet le 2026-08-01.

| Directive | État | Détail |
|---|---|---|
| `script-src` | **Fait** | `unsafe-inline` retiré. Scripts noncés via `SecurityService::getScriptNonce()`. |
| `style-src` | **Fait** | `unsafe-inline` retiré (2026-08-01). 84 style="" migrés vers classes CSS. `<style>` noncés. |
| `style-src-attr` | **Fait** | Zéro style="" dans les pages web (cleanup complet). |

Exclusions légitimes : templates email (MailService, TokenService, etc.) — les emails ne passent pas par le header CSP.



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

_Dernière mise à jour : 2026-08-05 (v10.38.2)_
