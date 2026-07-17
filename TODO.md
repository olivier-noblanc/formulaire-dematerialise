# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **1351** |
| Assertions | **2070** |
| PHPStan erreurs | **0** (level 8) |
| Tests E2E | 30 |
| Bugs dispatch corrigés | 7 |

---

## ✅ Terminé cette session

| Tâche | Détail |
|-------|--------|
| Fix relances parasites | v27 migration + transaction advanceWorkflow + debug log |
| Fix tests RgpdServiceTest + WorkflowEngineTest | 6 erreurs pré-existantes corrigées |
| Unification SubmissionStatus | Suppression `App\SubmissionStatus`, tous les fichiers migrent vers `App\Enum\SubmissionStatus` |
| Suppression static cache getWorkflowSteps | Élimine données obsolètes dans process longs |
| Paths cross-platform | `/tmp/` → `sys_get_temp_dir()`, `lsof` → `kill_port()`, `/home/z/` → `php` |
| check.ps1 : PHPStan + PHPUnit | Gate complète avec parallélisation PHPStan/PHPUnit |
| Hook pre-commit | Lance check.ps1 via `pwsh -NoProfile` |
| NOPROXY curl tests | Bypass proxy corporate sur appels localhost |

---

## 🎯 Ce qui reste (optionnel)

| Tâche | Effort | Détail |
|-------|--------|--------|
| Fix test_form_render_html | Faible | 7/8 passent — le test "Demande enregistrée" échoue (pré-existant) |
| Tests E2E HTTP en CI | Moyen | Paths déjà cross-platform, reste à ajouter `php tests/test_http.php` au pipeline CI |
| gate.sh同步 check.ps1 | Faible | gate.sh a des étapes (mail escaping, cache, email URLs, broken URLs) absentes de check.ps1 |

---

_Dernière mise à jour : 2026-07-16_
