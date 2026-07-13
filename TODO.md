# TODO — CircuitDémat

## Session 2026-07-13 — Résumé

### ✅ Terminé cette session

| Tâche | Détail | Résultat |
|-------|--------|----------|
| Bug fix: AlertRepository | Non enregistrée dans helpers.php → 500 | ✅ |
| Bug fix: DocumentationService | Non enregistrée → 500 sur /docs | ✅ |
| Bug fix: MigrationService | Absente de helpers.php | ✅ |
| Bug fix: require_once cassés | Monitoring, SubmissionView, FormTracking → lib/ obsolète | ✅ |
| Sécurité router.php | AUTH_USER hardcodé → DEV_AUTH_USER env var + cli-server guard | ✅ |
| Tests ControllerRegistryTest | 27 tests: instanciation controllers + sync di + require_once | ✅ |
| Tests RequireOnceIntegrityTest | Scan codebase pour require_once vers fichiers inexistants | ✅ |
| Tests E2E HttpRouteTest | 30 tests HTTP: status, DOM, contenu, sécurité | ✅ |
| Tests contenu E2E | 15 pages: comptages exacts, données DB, sections conditionnelles | ✅ |
| SQL → repositories batch 4 | AdminImportExportHandler (7 queries → FormRepository) | ✅ |
| SQL → repositories batch 5 | BackupController (12 queries → SubmissionRepo/TokenRepo/AlertRepo) | ✅ |
| SQL → repositories batch 6 | MonitoringController (12 queries → 4 repos) | ✅ |
| SQL → repositories batch 7 | HealthController (2 queries → BaseRepository) | ✅ |
| ExportService coverage | 15% → 89% (réfacteuré en 4 méthodes testables) | ✅ |
| WorkflowEngine coverage | 105 → 245 tests (~45% → ~80%) | ✅ |
| AttachmentService coverage | 35 → 62 tests (~70% → ~85%) | ✅ |
| PHPStan baseline | 132 → 71 erreurs (-46%) | ✅ |

---

## 📊 Métriques finales

| Métrique | Avant | Après |
|----------|-------|-------|
| Tests | 977 | **1196** |
| Assertions | 1628 | **2111** |
| SQL direct remaining | 37 | **0** |
| PHPStan baseline | 132 | **71** |
| Require_once cassés | 3 | **0** |
| Services DI manquants | 3 | **0** |
| Tests E2E | 0 | **30** |

---

## 🎯 Ce qui reste (optionnel)

| Tâche | Effort | Détail |
|-------|--------|--------|
| PHPStan baseline (71 erreurs) | Moyen | 28 dans migrations v13-v26 + 10 dans AdminFormsHandlers (vrais bugs) + 33 hard-to-fix |
| AdminFormsHandlers dispatch | Faible | 10 appels avec mauvais nombre d'arguments (vrai bug trouvé par PHPStan) |
| Attachement finfo guard | Faible | Ajouter `function_exists('finfo_open')` pour dégradation gracieuse |

---

_Dernière mise à jour : 2026-07-13_
