# TODO — CircuitDémat

## Session 2026-07-12 — Résumé

### ✅ Terminé

| Tâche | Détail | Résultat |
|-------|--------|----------|
| Ultrareview v2 | 15 constats (3C + 10W + 2P) → 0 restants | ✅ |
| PRAGMA foreign_keys ON global | Database.php + 9 tests adaptés | ✅ |
| Transactions TokenService | regenerate() + delegate() wrappées | ✅ |
| Cascade delete complète | step_recipients, form_fields, form_owners + transaction | ✅ |
| WorkflowEngine fix | étapes conditionnelles + array_reduce + N+1 getValidatorData | ✅ |
| Return types corrigés | : array → : ?array sur 3 handlers delete | ✅ |
| Extraction renderers | 7 renderers créés, 7 controllers allégés | ✅ |
| PHP 8.5+ check | install.php + HealthController mis à jour | ✅ |
| N+1 export fix | GROUP_CONCAT au lieu de boucle SQL | ✅ |
| Nettoyages | $pdo inutilisé, double if, rethrow, $result non initialisé | ✅ |

---

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | **977** (0 failures) |
| lib/ fichiers | **1** (core_bootstrap uniquement) |
| Pages procédurales | **0** |
| Controllers | **27** |
| PHPStan level | **8** |
| PHPStan baseline | **132** erreurs (annotations manquantes) |
| Repositories | **9** |
| Renderers | **7** |
| PRAGMA foreign_keys | **ON global** |
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
| PHPStan baseline (132 erreurs restantes) | Moyen | Qualité code | missingType.iterableValue (phpdoc manquants) |
| SQL → repositories (ConfirmAction, MyValidations, Stats) | Moyen | Cohérence | 3 controllers avec SQL direct restant |
| Coverage > 80% : ExportService (~15%) | Élevé | exit() empêche le test direct |
| Coverage > 80% : WorkflowEngine (~45%) | Moyen | Couvrir les branches restantes |
| Coverage > 80% : AttachmentService (~70%) | Faible | fileinfo extension manquante |

---

_Dernière mise à jour : 2026-07-12_
