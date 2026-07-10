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

| Tâche | Effort | Impact |
|-------|--------|--------|
| Supprimer wrappers lib/ restants (appels → DI) | Faible | Cosmétique |
| PHPStan baseline (312 erreurs) | Moyen | Qualité code |
| Coverage > 80% : AuthService (76%), AttachmentService (62%) | Moyen | Fiabilité |
| Coverage > 80% : ExportService (4%), WorkflowEngine (32%) | Élevé | exit(), network |
| Réduire communautés < 20 | Élevé | Architecture |

---

_Dernière mise à jour : 2026-07-10_
