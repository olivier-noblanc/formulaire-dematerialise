# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **1500** |
| Assertions | **2513** |
| PHPStan erreurs | **0** (level 8) |
| Tests E2E | 30 |
| Skipped | **0** |
| Errors | **0** |
| Failures | **0** |
| Warnings | **0** |
| Deprecations | **0** (PHP 8.5) |
| xdebug | **off** (permanent) |

---

## 📏 Règles de taille

| Règle | Limite | Détail |
|-------|--------|--------|
| **Fichiers PHP** | **350 lignes max** | Toute classe/fichier ne doit pas dépasser 350 lignes. Si plus long, splitter en plusieurs fichiers. |

---

## ✅ Terminé cette session (v10.19.0)

| Tâche | Détail |
|-------|--------|
| Fix TokenService constructeur | 6 args → 5 (WorkflowEngine supprimé) |
| Fix test PDO busy_timeout | PRAGMA busy_timeout = 5000 ajouté |
| Fix ExportServiceTest slugs | 8 slugs hardcodés → uniqid() |
| Fix GlobalFunctionsTest regex | PCRE2 lookbehind corrigé |
| Fix setAccessible() déprécié | 4 appels supprimés (PHP 8.5) |
| Fix saveValidatorData() INSERT | UUID ajouté (id NOT NULL manquant) |
| Fix addOwner() INSERT | UUID ajouté (id NOT NULL manquant) |
| Migration v28 | tokens.action, admin_requests.reviewed_at/reviewed_by, seed testeur@e2e.test |
| Fix RgpdServiceTest skip | Submission créée dans le test au lieu de markTestSkipped |
| Fix 77 WorkflowEngineTest skips | Pattern DELETE-based cleanup (helpers + tearDown) |
| Fix 4 AuthServiceTest skips | tearDown restaure $_SERVER, tests non-admin explicit user |
| Fix 2 TokenServiceTest failures | Tests non-admin définissent explicitement $_SERVER |
| Fix persona test skip | 3 scénarios testés (admin, non-admin, persona_token) |
| Split WorkflowEngineTest | 3370 lignes → 10 fichiers <350 lignes sous WorkflowEngineTest/ |
| xdebug off permanent | xdebug.mode=off dans cli/conf.d/xdebug.ini |
| check.ps1 sync gate.sh | 6 étapes ajoutées (mail_escaping, cache_service, email_urls, no_broken_urls, phpmailer_warnings, assets_cache) |
| CI PHPUnit + E2E | PHPUnit (1500 tests) + E2E HTTP ajoutés au pipeline Woodpecker |
| CI upgrade | PHP 8.4→8.5, PHPStan via composer au lieu de wget |

---

## 🎯 Ce qui reste

Rien. Tout est fait.

---

## ⚠️ Leçons apprises

1. **TOUJOURS commit avant d'écraser un fichier existant.**
2. **Quand un test modifie `$_SERVER`, le restaurer en tearDown** — sinon les tests suivants échouent silencieusement.
3. **Quand un test dépend d'un état non-admin, le définir explicitement** — pas compter sur un état implicite du `$_SERVER`.

---

_Dernière mise à jour : 2026-07-18_
