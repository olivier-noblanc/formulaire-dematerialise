# TODO — CircuitDémat

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **1350** |
| Assertions | **1991** |
| PHPStan erreurs | **0** (level 8) |
| Tests E2E | 30 |
| Bugs dispatch corrigés | 7 |

---

## 🎯 Ce qui reste (optionnel)

| Tâche | Effort | Détail |
|-------|--------|--------|
| Unifier SubmissionStatus | Faible | `App\SubmissionStatus` (EN_COURS/VALIDE) vs `App\Enum\SubmissionStatus` (EnCours/Valide) — WorkflowEngine utilise l'ancien |
| Tests E2E en CI | Moyen | Besoin d'un serveur PHP dev pour les 30 tests HTTP |
| PHPDoc workflow cache | Faible | `getWorkflowSteps()` a un static $cache qui survit entre requêtes dans le même process |

---

_Dernière mise à jour : 2026-07-16_
