# TODO — CircuitDémat

## Session 2026-07-11 — Ultrareview

### ✅ Terminé

| Tâche | Détail | Résultat |
|-------|--------|----------|
| Bug fixes (C-1,C-5,W-1,W-2,W-4,W-14,W-15) | 7 bugs logiques/data corrigés | ✅ |
| Sécurité (W-5,W-6,P-7,P-6,P-3,W-7,P-4) | CSRF, realpath, error disclosure, CSP nonce | ✅ |
| Performance (W-8,W-9,P-8,C-2,C-3) | Queries optimisées, streaming CSV, pagination | ✅ |
| Refactor SQL (C-7) | 9 contrôleurs → repositories, +30 méthodes repo | ✅ |
| Merge services (W-17) | FieldService/ValidatorDataService dédupliqués | ✅ |
| Timezone + double query (P-1,P-2,P-11) | UTC, réutilisation requête, longueur clé | ✅ |

---

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **995** (0 failures) |
| lib/ fichiers | **1** (core_bootstrap uniquement) |
| Controllers | **27** |
| Repositories | **9** (+AlertRepository) |
| PHPStan level | **8** |
| PHPStan baseline | **142** erreurs |
| Constats ultrareview | **0** critiques restants |

---

## 🎯 Ce qui reste (nice-to-have)

| Tâche | Effort | Impact | Détail |
|-------|--------|--------|--------|
| PHPStan baseline (142 erreurs restantes) | Moyen | Qualité code | missingType.iterableValue (phpdoc manquants) |
| Coverage > 80% : ExportService (~15%) | Élevé | exit() empêche le test direct |
| Coverage > 80% : WorkflowEngine (~45%) | Moyen | Couvrir les branches restantes |
| Coverage > 80% : AttachmentService (~70%) | Faible | fileinfo extension manquante |

---

_Dernière mise à jour : 2026-07-11_
