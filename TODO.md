# TODO — CircuitDémat

## Session 2026-07-09 — Résumé complet

**12 tâches lancées en parallèle** | **Toutes terminées**

### ✅ Terminé (2 sessions)

| Tâche | Détail | Résultat |
|-------|--------|----------|
| T1 — BaseController DI | Injection via constructor `?App $app` | ✅ |
| T2 — h() validation | 467 call sites vérifiés, 0 problème | ✅ |
| T3 — Repository Pattern | 7 repositories injectés | ✅ |
| T4 — Community 0 decomposition | Déjà en place | ✅ |
| T5 — Test DB cleanup | 130→19 erreurs | ✅ |
| T6 — Pages → Controllers | 3 pages migrées | ✅ |
| T7 — Composer autoload | PSR-4 natif | ✅ |
| T8 — PHPStan level up | Niveau 6→7 | ✅ |
| T13 — Réduire communautés | lib/ : 63→48 fichiers (-15) | ✅ |
| T14 — Migration pages/ | 16 controllers migrés (19 routes) | ✅ |
| T15 — PHPStan level 8 | Niveau 7→8, erreurs corrigées | ✅ |
| T16 — Test coverage | +219 tests (724→943) | ✅ |

---

## 📊 Métriques finales

| Métrique | Avant (08/07) | Après (09/07) |
|----------|---------------|---------------|
| Tests | 724 | **943** |
| Failures | 0 | **0** |
| Erreurs DB | 130 | **19** (pré-existantes) |
| PHPStan level | 6 | **8** |
| PHPStan baseline | — | **312** erreurs |
| Pages migrées | 0 | **19** (toutes) |
| Repositories injectés | 0 | **7** |
| lib/ fichiers | 63 | **48** |
| Composer autoload | classmap | **PSR-4** |
| Coverage HtmlService | 39% | **100%** |
| Coverage FormRepo | 36% | **82%** |
| Coverage BaseRepo | 67% | **81%** |

---

## 🎯 Ce qui reste (nice-to-have)

| Tâche | Effort | Bloqué par |
|-------|--------|-----------|
| Réduire communautés < 20 | Élevé | Consolidation progressive |
| PHPStan : corriger baseline 312 erreurs | Moyen | method.nonObject, argument.type |
| Coverage > 80% : AuthService (76%), AttachmentService (62%), WebhookService (59%) | Moyen | Infrastructure (LDAP/SMTP/network) |
| Coverage > 80% : ExportService (4%), EmailVerification (16%), WorkflowEngine (32%) | Élevé | exit(), network, DB complexe |

---

_Dernière mise à jour : 2026-07-09_
