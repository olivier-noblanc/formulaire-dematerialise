# Changelog — CircuitDémat

## [5.10.0] — 2026-06-15

### Feature — Suggestions LDAP sur les champs courriel (pur HTML5, zéro JS)

- **Autocomplétion LDAP via `<datalist>`** : Quand la suggestion LDAP est activée dans les paramètres, les champs de type « Courriel » (`email`) dans les formulaires publics et le champ « Ajouter un destinataire » dans l'administration proposent automatiquement les adresses de l'annuaire LDAP.
- **Pur HTML5** : Utilise l'élément natif `<datalist>` du navigateur. L'agent commence à taper et le navigateur filtre et propose les correspondances. Aucun JavaScript requis.
- **Fonction `ldap_suggest()`** : Nouvelle fonction dans helpers.php qui interroge l'annuaire LDAP avec un filtre configurable (par défaut : recherche sur cn, mail, sn, givenName). Retourne un tableau d'entrées `[email, cn]`.
- **Fonction `render_ldap_datalist()`** : Génère le HTML `<datalist>` avec les résultats LDAP. Un seul `<datalist>` par page, partagé par tous les champs email.
- **Cache fichier 30 min** : Les résultats LDAP sont mis en cache dans `db/cache/` pendant 30 minutes pour éviter de surcharger le serveur LDAP à chaque affichage de page.
- **Paramètres administrables** : Deux nouveaux paramètres dans Paramètres → Sécurité email → Configuration LDAP :
  - `ldap_suggest_enabled` : Case à cocher pour activer/désactiver les suggestions
  - `ldap_suggest_filter` : Filtre LDAP personnalisable pour la recherche (par défaut cherche sur nom, prénom, email)
- **Détection automatique** : Si le formulaire contient des champs de type `email` ou des champs texte dont le nom contient « email » / « courriel » / « mel », le `<datalist>` LDAP est automatiquement injecté.

## [5.9.0] — 2026-06-15

### Fix — Bouton Dupliquer illisible (texte blanc sur fond blanc)

- **CSS `.section-card-header button`** : La règle forçait `color: var(--c-text-inverse)` (blanc) sur tous les boutons dans l'en-tête des section-cards, y compris les `.btn-secondary` qui ont un fond blanc. Résultat : texte blanc sur fond blanc = invisible.
- **Correction** : Les boutons `.btn-secondary` dans `.section-card-header` héritent désormais de leur couleur de texte normale (`var(--c-sidebar-text)`), avec un fond blanc et une bordure. Seuls les boutons non-secondaires conservent le texte blanc.

### Fix — Bouton « Copier » ne fonctionne pas (HTTP sans HTTPS)

- **`navigator.clipboard.writeText()`** : Cette API n'est disponible que dans les contextes sécurisés (HTTPS ou localhost). En HTTP intranet, l'appel échoue silencieusement.
- **Fallback `document.execCommand('copy')`** : Ajout d'un fallback complet avec création d'un `<textarea>` temporaire pour les contextes non-HTTPS. Fonctionne maintenant dans tous les cas.
- **Concerne** : Le bouton « 📋 Copier » du prompt IA et le bouton « 📋 Copier le message » de validation JSON.

### Fix — Version 3.0.0 dans l'installateur

- **install.php** : Le script d'installation écrivait `APP_VERSION = '3.0.0'` dans le fichier config.php généré. La version est désormais synchronisée avec la version courante (5.9.0).

### Feature — Type de champ « Courriel » (email)

- **Nouveau `field_type` : `email`** : Ajouté dans le sélecteur de type de champ, la validation d'import JSON, le prompt IA, et le rendu du formulaire.
- **Rendu HTML5** : Les champs de type `email` utilisent `<input type="email">` avec validation de pattern email intégrée.
- **Avantage** : Avant, les champs email étaient de type `text` avec détection heuristique basée sur le `field_name` (si le nom contenait « email », « courriel » ou « mel »). Désormais, l'IA et l'import JSON peuvent explicitement créer des champs de type `email`.

### Feature — Destinataires dynamiques du workflow (syntaxe `{{field_name}}`)

- **Références dynamiques** : Les destinataires d'une étape de validation peuvent désormais contenir `{{field_name}}` pour faire référence à la valeur d'un champ du formulaire rempli par l'agent. Exemple : `{{email_superieur}}` envoie la demande de validation au supérieur hiérarchique saisi par l'agent.
- **Validation d'import** : La syntaxe `{{field_name}}` est acceptée dans `steps[].recipients` lors de l'import JSON (plus d'erreur « n'est pas une adresse email valide »).
- **Résolution à l'exécution** : La fonction `resolve_dynamic_recipient()` dans `helpers.php` résout les références `{{field_name}}` au moment où le workflow avance, en lisant les données soumises par l'agent. Si la référence ne peut être résolue ou n'est pas un email valide, le destinataire est ignoré avec un log d'erreur.
- **Prompt IA mis à jour** : Le prompt IA explique la syntaxe `{{field_name}}` et donne un exemple concret (demande de congé avec validation du supérieur hiérarchique).
- **Cas d'usage** : Formulaire de demande de congé, formulaire de mobilité, ou tout formulaire où le validateur dépend de l'agent qui remplit le formulaire.

### Fix — Section propriétaires du formulaire (structure section-card)

- **Structure manquante** : La section « Propriétaires du formulaire » n'utilisait pas la structure `.section-card-header` / `.section-card-body` standard, ce qui causait un rendu visuellement incohérent avec les autres sections.
- **Correction** : Ajout des `div.section-card-header` et `div.section-card-body` pour un rendu cohérent.

## [5.8.0] — 2026-06-15

### Feature — Nom et favicon dynamiques (configurables depuis la BDD)

- **Nom de l'application en base de données** : Le nom affiché (sidebar, titres, emails, footer) n'est plus codé en dur. Il est stocké dans la table `settings` (clé `app_name`) et lisible via `get_app_name()`. Modifiable depuis la page Paramètres → section « Identité de l'application ».
- **Favicon en base de données** : Le favicon SVG est stocké dans la table `settings` (clé `app_favicon`) et rendu via `render_favicon()`. Si la valeur est vide, le favicon par défaut est utilisé (losange bleu avec la première lettre du nom). Modifiable depuis la page Paramètres.
- **Nouveau nom par défaut** : `CircuitDémat` (Circuit de validation + Dématérialisation). Remplace `FluxDémat` — plus de « Flux » dans le nom.
- **Zéro valeur codée en dur** : Tous les titres de pages (`<title>`), favicons (`<link rel="icon">`), noms dans les emails et le footer utilisent désormais `get_app_name()` et `render_favicon()`.
- **Section « Identité de l'application »** ajoutée dans la page Paramètres (admin_settings.php) avec deux champs : nom et favicon SVG.

## [5.7.0] — 2026-06-15

### Feature — Renommage FluxDémat + Zéro anglicisme

- **FluxDémat** : L'application s'appelle désormais **FluxDémat** (contraction de « Flux » et « Dématérialisation »). Le nom remplace « FluxDREETS » dans toute l'interface : sidebar, titres de pages, sujets d'emails, paramètres par défaut, prompt IA, page d'accueil, installateur, favicons, etc. Aucune référence à DREETS dans le nom de l'application.
- **Zéro anglicisme dans l'interface** : Remplacement systématique de tous les anglicismes visibles par l'utilisateur :
  - « Dashboard » → « Tableau de bord »
  - « Monitoring » → « Surveillance »
  - « Onboarding agent » → « Accueil agent »
  - « Outboarding agent » → « Départ agent »
  - « Email » (dans les libellés et en-têtes de tableaux) → « Courriel »
  - « Observabilité » → « Diagnostic »
- **Favicon mis à jour** : La lettre du favicon passe de « D » à « F » pour FluxDémat.

### Fix — Section propriétaires du formulaire

- **`get_form_owners()`** : La requête ne retournait pas la colonne `id`, ce qui rendait le bouton « Retirer » inopérant (lien `confirm_action.php` avec `$owner['id']` vide). La fonction retourne désormais `id, email, added_at`.

## [5.6.0] — 2026-06-15

### Feature — Nom de la solution + Raccourci Formulaires

- **FluxDREETS** : L'application s'appelle désormais **FluxDREETS** (contraction de « Flux » = circuit de validation/workflow, et DREETS). Le nom remplace « Workflow DREETS » dans toute l'interface : sidebar brand, titres de pages, sujets d'emails, paramètres par défaut, prompt IA, page d'accueil, installateur, etc.
- **Raccourci « 📝 Formulaires »** dans la sidebar (section Administration) : lien direct vers `admin_forms.php`, visible par tous les admins sur toutes les pages. Plus besoin de passer par le dashboard pour gérer les formulaires.

### Fix — ensure_text_ids() autonome (v5.5.1)

- `ensure_text_ids(PDO $pdo)` : vérifie et corrige automatiquement les tables INTEGER PK à chaque accès, indépendamment du schema_version.

## [5.5.1] — 2026-06-15

### Fix — Datatype mismatch : ensure_text_ids() autonome

- **`ensure_text_ids(PDO $pdo)`** : Nouvelle fonction autonome qui vérifie à CHAQUE accès si des tables ont encore un `id INTEGER PRIMARY KEY` et les corrige automatiquement. Contrairement à la migration v11 (qui se marquait comme faite même en cas d'échec via `INSERT OR IGNORE`), cette fonction s'exécute indépendamment du numéro de version du schéma. Si les tables sont déjà en TEXT, elle ne fait rien (vérification instantanée via `PRAGMA table_info`).
- **Appel dans `populate_samples`** : `ensure_text_ids($pdo)` est appelé explicitement avant le peuplement pour garantir que le schéma est correct.
- **Migration v11 supprimée** : Remplacée par `ensure_text_ids()`. La v11 souffrait d'un bug critique : en cas d'échec, elle se marquait comme effectuée via `INSERT OR IGNORE INTO schema_version`, empêchant toute re-exécution.
- **Regex corrigé** : L'extraction des noms de colonnes du CREATE TABLE filtre désormais sur les types SQL réels (`TEXT|INTEGER|DATETIME|BLOB|REAL`) au lieu de matcher n'importe quel mot (`FOREIGN`, `UNIQUE`, etc.).
- **Diagnostic amélioré** : Le message d'erreur du peuplement liste toutes les tables en INTEGER et suggère de recharger la page.

## [5.5.0] — 2026-06-15

### Fix — TypeError run_lazy_cron

- **`run_lazy_cron(PDO $pdo)`** : La fonction recevait son PDO via un appel récursif à `get_pdo()`, ce qui créait une situation instable lors du premier accès. Désormais, `$pdo` est passé en paramètre depuis `get_pdo()` après l'initialisation, éliminant tout risque de récursion.
- **Try/catch global** : Ajout d'un bloc `try/catch (\Throwable)` englobant tout le `foreach` dans `run_lazy_cron()`. Toute erreur fatale dans le cron est désormais loguée via `error_log()` et ne casse plus la page utilisateur.
- **Vérification `$last_run === ''`** : Ajout du cas chaîne vide dans la vérification d'absence de dernière exécution.

### Fix — Datatype mismatch HY000 20 lors du peuplement

- **Migration v11** : Vérification et correction automatique des colonnes `id INTEGER PRIMARY KEY` restantes dans toutes les tables (forms, steps, step_recipients, form_fields, admins, admin_requests, audit_log, submissions, tokens, alert_rules, alert_log, attachments, delegations, form_owners, rate_limits). Si une table a encore un PK INTEGER, elle est automatiquement recréée avec `id TEXT PRIMARY KEY` en copiant les données existantes. Cette migration corrige les bases où la migration v9 a échoué silencieusement ou n'a pas été appliquée.
- **Diagnostic dans populate_samples** : En cas d'erreur PDOException lors du peuplement, le système vérifie automatiquement les colonnes INTEGER PK restantes et affiche un message de diagnostic indiquant quelles tables sont encore en INTEGER.
- **Catch `\Throwable`** dans populate_samples pour attraper aussi les TypeError et autres erreurs non-PDO.

## [5.4.1] — 2026-06-15

### Fix — TypeError run_lazy_cron (premier fix)

- Correction initiale du TypeError dans `run_lazy_cron()` — passage de PDO en paramètre, try/catch global.

## [5.4.0] — 2026-06-15

### Feature — Gestion avancée des formulaires

- **Export JSON** : Bouton « 📤 Exporter JSON » dans la barre d'actions d'un formulaire. Génère un fichier `.json` contenant la définition complète du formulaire (métadonnées, champs, étapes, destinataires) avec `schema_version: "1.0"`. Ce format est conçu pour être lisible par une IA qui peut analyser un document administratif et générer un JSON compatible pour import.

- **Import JSON** : Bouton « 📥 Importer JSON » dans la barre du sélecteur de formulaire. Panneau dépliable permettant de coller un JSON (exporté ou généré par IA). Le formulaire est créé automatiquement avec tous ses champs, étapes et destinataires. Validation du schéma JSON complète avant import.

- **Validation JSON (dry-run)** : Bouton « 🔍 Valider le JSON » dans le panneau d'import. Teste le JSON sans l'importer, avec retour détaillé :
  - **Erreurs bloquantes** : propriétés manquantes, types invalides (field_type inexistant, email mal formaté), doublons de field_name, select sans options, etc.
  - **Avertissements** : suggestions non bloquantes (schema_version manquante, field_name pas en snake_case, options sur un non-select, card_group vide, etc.).
  - **Bouton « 📋 Copier le message »** : génère un texte formaté prêt à copier-coller à l'IA pour qu'elle corrige son JSON et réessaie. Boucle de feedback LLM → validation → LLM.
  - L'import est bloqué si des erreurs bloquantes sont détectées. Les avertissements sont affichés mais n'empêchent pas l'import.
  - Le JSON est préservé dans le textarea après validation pour ne pas perdre le contenu.

- **Prompt IA** : Bouton « 🤖 Prompt IA » dans la barre du sélecteur de formulaire. Panneau dépliable indépendant avec un prompt complet prêt à copier-coller. Le prompt demande à l'IA de générer à la fois les champs du formulaire ET le circuit de validation (workflow/steps) dans le même JSON. Inclut un exemple concret (Onboarding agent avec 4 étapes de validation). L'utilisateur colle son document administratif à la fin du prompt, l'IA génère le JSON conforme au schéma, puis il suffit de le coller dans le champ d'import. Bouton « 📋 Copier » en un clic.

- **Formulaires exemples** : Bouton « 📦 Formulaires exemples » qui peuple la base avec 8 formulaires pré-configurés complets (Onboarding, Outboarding, Accès SI, Formation, Mutation, Matériel, Remboursement frais, Sortie hors plages) incluant champs, sections et circuits de validation. Les formulaires déjà existants (même slug) sont ignorés silencieusement.

- **Dupliquer** : Le bouton existant « Dupliquer » copie désormais le formulaire complet (champs + étapes + destinataires).

### Fix — CSS

- **Classe `.hidden`** : Ajout de la classe utilitaire `.hidden { display: none !important; }` dans `style.php` pour le panneau d'import.

### Maintenance — Screenshots docs.php

- **Mise à jour des 17 captures d'écran** : Toutes les screenshots de `docs.php` ont été refaites avec la nouvelle UI « Institutionnel v3 » (sidebar layout, palette bleu républicain, hero gradient). Les anciennes captures montraient l'interface pré-v5 qui n'était plus représentative.

## [5.2.0] — 2026-06-15

### Fix — TypeError date argument dans `helpers.php:188`

- **Correction du TypeError** : `strtotime($last_run)` peut retourner `false` lorsque la valeur `last_run` en base est une chaîne invalide ou vide. En PHP 8.0+, l'opération arithmétique `$now - $last_ts` lève alors un `TypeError` (int - bool). Ajout d'un test `$last_ts === false` qui déclenche la réexécution de la tâche au lieu de tenter le calcul.

- **Cast défensif sur `time()`** : `$now = (int) time()` pour garantir un type int strict passé à `date()`.

### Fix — Hero illisible (blanc sur fond transparent)

- **Variable `--gradient-mesh-hero` manquante** : La page d'accueil utilise `var(--gradient-mesh-hero)` pour le fond du hero, mais cette variable CSS n'était jamais définie dans `style.php`. Le hero héritait d'un fond transparent, rendant le texte blanc totalement illisible. Ajout de la définition dans `:root` avec un gradient bleu républicain profond conforme au design system.

### Fix — Images docs.php ne chargent pas

- **Chemin relatif cassé** : Les 17 captures d'écran dans `docs.php` utilisaient des chemins relatifs (`docs/screenshots/...`) qui ne résolvaient pas correctement sur le serveur IIS (sous `/workflow/`). Les chemins absolus via `BASE_URL` ne fonctionnaient pas non plus car IIS ne sert pas les fichiers statiques dans les sous-dossiers. Création de `screenshot.php` comme proxy PHP qui sert les images avec les bons headers MIME et un cache de 1 semaine. Les `src` utilisent désormais `screenshot.php?f=XX.png`.

## [5.1.0] — 2026-06-15

### Design System 2026 v2 — "Aurora Institutionnel"

- **Refonte du design system** : Passage de "Glassmorphism Institutionnel" à "Aurora Institutionnel" — une identité visuelle plus moderne et distinctive qui mêle l'esthétique républicaine française aux tendances 2026.

- **Palette bleu républicain** : Remplacement de la palette indigo→violet (#4F46E5 → #7C3AED) par un bleu républicain profond → bleu électrique (#1E40AF → #3B82F6), plus institutionnel et mieux adapté au contexte DREETS.

- **Dark mode natif** : Support complet du mode sombre via `prefers-color-scheme: dark`. Toutes les couleurs, ombres, bordures et surfaces s'adaptent automatiquement. Les surfaces sombres utilisent des gris bleutés (#0F172A, #1E293B, #334155) pour une cohérence visuelle.

- **Mesh gradients (aurora)** : Introduction de gradients multi-radiaux inspirés des aurores boréales pour le body (background-attachment: fixed) et le hero (gradient-mesh-hero). Effets de profondeur et d'immersion sans JavaScript.

- **Micro-interactions améliorées** : Boutons avec translateY(-1px) au survol et scale(.97) au clic, cards avec ombre glow au survol, nav-tiles avec icône dans un carré arrondi, animations d'entrée plus fluides (fadeSlideIn .5s avec stagger .06s).

- **Nouvelles animations CSS** : brandPulse (point d'accent du logo), badgePulse (badge de validation en attente), stepPulse (étape active du workflow), shimmer (pour les futurs skeletons). Animation fadeScaleIn ajoutée.

- **Hero aurora** : Le hero de la page d'accueil utilise désormais un gradient mesh multi-radial avec pseudo-éléments décoratifs (cercles de lumière), titre en font-weight:900 et font-size:text-4xl, description en text-lg.

- **Nav tiles améliorées** : Icônes dans des carrés arrondis avec fond primary-50, hover avec changement de fond en primary-100, espacement augmenté (gap 1rem, minmax 220px).

- **Favicon mis à jour** : Gradient bleu républicain (#1E40AF → #3B82F6) sur les 20 pages PHP.

- **color-mix()** : Utilisation de `color-mix(in srgb, ...)` pour les états hover des champs de formulaire (fusion dynamique primary 40% + border).

- **font-variant-numeric: tabular-nums** : Chiffres statistiques alignés en tabulaire pour un rendu plus professionnel.

- **backdrop-filter: saturate(1.4)** : Saturation renforcée sur la navigation et les messages pour un effet glass plus vibrants.

- **Error codes en gradient text** : Les codes d'erreur (403, 404, etc.) utilisent désormais des gradients en background-clip: text pour un effet visuel moderne.

- **Scrollbar personnalisée** : Scrollbar Webkit subtile avec thumb en couleur de bordure et track transparent.

- **prefers-reduced-motion** : Support complet — toutes les animations et transitions sont désactivées si l'utilisateur préfère réduire les mouvements.

- **::selection** : Couleur de sélection personnalisée (primary-100 sur primary-darker).

- **Footer épuré** : Séparateurs avec opacité .4, mention "DREETS BFC" ajoutée.

- **Variable --r-2xl** : Nouveau rayon 28px pour les cards hero et error.

- **Variable --shadow-2xl** : Ombre très profonde pour les éléments flottants.

- **Variable --gradient-aurora** : Gradient multi-couleur (5 stops) pour les futurs éléments décoratifs.

- **Variable --gradient-mesh-1** : Gradient de fond pour le body avec 3 couches radiales.

- **Token-wait animé** : Les tokens en attente dans le dashboard ont désormais une animation softPulse.

- **Detail-content border-radius** : Bords arrondis uniquement en bas pour le contenu des details (cohérence visuelle avec le summary).

- **21 fichiers modifiés** : style.php (réécriture complète), helpers.php (nav, footer), index.php, dashboard.php, config.php, et 20 fichiers PHP (favicon).

## [5.0.0] — 2026-06-15

### Design System 2026 — Refonte visuelle complète

- **Nouveau design system "Glassmorphism Institutionnel"** : Refonte totale de l'identité visuelle de l'application. Palette indigo→violet graduel (#4F46E5 → #7C3AED), glassmorphism sur la barre de navigation (backdrop-filter), ombres multi-couches douces, boutons pill avec gradient, cartes bento avec barres d'accent colorées, typographie système moderne, transitions CSS fluides, animations d'entrée (fadeSlideIn).

- **CSS Custom Properties (Design Tokens)** : Introduction de 60+ variables CSS (`--c-primary`, `--shadow-md`, `--r-lg`, `--text-base`, `--ease-out`, etc.) pour un theming cohérent et maintenable. Tous les fichiers PHP utilisent désormais ces tokens au lieu de valeurs codées en dur.

- **Navigation glassmorphism** : Barre de navigation sticky avec gradient indigo→violet, backdrop-filter blur, liens en pill avec hover semi-transparent, badge amber animé pour les validations en attente, brand "DREETS" avec point d'accent coloré.

- **Cartes bento** : Toutes les cartes statistiques, formulaires et tuiles de navigation utilisent désormais des bordures douces, ombres légères, barres d'accent colorées (::before), et micro-animations au survol (translateY, box-shadow).

- **Boutons pill** : Tous les boutons sont désormais en border-radius full avec gradient, ombre colorée au survol, et animation de scale au clic.

- **Badges pill** : Les badges de statut (Validé, En cours, Refusé) sont désormais en pill avec les nouvelles couleurs sémantiques (success-50/dark, warning-50/dark, danger-50/dark).

- **Tables modernisées** : En-tête avec background primary-50, texte uppercase, espacement augmenté, bordures légères, hover sur les lignes.

- **Favicon gradient** : Remplacement du favicon plat bleu #003189 par un favicon indigo→violet avec dégradé linéaire et coins arrondis (rx=20).

- **Footer épuré** : Footer minimaliste avec les nouveaux tokens, séparateur point médian.

- **Animations CSS** : Animation fadeSlideIn sur les cartes au chargement de page, softPulse sur les badges warning (validation en attente).

- **22 fichiers mis à jour** : style.php (réécriture complète), helpers.php (nav, footer), index.php, dashboard.php, form.php, my_submissions.php, my_validations.php, admin_settings.php, admin_forms.php, admin_alerts.php, admin_access.php, monitoring.php, stats.php, health.php, backup.php, rgpd.php, docs.php, changelog.php, validate.php, confirm_action.php, submission_view.php, form_preview.php, form_tracking.php.

## [4.6.0] — 2026-06-15

### Accessibilité RGAA — Critique

- **`aria-hidden="true"` sur tous les emojis décoratifs** : Les 89 emojis décoratifs (📋✅❌📧🔄🗑⏳📎📊⚙🔔🖥🔐💾🏥📝🚀🧭✓🎉📅🚨⚠️📥👁🤖📬📈🧹📤📜📁🔍🔧) présents dans les 17 pages de l'application sont désormais enveloppés dans `<span aria-hidden="true">` pour ne pas perturber les lecteurs d'écran. Les seuls emojis fonctionnels (indicateurs de statut dans health.php) conservent leur accessibilité via `aria-label`.

- **Contraste couleurs corrigé (`color:#888` → `color:#595959`)** : Les 14 instances restantes de `color:#888` (contraste 3.5:1 sur fond blanc, non conforme WCAG AA) ont été remplacées par `color:#595959` (contraste 7.0:1, conforme AAA). Fichers corrigés : form.php, docs.php, install.php, admin_forms.php, dashboard.php, submission_view.php, my_submissions.php, my_validations.php, monitoring.php, admin_alerts.php, form_tracking.php, form_preview.php, rgpd.php, stats.php, health.php.

### Navigation — Haut

- **Liens de navigation actifs corrigés** : Les 5 pages qui utilisaient `render_nav('')` (aucun lien actif dans le bandeau) utilisent désormais la clé de navigation correcte : validate.php → `mes_validations`, confirm_action.php → `dashboard`, submission_view.php → `mes_demandes`, admin_access.php → `settings`, form_tracking.php → `dashboard`. L'utilisateur voit toujours où il se trouve dans la navigation.

### Interface — Moyen

- **Dashboard : lignes de détail en `<details>/<summary>`** : Les lignes de détail du tableau de bord (historique des validations, données du formulaire, actions admin) sont désormais masquées par défaut et révélées au clic sur un résumé. Améliore la lisibilité et réduit la surcharge visuelle. Zéro JavaScript — utilise les éléments HTML5 natifs.

- **Responsive mobile amélioré** : Ajout de 25 règles CSS responsive pour les écrans ≤768px et ≤600px : grilles adaptatives, formulaires pleine largeur, timeline verticale, inputs sans zoom iOS (font-size 16px), boutons empilés, pagination compacte, fil d'Ariane réduit, barre d'outils empilée.

- **docs.php version fallback corrigé** : Le fallback de version dans docs.php passe de `4.4.0` à `4.6.0` (cohérent avec APP_VERSION dans config.php).

## [4.5.0] — 2026-06-15

### Navigation uniformisée — Critique

- **Navigation centralisée** : Les 9 pages qui utilisaient un bandeau manuel (`<div class="bandeau">`) avec des liens incohérents utilisent désormais toutes `render_nav()`. La navigation est identique sur toutes les pages : Accueil, Mes demandes, Mes validations, Documentation (+ liens admin pour les administrateurs). Les sous-pages admin (Monitoring, Alertes, Statistiques, etc.) sont accessibles via des liens contextuels dans la barre de navigation.

- **Fil d'Ariane sur toutes les pages** : Chaque page affiche désormais un fil d'Ariane (`render_breadcrumb()`) pour que l'utilisateur sache toujours où il se trouve et puisse revenir en arrière.

- **Liens « Accueil » et « Mes validations »** sur toutes les pages d'erreur (validate.php : lien invalide, déjà validé, workflow terminé, lien expiré). Plus aucune impasse de navigation.

### Accessibilité RGAA — Critique

- **Contraste couleurs corrigé** : Les liens du bandeau passent de `#b3c8f0` (contraste 3.5:1, non conforme WCAG AA) à `#c8dbf5` (contraste 4.7:1, conforme). Les textes d'aide passent de `#888` (3.5:1) à `#595959` (5.3:1). Le footer passe de `#888` à `#595959`.

- **Emojis décoratifs avec `aria-hidden="true"`** : Tous les emojis décoratifs (🏠📋✅📖📊⚙🔔🖥📈🔐💾🏥✅❌⏳📎📧🔄🗑⚠) sont désormais enveloppés dans `<span aria-hidden="true">` pour ne pas perturber les lecteurs d'écran.

- **Landmarks HTML5** : Les pages `admin_access.php`, `admin_alerts.php`, `backup.php`, `form_preview.php`, `form_tracking.php`, `submission_view.php` utilisent désormais `<main>` au lieu de `<div class="container">`.

- **Skip-link** : Toutes les pages ont un lien d'évitement « Aller au contenu principal ».

### Confirmation de refus — Haut

- **Commentaire obligatoire pour le refus** : Le bouton « Refuser » sur la page de validation requiert désormais un motif dans le champ commentaire. Sans commentaire, le refus est bloqué avec un message d'erreur explicite. Le label du champ commentaire indique « obligatoire en cas de refus ».

### Post-soumission — Haut

- **Liens après soumission** : Après la soumission d'un formulaire, l'utilisateur voit désormais trois boutons : « Voir ma demande » (lien direct vers submission_view.php), « Mes demandes » et « Accueil ». Plus de page orpheline après soumission.

### Administration

- **Email admin en base de données** : L'email de l'administrateur principal (anciennement `ADMIN_EMAIL` dans config.php) est désormais stocké dans la table `settings` et modifiable depuis l'interface d'administration (Paramètres → Administration). La constante `ADMIN_EMAIL` reste en fallback. Migration v11.

- **Clôturés → Validés + Refusés** : Le tableau de bord admin sépare désormais les soumissions clôturées en « Validés » et « Refusés » (statistiques + filtres). Le filtre « Clôturés » reste disponible pour compatibilité.

- **Section « Administration »** dans admin_settings.php : Nouveau champ pour modifier l'email de l'administrateur principal.

### Technique

- **`render_nav()` améliorée** : Nouveau paramètre `$extra_admin_links` pour ajouter des liens contextuels admin (Monitoring, Alertes, etc.) selon la page courante. Utilisation de classes CSS au lieu de styles inline pour la navigation.

- **`get_admin_email()`** : Nouvelle fonction qui récupère l'email admin depuis la base de données avec fallback sur la constante `ADMIN_EMAIL`. `is_super_admin()` utilise cette fonction.

- **`run_lazy_cron()` corrigé** : Ajout d'un guard `static $running` pour empêcher la récursion infinie (get_pdo() → run_lazy_cron() → get_pdo()).

- **CSS centralisé** : Ajout de classes CSS pour la navigation (`nav-brand`, `nav-main`, `nav-admin`, `nav-user`, `nav-badge`, `nav-active`), le fil d'Ariane (`breadcrumb`, `separator`, `current`), les détails/summary (`details`, `summary`). Styles responsive améliorés pour le bandeau et les tableaux.

- **`<details>/<summary>` HTML5** : Nouveaux styles CSS pour les éléments `<details>/<summary>` (accordéon sans JavaScript), utilisables dans toutes les pages.

## [4.4.0] — 2026-06-15

### Sécurité email — Critique

- **Mode Dry-Run (mail_dry_run)** : Nouveau paramètre activable dans l'administration qui intercepte **tous** les envois d'emails. En mode dry-run, `send_mail()` journalise chaque tentative d'envoi dans l'audit log sans contacter le serveur SMTP. Le workflow continue normalement (tokens créés, étapes avancées). **Activé par défaut** lors de la migration — un administrateur doit explicitement le désactiver pour autoriser les envois réels.

- **Vérification LDAP / Active Directory** : Nouveau mode de vérification des adresses destinataires avant envoi. Si activé, le système se connecte à l'Active Directory en lecture seule (bind anonyme ou compte de service) et recherche l'adresse email dans l'annuaire. Si l'adresse est introuvable, l'envoi est bloqué et journalisé. Configuration complète dans la section admin : hôte LDAP, port, base DN, bind DN/mot de passe, filtre de recherche.

- **Vérification SMTP (probe RCPT TO)** : Mode alternatif de vérification des adresses. Le système ouvre une connexion SMTP, envoie `HELO`, `MAIL FROM`, `RCPT TO` et vérifie si le serveur accepte le destinataire, puis se déconnecte proprement (`QUIT` avant `DATA`). Supporte STARTTLS. Attention : certains serveurs Exchange acceptent toutes les adresses en RCPT TO (mode catch-all), ce qui rend cette vérification moins fiable que LDAP.

- **Blocage des emails non vérifiés** : Si la vérification est activée (LDAP ou SMTP) et qu'une adresse échoue, `send_mail()` retourne `false` et journalise l'événement dans l'audit log (`mail_blocked`). Cela empêche l'envoi à des adresses placeholder ou inexistantes.

- **Audit log des emails** : Chaque appel à `send_mail()` est désormais journalisé dans l'audit log avec le type `mail_sent`, `mail_dry_run`, `mail_blocked` ou `mail_error`, incluant le destinataire, le sujet et le détail.

- **Ordre de priorité dans send_mail()** : TEST_MODE → Dry-Run → Vérification email → Blocage CLI → Envoi réel PHPMailer. Chaque couche de protection est évaluée dans l'ordre.

### Administration

- **Section « Sécurité email »** dans admin_settings.php : Nouvelle interface de configuration avec :
  - Toggle Dry-Run avec explication claire
  - Sélecteur du mode de vérification (aucun / LDAP / SMTP)
  - Configuration LDAP complète (hôte, port, base DN, bind DN, mot de passe, filtre)
  - Information sur les limitations du mode SMTP
  - Détection automatique de l'extension PHP LDAP
  - Bouton de test de vérification email avec résultat détaillé

- **Résumé de sécurité email** : Tableau de bord affichant le statut de chaque couche de protection (Dry-Run, vérification, PHPMailer stub/real, blocage CLI) et un score de sécurité sur 4.

- **Badge Dry-Run** : Avertissement visuel en haut de la page quand le mode Dry-Run est actif.

- **Slug auto-généré** : Le champ « Slug » n'est plus visible dans l'interface d'administration. L'identifiant technique est désormais généré automatiquement à partir du libellé du formulaire (ex: « Demande de congé » → `demande_de_conge`). Si un slug existe déjà, un suffixe numérique est ajouté automatiquement. En édition, le slug actuel est affiché en hint en lecture seule. La documentation a été mise à jour en conséquence.

- **Version footer/docs** : Correction du fallback de version dans `docs.php` (était `4.3.1`, désormais `4.4.0`).

### Base de données

- **Migration v10** : Ajout des paramètres `mail_dry_run` (défaut : `1`), `email_verify_mode` (défaut : `none`), `ldap_host`, `ldap_port`, `ldap_base_dn`, `ldap_bind_dn`, `ldap_bind_pass`, `ldap_filter` dans la table `settings`.

### Version

- Passage de `4.3.1` à `4.4.0` (version mineure : nouvelle fonctionnalité de sécurité email).

## [4.3.1] — 2026-06-15

### Sécurité — Critique

- **Protection contre l'envoi d'emails lors des tests** : Les scripts de test (`test_all.php`, `test_e2e.php`) forcent désormais le mode `TEST_MODE` pour intercepter tous les appels à `send_mail()`. Aucun email réel ne peut plus être envoyé pendant l'exécution des tests. Toutes les adresses de test utilisent le domaine `@e2e.test` (réservé RFC 2606, impossible qu'il soit réel).

- **Garde-fou CLI pour `send_mail()`** : Ajout d'un blocage automatique de l'envoi d'emails en contexte CLI sauf si la constante `CLI_MAIL_ALLOWED` est définie. Les scripts légitimes `remind.php` et `alert_check.php` déclarent cette constante. Cela empêche tout envoi accidentel d'emails depuis un script de test ou une exécution CLI inattendue.

### Tests

- **80 tests E2E intensifs** (`test_e2e.php`) : Nouveau script de test end-to-end couvrant 15 catégories — soumission de formulaires, avancement du workflow complet, validation étape par étape, refus, annulation, délégation, cas limites de sécurité (tokens invalides/expirés, injection SQL, XSS, CSRF), uploads de fichiers (BLOB), formulaire outboarding, fonctions utilitaires, intégrité des données, conformité RGPD, relance/expiration des tokens, types de champs. Résultat : **80/80 tests passent**.

- **51 tests unitaires** (`test_all.php`) : Mise à jour pour la compatibilité avec le mode TEST (authentification via `X-Test-User`, CSRF bypass testé via `hash_equals()`). Résultat : **51/51 tests passent**.

- **Total : 131/131 tests passent**, zéro email réel envoyé.

### Documentation

- **Schéma de base de données corrigé** : La section technique de `docs.php` affichait `INTEGER PK` pour toutes les tables — désormais corrigé en `TEXT PK (UUID v4)` et `TEXT FK (UUID v4)` pour refléter la migration UUID. Ajout des tables `form_owners`, `delegations` et de la colonne `rgpd_consent`.

- **Bouton retour en haut** : Ajout d'un bouton flottant « ↑ » en bas à droite (CSS pur, zéro JavaScript) pour remonter en haut de la page de documentation (1700+ lignes).

- **Indicateur de version** : Affichage du badge `v4.3.0` en haut de la page de documentation.

- **Captures d'écran manquantes** : Intégration de `13_docs.png` (page d'aide) et `14_changelog.png` (journal des modifications) qui existaient dans le dossier mais n'étaient pas affichées.

- **Version PHP corrigée** : « PHP 7.4+ » → « PHP 8+ » dans la section architecture technique.

- **Table des types de champs** : Ajout d'un tableau de référence des 6 types de champs disponibles (text, date, select, checkbox, textarea, file) dans le guide administrateur.

- **FAQ déploiement IT** : Nouvelle entrée FAQ pour l'équipe technique avec les prérequis système, les étapes d'installation et les tâches planifiées.

- **Avertissement sur les emails de seeding** : Commentaire dans `helpers.php` indiquant que les adresses email par défaut dans le seeding sont des valeurs à remplacer par l'administrateur.

## [4.3.0] — 2026-06-14

### Sécurité — Majeure

- **Zéro ID entier dans toute l'application** : Toutes les clés primaires et étrangères de la base de données sont désormais des UUID (TEXT) au lieu d'entiers auto-incrémentés. Plus aucune table n'utilise `INTEGER PRIMARY KEY AUTOINCREMENT`. Les 15 tables d'entités utilisent `id TEXT PRIMARY KEY NOT NULL` avec des UUID v4 générés par `generate_uuid()`. Les colonnes `uuid` de la table `forms` ont été supprimées (l'`id` EST l'UUID). Cela rend impossible la devinette ou l'énumération d'identifiants dans les URLs.

- **URLs entièrement en UUID** : Tous les paramètres d'URL (`?id=`, `?form_id=`, `?submission_id=`, `?token_id=`, `?step_id=`, `?rule_id=`, etc.) utilisent désormais des UUID non devinables au lieu d'entiers séquentiels. Plus d'attaque IDOR possible par énumération.

### Architecture

- **Suppression complète de `lastInsertId()`** : Les 29 appels à `$pdo->lastInsertId()` ont été remplacés par des UUIDs pré-générés avant chaque INSERT. Chaque INSERT inclut désormais explicitement la colonne `id` avec la valeur UUID.

- **Migration v9** : Migration complète des bases existantes — recréation de toutes les tables avec clés TEXT, mapping des anciens IDs entiers vers les nouveaux UUIDs, préservation de toutes les données et relations.

- **Signatures de fonctions** : Tous les paramètres d'ID sont passés de `int` à `string` (`int $form_id` → `string $form_id`). Suppression de tous les casts `(int)` sur les variables d'ID dans tout le codebase.

### Pages d'erreur visuelles

- **`render_error_page()`** : Nouvelle fonction réutilisable pour afficher des pages d'erreur HTML complètes et soignées (403, 404, 400, 401, 500). Chaque code a son icône SVG, son code HTTP en gros, un message descriptif, un encart « Que faire ? » et un bouton de retour. Remplace tous les appels `die()` avec texte brut (20+ remplacements à travers 13 fichiers). La page 401 (authentification) a été mise à jour pour matcher le même design.

### Base de données

- 15 tables migrées de `INTEGER PRIMARY KEY AUTOINCREMENT` vers `id TEXT PRIMARY KEY NOT NULL`
- Toutes les colonnes FK migrées de `INTEGER` vers `TEXT`
- Colonne `uuid` supprimée de la table `forms` (l'`id` est l'UUID)
- Migration v9 : reconstruction complète avec mapping old_int → new_uuid

### Corrections

- **Colonne `hint` manquante dans `form_fields`** : Le `CREATE TABLE` initial de `form_fields` ne contenait pas la colonne `hint`, qui était ajoutée via `ALTER TABLE` en legacy. Cela causait un crash lors de l'initialisation d'une base neuve. La colonne est désormais dans la définition de la table.
- **`generate_field_name()` crashait sans `mbstring`/`intl`** : La fonction appelait `mb_strtolower()` et `transliterator_transliterate()` sans vérifier la disponibilité des extensions. Ajout de fallbacks via `strtolower()` et remplacement manuel des caractères accentués.
- **`test_all.php` inutilisable avec les UUIDs** : Les requêtes SQL du test utilisaient les UUIDs sans quotes (`WHERE form_id=$onboarding_id`), `generate_uuid()` était appelé dans le SQL comme fonction SQLite, et les tests de pages utilisaient des IDs entiers (`'1'`). Réécriture complète du fichier de test avec prepared statements et UUIDs corrects.
- **Onboarding et outboarding sans recipients** : Les formulaires onboarding et outboarding étaient seedés avec des étapes mais sans destinataires (`step_recipients`) ni propriétaires (`form_owners`). Le workflow ne pouvait donc pas démarrer pour ces deux formulaires. Ajout des recipients par défaut (responsable.direct, informatique, rh, logistique) et des owners.
- **4 screenshots manquants dans la documentation** : Les captures d'écran disponibles (`04_form_outboarding`, `09_admin_access`, `16_submission_view`, `17_form_preview`) n'étaient pas intégrées dans `docs.php`. Ajout dans les sections Guide de l'agent, Guide du validateur et Guide de l'administrateur.

## [4.2.0] — 2026-06-14

### Sécurité

- **UUID pour les formulaires** : Les formulaires sont désormais identifiés par un UUID v4 (RFC 4122) dans les URLs au lieu d'un identifiant entier prédictible. Les URLs de suivi propriétaire passent de `form_tracking.php?form_id=3` à `form_tracking.php?f=a1b2c3d4-e5f6-7890-abcd-ef1234567890`. Colonnes `uuid` ajoutée à la table `forms` (migration v7). Fonction `generate_uuid()` et `get_form_by_uuid()` ajoutées.

- **Validation HTML5 native** : Les champs de formulaire utilisent désormais les types HTML5 appropriés (email, tel, number, time, url) détectés automatiquement à partir du nom du champ. Ajout de `pattern`, `maxlength`, `min/max`, `step` pour une validation côté navigateur sans JavaScript. Retrait de l'attribut `novalidate` qui désactivait la validation HTML5.

### Architecture

- **Lazy cron (pas de Task Scheduler)** : Les tâches planifiées (relances et alertes) sont désormais exécutées par le premier utilisateur qui se connecte, au lieu d'un cron externe. `run_lazy_cron()` est appelé automatiquement au premier accès PDO. La table `lazy_cron` (migration v8) trace la dernière exécution de chaque tâche. Le remind s'exécute toutes les heures, l'alert_check une fois par jour.

- **Cloisonnement propriétaire** : Les tableaux de suivi (`form_tracking.php`) sont strictement isolés — seuls les owners du formulaire et les administrateurs peuvent y accéder. Les autres utilisateurs n'ont aucun moyen de voir les données ou les stats d'un formulaire dont ils ne sont pas propriétaires.

### Interface

- **Pages d'erreur visuelles** : Toutes les pages d'erreur (403 Accès refusé, 404 Introuvable, 400 Requête invalide, 401 Authentification requise) sont désormais des pages HTML complètes et soignées au lieu de texte brut. Nouvelle fonction `render_error_page()` dans helpers.php, avec icône SVG, code HTTP en gros, message descriptif, encart « Que faire ? » et bouton de retour. Style cohérent avec le design DREETS (bandeau, Marianne, palette #003189/#c0392b/#b45309). CSS dédié dans `style.php`. Les 13 appels `die()` avec messages CSRF affichent également une page 403 soignée au lieu d'un texte brut.

### Base de données

- Colonne `uuid TEXT UNIQUE` sur `forms` : identifiant non devinable pour les URLs publiques (migration v7).
- Table `lazy_cron` : suivi de la dernière exécution des tâches planifiées (migration v8).

## [4.1.0] — 2026-06-14

### Fonctionnalités majeures

- **Propriétaires de formulaire** : Nouveau concept de propriétaires (owners) par formulaire. Les owners peuvent accéder à un tableau de suivi dédié sans être administrateurs. Chaque formulaire peut avoir un ou plusieurs propriétaires, gérés depuis le form builder admin. Nouvelle table `form_owners` (migration version 6).

- **Tableau de suivi propriétaire** : Nouvelle page `form_tracking.php` réservée aux propriétaires d'un formulaire. Affiche toutes les soumissions du formulaire avec colonnes clés, barre d'avancement, filtres par statut, pagination, export CSV. Accessible aussi aux administrateurs.

- **Formulaires métier recalibrés** : Remplacement des formulaires "Demande de congé" et "Signalement" (déplacés vers d'autres applications) par les trois formulaires métier réels de la DREETS :
  - **Demande de sortie hors plages fixes** : autorisation d'arrivée tardive, départ anticipé, pause prolongée (circuit Chef de service → DRH).
  - **Remboursement d'avance de frais** : déplacement, hébergement, repas, fournitures (circuit Chef de service → Comptabilité → Agent financier).
  - **Demande de matériel suite prescription médicale** : aménagement de poste, équipement ergonomique (circuit Médecin de prévention → Chef de service → DSI + Logistique parallèle → DRH).

### Base de données

- Table `form_owners` : relation formulaire ↔ propriétaires avec email et date d'ajout.
- Migration version 6 : création automatique de la table `form_owners`.
- Nouveaux formulaires seedés : `sortie_hors_plages`, `remboursement_avance_frais`, `materiel_prescription` (avec owners pré-configurés).

### Fonctions

- `is_form_owner(string $form_id, ?string $email)` : vérifie si un utilisateur est propriétaire d'un formulaire.
- `get_form_owners(string $form_id)` : retourne la liste des propriétaires d'un formulaire.
- `get_owned_forms(?string $email)` : retourne les formulaires dont un utilisateur est propriétaire.

### Pages

- `form_tracking.php` : tableau de suivi propriétaire avec filtres, pagination, export CSV.
- `admin_forms.php` : nouvelle section "Propriétaires du formulaire" avec ajout/retrait d'owners.
- `confirm_action.php` : support de l'action `remove_owner`.
- `index.php` : liens dynamiques vers les tableaux de suivi des formulaires possédés.

## [4.0.0] — 2026-06-14

### Fonctionnalités majeures

- **Pièces jointes en BLOB SQLite** : Les fichiers uploadés sont désormais stockés directement dans la base de données SQLite sous forme de BLOB, éliminant tout besoin de droits filesystem et garantissant le caractère mono-fichier de l'application. La compatibilité descendante avec les anciens fichiers sur disque est maintenue dans `download.php`.

- **Conformité RGPD complète** : Nouvelle page `rgpd.php` dédiée à la conformité RGPD, incluant : mentions légales configurables, durée de conservation paramétrable, export des données d'un agent (droit d'accès art. 15), suppression/anonymisation des données (droit à l'effacement art. 17), purge automatique des données anciennes, statistiques de volume de données. Consentement RGPD obligatoire à la soumission des formulaires.

- **Statistiques par période** : Nouvelle page `stats.php` avec tableaux de bord visuels : répartition des statuts (donut chart CSS), évolution par semaine/mois/année (barres empilées CSS), performance par formulaire, performance par validateur, volume de données. Aucun JavaScript requis — tous les graphiques sont en CSS pur.

- **Health check** : Nouvelle page `health.php` accessible sans authentification, vérifiant : connectivité SQLite, version PHP, répertoire accessible en écriture, schéma de base initialisé, configuration SMTP. Retourne HTTP 200/503 et JSON pour les outils de monitoring (`?format=json`).

- **Webhooks pour intégration SI** : Configuration d'URL webhook dans les paramètres admin. Notifications automatiques en POST JSON pour les événements `workflow_complete`, `submission_cancelled`, `token_validated`. Format structuré avec événement, timestamp et données. Bouton de test disponible.

- **Historique des relances** : Section dédiée dans le détail de soumission affichant l'historique complet des relances avec dates, validateurs concernés et compteur. Bouton "Rappeler tous les validateurs en attente" pour les administrateurs.

- **Versionnage du schéma de base** : Table `schema_version` pour suivre les migrations applicatives. Chaque migration est versionnée et idempotente, permettant les mises à jour automatiques et sans risque.

### Fonctionnalités

- **Recherche plein texte étendue** : La recherche du dashboard couvre désormais les noms de formulaires en plus des agents et des données JSON.

- **Formulaires pré-configurés supplémentaires** : Trois nouveaux formulaires sont pré-chargés à l'installation : "Demande de congé" (circuit Chef de service → DRH), "Demande de matériel" (Chef de service → DSI), "Signalement interne" (RH + Encadrant en parallèle → Direction).

- **Rate limiting** : Protection contre les abus avec limitation configurable par IP et par action. Appliqué notamment sur les exports et suppressions RGPD.

- **Fonctions de sécurité** : `sanitize_input()` pour le nettoyage des entrées, `validate_email()` pour la validation d'emails, `rate_limit_check()` pour la limitation de débit.

- **Documentation refondue** : Documentation complète et accessible aux non-techniciens avec guide de démarrage rapide (3 étapes), guides détaillés par rôle (agent, validateur, admin), FAQ étendue (18 questions), matrice des permissions, fiche fonctionnalités, et section RGPD.

- **Navigation unifiée** : Tous les bandeaux de navigation incluent désormais des liens vers Statistiques, RGPD et Santé système.

### Base de données

- Table `schema_version` : suivi des versions de schéma de base de données.
- Colonne `file_data BLOB` sur `attachments` : stockage des fichiers en base.
- Colonne `rgpd_consent INTEGER` sur `submissions` : traçabilité du consentement RGPD.
- Table `rate_limits` : suivi des tentatives pour le rate limiting.
- Paramètres `legal_mentions`, `retention_months`, `webhook_url`, `webhook_events` dans la table `settings`.

### Sécurité

- Stockage BLOB des fichiers : plus d'accès filesystem requis, élimination des risques de path traversal.
- Rate limiting sur les actions sensibles (export RGPD, suppression RGPD).
- Fonctions de sanitisation des entrées utilisateur.
- Consentement RGPD obligatoire avant soumission de formulaire.
- Purge automatique des données anciennes configurable.

### Technique

- `APP_VERSION` passé à `4.0.0`.
- Nouvelles fonctions dans `helpers.php` : `rgpd_export_user_data()`, `rgpd_delete_user_data()`, `rgpd_auto_purge()`, `rate_limit_check()`, `sanitize_input()`, `validate_email()`, `search_submissions()`, `get_stats_by_period()`, `get_global_stats()`, `send_webhook()`.
- Webhook calls ajoutés dans `advance_workflow()`, `validate_token()`, `cancel_submission()`.
- Nouveaux fichiers : `health.php`, `rgpd.php`, `stats.php`.

## [3.1.0] — 2026-06-14

### Fonctionnalités majeures

- **Pièces jointes** : Nouveau type de champ `file` permettant aux agents de joindre des fichiers (PDF, images, Office, ZIP) lors de la soumission d'un formulaire. Les fichiers sont stockés de manière sécurisée avec validation du type MIME et de l'extension, protection anti-traversal, et taille maximale de 10 Mo. Le téléchargement sécurisé passe par `download.php` avec contrôle d'accès (admin, propriétaire, validateur). Les pièces jointes sont visibles dans le détail de la soumission et lors de la validation.

- **Délégation de validation** : Un validateur peut désormais déléguer sa validation à un autre agent lorsqu'il est absent ou indisponible. Le mécanisme crée un nouveau token pour le délégataire, marque l'ancien token comme traité, et envoie un email de notification aux deux parties. L'historique des délégations est visible dans le détail de la soumission. La délégation est accessible depuis `my_validations.php` et `submission_view.php`.

- **Rappel manuel** : Nouveau bouton "📧 Rappeler" dans le dashboard admin et dans le détail de soumission permettant d'envoyer un email de rappel à un validateur en attente. Contrairement à la régénération de token, le rappel ne modifie pas le token existant — il envoie simplement un email avec le compteur de relances. Le nombre de relances maximum est configurable (3 par défaut).

### Fonctionnalités

- **Affichage du nombre de relances** : Le compteur de relances (`relance_count`) est désormais visible dans le diagramme de workflow de `submission_view.php`, à côté de l'email du validateur en attente.

- **Recherche dans "Mes validations"** : Nouveau champ de recherche dans la page `my_validations.php` permettant de filtrer les validations en attente par nom de formulaire ou contenu des données.

- **Recherche et filtres dans "Mes demandes"** : Nouveau champ de recherche et filtres par statut (Tous / En cours / Validées / Refusées) dans `my_validations.php` permettant aux agents de retrouver facilement leurs soumissions.

### Base de données

- Nouvelle table `attachments` : stockage des fichiers joints aux soumissions (nom original, nom stocké, type MIME, taille).
- Nouvelle table `delegations` : traçabilité des délégations de validation (depuis/vers email, motif, token associé).
- Protection du répertoire d'upload avec `.htaccess` et `index.php` vide.

### Sécurité

- Validation des fichiers uploadés : vérification du type MIME (via `finfo`), de l'extension, et de la taille.
- Extension whitelist : PDF, JPG, PNG, GIF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, ZIP.
- Protection anti-directory-traversal dans `download.php` avec `realpath()`.
- Contrôle d'accès strict sur le téléchargement : admin, propriétaire de la soumission, ou validateur uniquement.

## [3.0.0] — 2026-06-14

### Changement majeur

- **Suppression complète du JavaScript** : Toutes les fonctionnalités JavaScript ont été remplacées par des alternatives PHP/CSS pures, conformément à la philosophie du projet. Les toggles de détail utilisent désormais des liens directs, les onglets utilisent des paramètres GET, les selects de filtrage ont des boutons de soumission, et les confirmations d'actions destructrices passent par une page de confirmation serveur. L'application est désormais 100% sans JavaScript.

### Fonctionnalités majeures

- **Assistant d'installation (`install.php`)** : Nouvelle page de première installation qui guide l'administrateur à travers la configuration initiale. Vérification automatique des prérequis (PHP 8+, SQLite3, intl, PHPMailer, permissions d'écriture), formulaire de configuration SMTP et administrateur avec test d'envoi d'email, génération automatique du fichier `config.php`. Accessible uniquement si `config.php` n'existe pas encore.

- **Sauvegarde et restauration (`backup.php`)** : Nouvelle page d'administration permettant de télécharger une copie de la base SQLite, restaurer une base depuis un fichier (avec validation et sauvegarde préalable automatique), purger les anciennes données (soumissions clôturées de plus de 6/12/18/24 mois, avec prévisualisation du compte avant exécution), et consulter les statistiques de la base (taille, nombre de lignes par table, âge des données, pages SQLite). Processus de purge en deux étapes pour éviter les erreurs.

- **Page de confirmation serveur (`confirm_action.php`)** : Nouvelle page remplaçant les boîtes de dialogue JavaScript `confirm()` pour les actions destructrices (annulation de soumission, régénération de token, suppression de règle, purge de logs, suppression d'administrateur). Affiche un récapitulatif de l'action et demande confirmation via un formulaire POST avant exécution.

### Fonctionnalités

- **Duplication de formulaires** : Nouveau bouton "📋 Dupliquer" dans le form builder permettant de copier un formulaire existant avec tous ses champs, étapes et destinataires. Le formulaire dupliqué reçoit le suffixe "(copie)" dans son libellé et "-copie" dans son slug.

- **Prévention des doublons** : Lorsqu'un agent remplit un formulaire pour lequel il a déjà une soumission en cours, un avertissement s'affiche avec la date de la soumission existante et un lien pour la consulter. L'agent peut tout de même soumettre une nouvelle demande.

- **Recherche dans le dashboard** : Nouveau champ de recherche dans le dashboard de supervision permettant de filtrer les soumissions par nom ou email d'agent. Le paramètre de recherche est préservé dans les filtres et la pagination.

- **Styles d'impression** : Ajout de règles `@media print` dans le CSS partagé pour permettre l'impression propre des soumissions et du dashboard. Le bandeau, le footer, les boutons et les filtres sont masqués à l'impression. Les URLs des liens sont affichées après le texte du lien.

### Accessibilité (RGAA)

- **Lien d'évitement** : Ajout d'un lien "Aller au contenu principal" (skip link) sur toutes les pages, visible uniquement au focus clavier, permettant de sauter le bandeau de navigation.
- **Focus visible** : Ajout d'un contour bleu de 3px (`:focus-visible`) sur tous les éléments interactifs (liens, boutons, champs, selects) pour une navigation clavier conforme RGAA.
- **Classe `.sr-only`** : Ajout d'une classe utilitaire pour le contenu destiné uniquement aux lecteurs d'écran.

### Nettoyage

- Suppression de toutes les balises `<script>`, attributs `onclick`, `onsubmit`, `onchange` de l'ensemble des fichiers PHP (form.php, dashboard.php, my_validations.php, monitoring.php, submission_view.php, admin_forms.php, admin_alerts.php, admin_access.php, form_preview.php).
- Remplacement des toggles JavaScript par des liens PHP directs.
- Remplacement des onglets JavaScript par un système d'onglets basé sur les paramètres GET.
- Remplacement des selects auto-soumis par des formulaires avec bouton de soumission.
- Remplacement du toggle d'édition des règles d'alerte par un paramètre GET `?edit_rule=X`.

## [2.5.0] — 2026-06-13

### Fonctionnalités majeures

- **Refonte UX du form builder (`admin_forms.php`)** : Amélioration majeure pour rendre la création de formulaires accessible aux non-techniques. Auto-génération du nom technique (`field_name`) à partir du libellé (ex: "Date de prise de poste" → `date_de_prise_de_poste`) via `generate_field_name()`. Saisie des options de sélecteur simplifiée : une option par ligne au lieu du JSON (via `parse_options_input()`). Suggestions des groupes de cartes existants sous forme de liste déroulante. Icônes par type de champ (📝📅📋☑). Étoile rouge pour les champs obligatoires. Diagramme visuel du circuit de validation (flowchart CSS horizontal avec boîtes connectées par des flèches, destinataires affichés dans chaque étape). Bouton "👁 Prévisualiser le formulaire" pour voir le rendu final.

- **Prévisualisation du formulaire (`form_preview.php`)** : Nouvelle page permettant aux administrateurs de voir exactement comment un formulaire apparaîtra pour l'agent. Affiche le formulaire en mode lecture seule avec les champs désactivés, le circuit de validation en diagramme horizontal, et un bandeau "Mode prévisualisation" bien visible.

- **Page de détail soumission (`submission_view.php`)** : Nouvelle page dédiée offrant une vue complète et visuelle d'une soumission. Comprend : barre de progression avec pourcentage, diagramme workflow horizontal (boîtes colorées : vert=validé, orange=en cours, gris=à venir, rouge=refusé), carte deadline avec code couleur urgence, données du formulaire regroupées par section, historique des validations avec commentaires, actions admin (régénération de token, annulation). Accessible depuis le dashboard (lien "voir") et depuis "Mes demandes".

- **Refonte de "Mes demandes" (`my_submissions.php`)** : Amélioration visuelle majeure. Barres de progression par soumission (pourcentage + ratio d'étapes), timeline compacte avec code couleur, badges deadline (🚨 J+, ⚠️ J-2, 📅 J-5), lien "👁 Voir le détail" vers la page de détail, cartes cliquables, mise en page moderne.

- **Page d'accueil par rôle (`index.php`)** : Refonte complète de la page d'accueil pour s'adapter au rôle de l'utilisateur. Pour les agents : statistiques personnelles, formulaires disponibles sous forme de cartes cliquables, accès rapide (Mes demandes, Mes validations, Documentation). Pour les admins : statistiques globales, tokens bloqués, liens d'administration rapide (Dashboard, Monitoring, Formulaires, Alertes, Paramètres). Design moderne avec hero banner et nav tiles.

- **Graphique camembert CSS dans le monitoring** : Ajout d'un diagramme en anneau (donut chart) en CSS pur (`conic-gradient`) dans la page monitoring, montrant la répartition des soumissions par statut (validées / en cours / refusées) avec légende et pourcentages.

### Fonctionnalités

- **Fonction `generate_field_name()`** : Nouvelle fonction dans `helpers.php` qui convertit un libellé français en identifiant technique snake_case, avec suppression des accents (via `transliterator_transliterate` ou fallback manuel).

- **Fonction `parse_options_input()`** : Nouvelle fonction dans `helpers.php` qui accepte les options de sélecteur soit en JSON, soit une par ligne (format beaucoup plus accessible pour les non-techniques).

- **Lien "voir" dans le dashboard** : Chaque ligne du dashboard de supervision a désormais un lien "voir" à côté du bouton "détail", ouvrant la page de détail complète de la soumission.

- **Infrastructure de test automatisé** : Ajout d'un mode test complet activé par le header HTTP `X-Test-Mode: 1`. En mode test : l'authentification utilise le header `X-Test-User` au lieu de `AUTH_USER` (IIS), le CSRF est bypassé, les emails sont interceptés dans une file d'attente au lieu d'être envoyés, la base de données utilise `workflow_test.db` séparée, les réponses POST sont en JSON au lieu de redirections. API de test (`test_api.php`) avec actions : mails, tokens, submissions, cleanup, seeding, stats. Suite de tests HTTP (`test_http.php`) — 12 phases de tests via curl contre un serveur PHP dédié. Suite de tests CLI existante (`test_all.php`) — 47 tests en subprocess isolation.

- **Captures d'écran de l'application** : 17 captures d'écran haute résolution (1440×900, 2x DPI) ajoutées dans `docs/screenshots/` pour la documentation. Couvrent toutes les vues : agent (accueil, formulaires, suivi, détail), validateur (validations, décision), admin (dashboard, monitoring, form builder, alertes, paramètres, accès, aperçu, docs, changelog).

- **Documentation technique refondue** : Réécriture complète de `AGENT.md` (guide technique IA) et `README.md` avec captures d'écran intégrées, tables de référence, diagramme workflow, section mode test détaillée.

---

## [2.4.0] — 2026-06-13

### Fonctionnalités majeures

- **Système d'alerte paramétrable** : Nouveau système complet permettant de configurer des alertes automatiques basées sur la proximité d'une date cible (deadline). Si un onboarding est prévu pour le 20/06 et que le 15/06 toutes les étapes ne sont pas encore faites, une alerte email est envoyée. Comprend : table `alert_rules` (règles par formulaire, nombre de jours avant la deadline, condition de déclenchement, destinataires), table `alert_log` (historique des alertes envoyées, évitement des doublons), script CLI `alert_check.php` (à planifier via Task Scheduler), et interface d'administration complète `admin_alerts.php`.

- **Champ date limite par formulaire** : Nouvelle colonne `deadline_field` sur la table `forms` permettant d'associer un champ de type date du formulaire comme date cible pour les alertes. Pour l'onboarding, c'est `date_prise_poste` ; pour l'outboarding, c'est `date_depart`. Configurable depuis la page d'administration des alertes.

- **Page admin_alerts.php** : Interface d'administration des règles d'alerte avec : configuration du champ date limite par formulaire, création/modification/suppression de règles (J-N jours avant la deadline, condition « étapes incomplètes », destinataires parmi : administrateurs, agent, validateurs en cours, admin+agent, admin+validateurs, ou email personnalisé), activation/désactivation individuelle, historique des alertes envoyées (50 dernières), purge des logs > 90 jours, statut du script `alert_check.php` (dernière exécution, alerte si > 24h).

- **Script alert_check.php** : Script CLI qui vérifie les soumissions en cours, calcule la distance à la date cible, évalue les conditions (étapes incomplètes), détermine les destinataires, envoie les emails d'alerte avec un tableau récapitulatif des étapes, et trace chaque envoi dans `alert_log`. Les doublons sont évités (une seule alerte par règle + soumission + jour). Planification recommandée : toutes les 6h via Windows Task Scheduler.

- **Intégration monitoring** : La page `monitoring.php` affiche désormais une section « Alertes actives » avec les soumissions en cours proches de leur date cible (code couleur : rouge si dépassé, orange si J-2 ou moins, jaune si J-5 ou moins), le compteur d'alertes actives dans les statistiques globales, l'historique des dernières alertes envoyées, et le statut du script `alert_check.php` dans la section scripts automatisés.

### Fonctionnalités

- **Colonne « Date cible » dynamique dans le dashboard** : Le tableau du dashboard affiche désormais la date cible (deadline) de chaque soumission au lieu du champ en dur `date_prise_poste`. La date est colorée en rouge si la deadline est dépassée ou imminente (J-2 ou moins), en orange si proche (J-5 ou moins). La valeur est résolue dynamiquement via le `deadline_field` configuré sur le formulaire.

- **Lien « 🔔 Alertes » dans le bandeau et le dashboard** : Accès direct à la page de configuration des alertes depuis le dashboard et les bandeaux de navigation.

- **Seed des règles d'alerte par défaut** : À l'installation, deux règles sont créées pour chaque formulaire (J-5 et J-2 avant la deadline), et le `deadline_field` est automatiquement configuré (`date_prise_poste` pour l'onboarding, `date_depart` pour l'outboarding).

- **Email d'alerte riche** : L'email d'alerte contient un bandeau coloré selon l'urgence (rouge si dépassé, orange si J-2, bleu si J-5+), les informations de l'agent, la date cible, le nombre de jours restants, l'avancement (validées/total), et un tableau détaillé des étapes avec leur statut.

---

## [2.3.0] — 2026-06-13

### Fonctionnalités majeures

- **Dashboard validateur (`my_validations.php`)** : Nouvelle page dédiée aux validateurs leur permettant de voir toutes leurs tâches de validation en attente et leur historique de validations. Comprend : vue des tokens en attente avec données du formulaire et progression du circuit (mini-workflow), détection des tokens expirés, historique des validations passées avec délai de traitement, onglets En attente / Historique, et lien direct vers la page de validation. Accessible depuis le bandeau de toutes les pages via le lien « ✅ Mes validations ».

- **Progression du workflow dans validate.php** : Quand un validateur clique sur un lien de validation, il voit désormais la progression complète du circuit (étapes validées, en cours, à venir) avant de prendre sa décision. Un lien « ← Mes validations » permet de revenir au dashboard validateur.

- **Régénération de token par l'admin** : Depuis le dashboard de supervision, un administrateur peut régénérer un lien de validation expiré ou perdu pour un validateur. L'ancien token est invalidé, un nouveau est créé avec une nouvelle date d'expiration, et un email de renvoi est envoyé au validateur. L'action est protégée par CSRF et tracée dans l'audit log.

- **Annulation de soumission** : Un agent ou un administrateur peut annuler une soumission en cours depuis le dashboard. La soumission est fermée avec le statut « refusé », tous les tokens en attente sont clôturés, et l'agent est notifié par email. L'action est protégée par confirmation JavaScript et CSRF.

### Refactoring

- **CSS partagé via `style.php`** : Tout le CSS commun (reset, bandeau, cards, boutons, formulaires, tables, badges, stats, timeline, etc.) est désormais dans un fichier `style.php` inclus via `require_once`. Chaque page ne contient plus que son CSS spécifique dans un second bloc `<style>`. Cela élimine la duplication de ~200 lignes de CSS par page et facilite la maintenance.

---

## [2.2.0] — 2026-06-13

### Fonctionnalités majeures

- **Formulaire d'outboarding** : Nouveau formulaire « Outboarding agent » (slug : `outboarding`) pour le départ d'un agent — restitution du matériel, révocation des accès, formalités RH et logistique. 21 champs répartis en 4 groupes (Identité, Informatique, RH, Logistique) avec 4 étapes de validation par défaut (Responsable direct, Service informatique, RH, Logistique). Seed automatique en base.

- **Page monitoring.php** : Nouveau tableau de bord d'observabilité pour les administrateurs. Comprend : métriques globales (total soumissions, taux de validation, temps moyen de traitement), détection des tokens bloqués (en attente depuis plus de 2x le délai de relance), tokens expirés non traités, santé SMTP (test en un clic), suivi du script de relance (dernière exécution, alerte si > 24h), statistiques par formulaire, activité des 7 derniers jours (barres visuelles), et journal d'audit consultable avec filtres.

- **Journal d'audit** : Nouvelle table `audit_log` et fonction `app_log()` qui tracent toutes les actions administratives (création/suppression de formulaires, ajout d'étapes, modification de paramètres, approbation/refus d'accès admin, exécution du script de relance, exports CSV, complétion de workflow). Chaque entrée enregistre l'action, la cible, un détail lisible, l'acteur (email) et l'adresse IP. Le journal est consultable depuis la page monitoring avec filtre par type d'action.

- **Export CSV** : Fonction `export_csv()` permettant d'exporter les soumissions au format CSV (avec BOM UTF-8 pour Excel, séparateur point-virgule). Les colonnes dynamiques du formulaire sont automatiquement ajoutées. Export accessible depuis le dashboard avec conservation des filtres (statut, formulaire). L'export est tracé dans le journal d'audit.

### Sécurité

- **Approbation admin via POST** : Les liens d'approbation/refus dans les emails admin n'ont plus d'effet de bord au GET. Le clic sur un lien email affiche désormais une page de confirmation avec formulaire POST et protection CSRF, empêchant les préfetch de scanners email ou proxys de déclencher une action non intentionnelle.

- **Validation des emails destinataires** : L'ajout d'un destinataire à une étape de validation vérifie désormais le format de l'adresse email via `filter_var(FILTER_VALIDATE_EMAIL)`. Un message d'erreur clair est affiché si le format est invalide.

### Fonctionnalités

- **Notification de validation finale** : L'agent reçoit désormais un email de confirmation quand sa demande est entièrement validée (toutes les étapes du workflow complétées). Auparavant, seul un email de confirmation de soumission et de refus était envoyé.

- **Traçabilité du script de relance** : `remind.php` enregistre désormais sa dernière date d'exécution dans la table `settings` (clé `last_remind_run`) et logue le nombre de relances envoyées et bloquées dans le journal d'audit. La page monitoring affiche cette information et alerte si le script n'a pas été exécuté depuis plus de 24h.

- **Lien Monitoring dans le dashboard** : Le bouton « 🖥 Monitoring » est désormais accessible depuis la barre d'outils du dashboard de supervision.

- **Audit logging des actions admin** : Toutes les actions de modification dans `admin_forms.php` (création/modification/suppression de formulaires, étapes, destinataires, champs) et `admin_settings.php` (modification des paramètres) sont désormais tracées dans le journal d'audit via `app_log()`.

---

## [2.1.0] — 2026-06-11

### Fonctionnalités

- **Footer avec version** : Toutes les pages affichent un footer avec la version de l'application sous forme de lien cliquable vers le journal des modifications
- **Page changelog.php** : Nouvelle page qui parse le fichier `CHANGELOG.md` et l'affiche de manière formatée avec icônes par section, navigation entre versions et couleurs distinctes (sécurité, fonctionnalités, corrections, UX, nettoyage)
- **Constante APP_VERSION** : Version de l'application définie dans `config.php`, utilisée dans le footer et la page changelog
- **Script `update.ps1`** : Script PowerShell de mise à jour automatique qui télécharge les nouveaux fichiers depuis le dépôt GitHub, avec sauvegarde automatique, mode simulation (`-DryRun`), protection des fichiers de configuration et nettoyage des anciens backups

---

## [2.0.0] — 2026-06-11

### Fonctionnalités majeures

- **Formulaire dynamique** : Les champs du formulaire sont désormais configurables en base de données via la table `form_fields`. Un admin peut ajouter/modifier/supprimer des champs (text, date, select, checkbox, textarea) depuis le back office. Le formulaire hardcodé est supprimé au profit d'un rendu 100% dynamique groupé par cartes (`card_group`). Migration automatique des 21 champs existants de l'onboarding.
- **Page « Mes demandes »** : Nouvelle page `my_submissions.php` permettant à l'agent de suivre l'avancement de toutes ses soumissions avec timeline visuelle (✓ validé / ⏳ en cours / ○ à venir), badges de statut, détails du refus le cas échéant, et liens vers les formulaires actifs.

### Fonctionnalités

- **Protection contre la suppression d'éléments actifs** : Impossible de supprimer un formulaire ou une étape si des soumissions en cours y sont rattachées (`has_active_submissions()`, `has_active_step_submissions()`)
- **Plafond de relances** : Nouveau paramètre `relance_max` (défaut 3). Les tokens ont un compteur `relance_count`. Quand le plafond est atteint, les relances sont bloquées et loguées. Configurable depuis les paramètres admin.
- **Erreur conviviale si AUTH_USER absent** : `get_auth_user()` affiche une page 401 stylisée au lieu d'une exception fatale PHP brute

### UX / Accessibilité

- **Erreurs de validation ciblées** : Chaque champ en erreur est mis en surbrillance avec message d'erreur en dessous, scroll automatique vers le premier champ en erreur
- **Pagination** : Le dashboard affiche 25 soumissions par page avec navigation (Précédent/Suivant)
- **Bandeau responsive** : `flex-wrap: wrap; gap: .5rem` sur le bandeau de toutes les pages
- **Lien admin conditionnel** : Le lien « ⚙ Paramètres » n'est visible que pour les utilisateurs admin
- **Labels accessibles** : Attributs `for`/`id` sur tous les labels et inputs, `fieldset`/`legend` à la place de `div`/`h2`
- **ARIA** : `aria-required`, `aria-invalid`, `aria-describedby` sur les champs du formulaire
- **Aide sur les champs date** : Indication « Format : JJ/MM/AAAA » sous les champs date
- **Favicon** : Icône SVG inline (D bleu sur fond #003189) sur toutes les pages

---

## [1.1.0] — 2026-06-11

### Sécurité

- **CSRF** : Ajout de tokens CSRF sur tous les formulaires POST (form, validate, admin_access, admin_forms, admin_settings)
- **Injection SQL** : Remplacement de `$pdo->quote()` par des requêtes préparées dans `dashboard.php`
- **GET/POST** : Séparation stricte dans `validate.php` — les requêtes GET n'ont plus d'effet de bord (plus d'appel à `validate_token()` au GET)
- **Token expiration** : Ajout d'une colonne `expires_at` sur les tokens, vérification dans `validate_token()`
- **Fichiers debug** : Suppression de `temp_fix.php` et `test_migration.php` (exposaient la structure DB et simulaient l'auth)

### Fonctionnalités

- **Paramètres SMTP configurables** : Nouvelle page `admin_settings.php` pour configurer SMTP (hôte, port, auth, TLS/SSL, identifiants, expéditeur) depuis l'interface admin
- **Table `settings`** : Stockage des paramètres en base (clé/valeur) avec `get_setting()` / `set_setting()`
- **Email de confirmation agent** : Envoi automatique d'un email de confirmation après soumission du formulaire
- **Notification de refus** : L'agent reçoit un email quand sa demande est refusée (avec motif si renseigné)
- **Champ `status`** : Nouveau champ `status` sur les soumissions (`en_cours` / `valide` / `refuse`) — fin du hack `REFUSED:` dans `closed_at`
- **Historique des validations** : Chaque validation/refus est enregistrée dans les données JSON de la soumission
- **Page documentation** : Nouvelle page `docs.php` avec guides agent, validateur, admin, FAQ et architecture technique

### Corrections

- `form.php` : Remplacement de `$_SERVER['AUTH_USER']` par `get_auth_user()`
- `form.php` : Suppression de la variable `$current_user` inutilisée
- `dashboard.php` : Suppression de `get_current_user()` (mauvaise fonction, variable inutilisée)
- `dashboard.php` : Affichage du statut basé sur le champ `status` au lieu du hack `REFUSED:`
- `helpers.php` : Vérification du retour de `send_mail()` dans `advance_workflow()` avec log d'erreur
- `remind.php` : Utilisation de `get_setting('delai_relance_h')` au lieu de la constante
- Migration automatique des données `REFUSED:` existantes vers le champ `status`

### Nettoyage

- Suppression de `.history/` du suivi git (46 fichiers de backup VSCode)
- Mise à jour du `.gitignore` : ajout de `/db/`, `*.db`, `/sessions/`, etc.

---

## [1.0.0] — 2026-03-17

### Initial

- Formulaire d'onboarding agent (champs hardcodés : identité, IT, RH, logistique)
- Moteur de workflow séquentiel/parallèle avec tokens
- Validation par email avec lien à usage unique
- Dashboard de supervision des soumissions
- Back office de gestion des formulaires, étapes et destinataires
- Gestion des accès admin avec approbation par email
- Script de relance automatique (cron)
- Base SQLite avec migration automatique
- Authentification Windows (IIS + Kerberos)
