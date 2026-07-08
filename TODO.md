# TODO — CircuitDémat

## Session 2026-07-08 — Résumé

**39 commits** | **724 tests** | **0 failures**

### ✅ Terminé cette session

| Phase | Détail | Commits |
|-------|--------|---------|
| Interfaces | SecurityInterface, AuthInterface complétées | 2 |
| Repository Pattern | BaseRepository + 7 domain repos (Form, Submission, Token, Settings, Admin, Audit, Attachment) | 18 |
| Bug fixes | AttachmentRepository, AdminRepository, WorkflowEngineTest | 3 |
| Cleanup | Dead $db properties, repository boundaries | 2 |
| Services extraits | ValidationService, EmailVerificationService, ExportService | 3 |
| Migration DI | Tous les appels directs lib/ → DI container | 2 |
| Consolidation lib/ | 7 wrappers consolidés vers services src/ | 1 |
| Tests | +120 tests couverture, round-trip tests | 3 |
| Documentation | CHANGELOG v10.5.0, AGENT.md, graph.html | 3 |

---

## 🔴 Priorité haute (P1-P2)

### BaseController DI
- **Problème** : `BaseController` a 22 connexions, connecte 10 communautés
- **Action** : Injecter les services via DI container au lieu de les instancier directement
- **Fichiers** : `src/Controller/BaseController.php`, tous les contrôleurs enfants
- **Effort** : Moyen

### h() validation
- **Problème** : 79 arêtes inferred risquent de masquer des bugs
- **Action** : Valider les connexions et supprimer les faux positifs
- **Fichiers** : `lib/html.php`, tous les fichiers qui appellent `h()`
- **Effort** : Faible

### Repository Pattern — Migration pages/
- **Problème** : Les contrôleurs utilisent encore `get_pdo()` directement
- **Action** : Injecter les repositories dans les contrôleurs
- **Fichiers** : `src/Controller/*.php`, `pages/*.php`
- **Effort** : Moyen

---

## 🟡 Priorité moyenne (P2-P3)

### Community 0 decomposition
- **Problème** : 149 communautés, Community 0 est le catch-all (cohésion 0.058)
- **Action** : Décomposer en sous-services cohérents
- **Sous-tâches** :
  - Auth module : lib_auth + lib_persona → AuthService
  - Mail module : lib_mail → MailService (déjà fait, supprimer appels directs)
  - Token module : lib_tokens → TokenService
  - Workflow module : lib_workflow → WorkflowEngine
  - Webhook module : lib_webhook → WebhookService (déjà fait)
- **Effort** : Élevé

### Réduire les communautés
- **Problème** : 149 communautés, objectif < 20 avec cohésion > 0.2
- **Action** : Consolidation progressive
- **Effort** : Élevé

### Test DB cleanup
- **Problème** : FK cassées dans la DB test (12 tokens orphelins)
- **Action** : Nettoyer les données de test, ajouter des contraintes FK
- **Effort** : Faible

---

## 🟢 Priorité basse (P3)

### Migration pages/ vers Controllers
- **Problème** : Les pages/ sont encore procédurales
- **Action** : Convertir en controllers OOP
- **Effort** : Élevé

### Composer autoload optimization
- **Problème** : `classmap-authoritative: true` nécessite `composer dump-autoload` après chaque ajout de classe
- **Action** : Évaluer PSR-4 completion ou optimiser le processus
- **Effort** : Faible

### PHPStan level up
- **Problème** : Niveau actuel inconnu
- **Action** : Augmenter le niveau progressivement
- **Effort** : Moyen

---

## 📊 Métriques actuelles

| Métrique | Valeur |
|----------|--------|
| Tests | 724 |
| Failures | 0 |
| Services src/ | 18 |
| Repositories | 8 (Base + 7 domain) |
| Interfaces | 11 |
| Commits cette session | 39 |

---

## 🎯 Objectifs à long terme

1. **Architecture cible** : < 20 communautés avec cohésion > 0.2
2. **Test coverage** : > 80% pour tous les services
3. **Zero direct calls** : Aucun appel direct aux fonctions lib/ depuis les contrôleurs
4. **PHPStan level 8** : Typage strict complet

---

_Dernière mise à jour : 2026-07-08_
