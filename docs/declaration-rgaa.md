# Déclaration d'accessibilité RGAA — CircuitDémat

**Établissement** : DREETS Bourgogne-Franche-Comté
**Application** : CircuitDémat
**Date de la déclaration** : 03/07/2026
**Responsable de l'accessibilité** : Olivier Noblanc (DREETS BFC)

---

## Article 1 — Conformité

CircuitDémat est **partiellement conforme** au Référentiel Général d'Amélioration
de l'Accessibilité (RGAA) version 4.1, en raison des non-conformités et des
dérogations énumérées ci-dessous.

## Article 2 — Méthode et outils

L'audit a été réalisé avec :
- **Navigateurs de test** : Edge (Chromium), Firefox
- **Outils d'évaluation** : Wave Accessibility Evaluation Tool, axe DevTools
- **Lecteur d'écran** : NVDA (Windows)
- **Tests clavier** : navigation 100% au clavier (Tab, Shift+Tab, Enter, Escape)

## Article 3 — Résultats du test

| Critère | Statut |
|---------|--------|
| Navigation au clavier | ✅ Conforme |
| Contraste des couleurs | ✅ Conforme (WCAG AA) |
| Textes alternatifs (images) | ✅ Conforme |
| Structure des titres (h1-hh6) | ✅ Conforme |
| Formulaires (labels, erreurs) | ✅ Conforme |
| Liens textuels | ✅ Conforme |
| Skip link (aller au contenu) | ✅ Conforme |
| ARIA landmarks | ✅ Conforme (nav, main, footer) |
| Langue de la page (lang="fr") | ✅ Conforme |
| Taille de texte (16px minimum) | ✅ Conforme |

## Article 4 — Non-conformités connues

| Non-conformité | Niveau | Plan de correction |
|----------------|--------|-------------------|
| Diagrammes de workflow (SVG) non décrits par ARIA | AA | Ajouter `aria-label` descriptif sur les SVG de workflow |
| Tableau de bord : tri des colonnes non annoncé au lecteur d'écran | AA | Ajouter `aria-sort` sur les th cliquables |
| Notifications toast non annoncées via `aria-live` | AA | Ajouter `role="status"` + `aria-live="polite"` sur les toasts |

## Article 5 — Dérogations

Aucune dérogation pour le moment.

## Article 6 — Schémas d'accessibilité

CircuitDémat est une application interne (intranet DREETS), non soumise à
l'obligation de schéma d'accessibilité.

## Article 7 — Voies de recours

Si vous constatez un défaut d'accessibilité vous empêchant d'accéder à un
contenu ou une fonctionnalité de l'application, et que vous n'obtenez pas
de réponse satisfaisante du responsable de l'application, vous pouvez :

1. Contacter le responsable accessibilité : admin.local@exemple.invalid
2. Saisir le Défenseur des droits (https://www.defenseurdesdroits.fr)

---

## Engagements

- Audit complet RGAA 4.1 réalisé le 03/07/2026
- Les non-conformités connues seront corrigées dans la version 11.0
- Un nouvel audit sera réalisé après chaque refonte majeure
