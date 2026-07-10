# TODO — CircuitDémat

## Session 2026-07-10 — Résumé

### ✅ Terminé

| Tâche | Détail | Résultat |
|-------|--------|----------|
| Nettoyage lib/ | 11 wrappers supprimés | ✅ lib/ : 14 fichiers |
| Fix autoload IIS | vendor/composer/ commité | ✅ Erreur prod résolue |
| BackupController | Migration vers BackupRenderer | ✅ |
| Documentation | AGENTS.md mis à jour (règles test, réseau, IIS) | ✅ |
| TODO.md | Retiré du .gitignore, versionné | ✅ |

---

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **855** (0 failures) |
| lib/ fichiers | **14** (wrappers thin) |
| Pages procédurales | **0** |
| Controllers | **27** |
| PHPStan level | **8** |
| PHPStan baseline | **312** erreurs |
| Repositories | **8** |
| vendor/composer | **commité** (IIS offline) |
| Coverage HtmlService | **100%** |
| Coverage FormRepo | **82%** |
| Coverage BaseRepo | **81%** |

---

## 🎯 Ce qui reste (nice-to-have)

| Tâche | Effort | Impact | Détail |
|-------|--------|--------|--------|
| Supprimer service_wrappers.php | ~3h | Élevé | 54 wrappers → supprimer, remplacer ~102 appels par DI |
| Supprimer 12 render wrappers | ~1h | Moyen | Les controllers appellent déjà les classes OOP |
| PHPStan baseline (312 erreurs) | Moyen | Qualité code | method.nonObject, argument.type |
| Coverage > 80% : AuthService (76%), AttachmentService (62%) | Moyen | Fiabilité | Infrastructure |
| Coverage > 80% : ExportService (4%), WorkflowEngine (32%) | Élevé | exit(), network |
| Réduire communautés < 20 | Élevé | Architecture | Consolidation progressive |

---

_Dernière mise à jour : 2026-07-10_
