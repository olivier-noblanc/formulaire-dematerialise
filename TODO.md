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

---

## 🎯 Ce qui reste

| Tâche | Effort | Détail |
|-------|--------|--------|
| Tests E2E HTTP en CI | Moyen | Paths cross-platform déjà faits, reste à ajouter au pipeline CI |
| gate.sh sync check.ps1 | Faible | Comparer les 2 scripts et synchroniser les étapes manquantes |

---

## ⚠️ Leçons apprises

1. **TOUJOURS commit avant d'écraser un fichier existant.**
2. **Quand un test modifie `$_SERVER`, le restaurer en tearDown** — sinon les tests suivants échouent silencieusement.
3. **Quand un test dépend d'un état non-admin, le définir explicitement** — pas compter sur un état implicite du `$_SERVER`.

---

_Dernière mise à jour : 2026-07-18_
