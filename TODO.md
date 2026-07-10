# TODO — CircuitDémat

## Session 2026-07-09 — Résumé complet

**Phase 1-4 terminées** | **Procédural éliminé**

### ✅ Terminé

| Phase | Détail | Résultat |
|-------|--------|----------|
| Phase 1 | Suppression pages dead code + autoloader | ✅ 18 pages + 1 fichier supprimés |
| Phase 2 | DocsController + ValidateController | ✅ 2 controllers créés |
| Phase 3 | Absorption handlers procéduraux | ✅ 4 classes OOP créées |
| Phase 4 | Render templates → OOP classes | ✅ 14 renderers créés |
| T1-T16 | Sessions précédentes | ✅ Tout terminé |

---

## 📊 Métriques finales

| Métrique | Avant (08/07) | Après (09/07) |
|----------|---------------|---------------|
| Pages procédurales | 25 | **0** |
| Controllers | 0 | **27** |
| Fichiers lib/ | 63 | **26** (wrappers thin) |
| Taille max lib/ | 39KB | **14KB** |
| Tests | 724 | **943** |
| Failures | 0 | **0** |
| PHPStan level | 6 | **8** |
| PHPStan baseline | — | **312** erreurs |
| Repositories | 0 | **8** |
| Coverage HtmlService | 39% | **100%** |
| Coverage FormRepo | 36% | **82%** |
| Coverage BaseRepo | 67% | **81%** |

---

## 🎯 Ce qui reste (nice-to-have)

| Tâche | Effort | Bloqué par |
|-------|--------|-----------|
| Supprimer les wrappers lib/ (quand plus appelés) | Faible | Migration des appelants restants |
| PHPStan : corriger baseline 312 erreurs | Moyen | method.nonObject, argument.type |
| Coverage > 80% : AuthService (76%), AttachmentService (62%) | Moyen | Infrastructure |
| Coverage > 80% : ExportService (4%), WorkflowEngine (32%) | Élevé | exit(), network |
| Réduire communautés < 20 | Élevé | Consolidation progressive |

---

_Dernière mise à jour : 2026-07-09_
