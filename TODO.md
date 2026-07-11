# TODO — CircuitDémat

## Session 2026-07-11 — Résumé

### ✅ Terminé

| Tâche | Détail | Résultat |
|-------|--------|----------|
| Supprimer service_wrappers.php | 54 wrappers → DI directe + fichier supprimé | ✅ |
| Supprimer render wrappers | 10 fichiers render/ + security.php migrés | ✅ |
| Supprimer h() wrapper | 544 call sites → App::html()->escape() | ✅ |
| PHPStan baseline | 312 → 142 erreurs (-54%) | ✅ |
| Tests coverage | +62 tests (Attachment, Auth, Export, Workflow) | ✅ |
| Documentation | CHANGELOG.md mis à jour (v10.10.0) | ✅ |

---

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **995** (0 failures) |
| lib/ fichiers | **1** (core_bootstrap uniquement) |
| Pages procédurales | **0** |
| Controllers | **27** |
| PHPStan level | **8** |
| PHPStan baseline | **142** erreurs |
| Repositories | **8** |
| vendor/composer | **commité** (IIS offline) |
| Coverage HtmlService | **100%** |
| Coverage FormRepo | **82%** |
| Coverage BaseRepo | **81%** |
| Coverage AuthService | **~82%** |
| Coverage AttachmentService | **~70%** |

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
