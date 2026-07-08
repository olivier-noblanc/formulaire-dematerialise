<?php
declare(strict_types=1);

/**
 * Panneaux de la page "Gestion des formulaires".
 *
 * Extrait de {@see render_admin_forms_page()} — contient les panneaux
 * autonomes affichés en haut de page :
 *  - {@see render_form_selector_panel()} : sélecteur de formulaire.
 *  - {@see render_import_json_panel()}   : import JSON d'un formulaire.
 *  - {@see render_prompt_ia_panel()}     : prompt IA pour générer un
 *    formulaire + circuit de validation depuis un document.
 *  - {@see render_new_form_panel()}      : création d'un nouveau formulaire.
 *
 * @package lib
 */

/**
 * Panneau « Sélecteur de formulaire » + actions globales (nouveau,
 * importer JSON, prompt IA, formulaires exemples).
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées : forms, form_id)
 * @return string HTML du panneau
 */
function render_form_selector_panel(array $ctx): string {
    $forms   = $ctx['forms']   ?? [];
    $form_id = $ctx['form_id'] ?? '';

    ob_start();
    ?>
    <!-- ── Form selector ──────────────────────────────────────── -->
    <div class="form-selector">
        <form method="GET" style="display:inline-flex;gap:.5rem;align-items:center;">
            <select name="form_id">
                <option value="">— Sélectionner un formulaire —</option>
                <?php foreach ($forms as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $form_id == $f['id'] ? 'selected' : '' ?>>
                        <?= h($f['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;">OK</button>
        </form>
        <a href="index.php?p=admin_forms" class="btn btn-primary">＋ Nouveau formulaire</a>
        <button type="button" onclick="document.getElementById('import-panel').classList.toggle('hidden')" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;"><span aria-hidden="true">📥</span> Importer JSON</button>
        <button type="button" onclick="document.getElementById('ai-prompt-panel').classList.toggle('hidden')" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;"><span aria-hidden="true">🤖</span> Prompt IA</button>
        <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="populate_samples">
            <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;"><span aria-hidden="true">📦</span> Formulaires exemples</button>
        </form>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

/**
 * Panneau « Importer un formulaire depuis JSON ».
 *
 * @param array<string,mixed> $ctx Contexte (clés utilisées : preserved_json, validation_html)
 * @return string HTML du panneau
 */
function render_import_json_panel(array $ctx): string {
    $preserved_json  = $ctx['preserved_json']  ?? '';
    $validation_html = $ctx['validation_html'] ?? '';

    ob_start();
    ?>
    <!-- ── Import JSON panel ──────────────────────────────────── -->
    <div id="import-panel" class="<?= !empty($preserved_json) ? '' : 'hidden' ?>" style="margin-bottom:1.5rem;">
        <div class="section-card">
            <div class="section-card-header">
                <h2><span aria-hidden="true">📥</span> Importer un formulaire depuis JSON</h2>
            </div>
            <div class="section-card-body">
                <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Collez un JSON décrivant un formulaire <strong>et son circuit de validation</strong> (exporté depuis cette page ou généré par une IA). Le format attendu : <code>{ "form": { "label": "..." }, "fields": [...], "steps": [...] }</code></p>

                <?php if (!empty($validation_html)): ?>
                    <?= $validation_html ?>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label>Données JSON<span class="req">*</span></label>
                        <textarea name="json_data" rows="12" placeholder='{"schema_version":"1.0","form":{"label":"Mon formulaire","description":"..."},"fields":[{"label":"Nom","field_type":"text","field_name":"nom","required":1,"card_group":"Général","filled_by":"demandeur"},{"label":"Décision","field_type":"select","field_name":"decision","options":["Accepté","Refusé"],"required":1,"card_group":"Décision","filled_by":"validator","validator_step":"Validation manager"}],"steps":[{"label":"Validation manager","ordre":1,"recipients":["manager@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>"]}]}' style="font-family:monospace;font-size:.8rem;"><?= h($preserved_json ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <input type="hidden" name="action" value="validate_json" id="import-action-input">
                        <button type="submit" class="btn btn-secondary" style="font-size:.85rem;"><span aria-hidden="true">🔍</span> Valider le JSON</button>
                        <button type="submit" class="btn btn-primary" style="font-size:.85rem;" onclick="document.getElementById('import-action-input').value='import_form';return true;"><span aria-hidden="true">📥</span> Importer le formulaire</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

/**
 * Panneau « Prompt IA » : prompt pré-rempli à copier-coller dans une IA
 * pour générer un formulaire + circuit de validation depuis un document
 * administratif.
 *
 * @param array<string,mixed> $ctx Contexte (non utilisé directement mais
 *                                 conservé pour homogénéité de l'API)
 * @return string HTML du panneau
 */
function render_prompt_ia_panel(array $ctx): string {
    ob_start();
    ?>
    <!-- ── Prompt IA panel ────────────────────────────────────── -->
    <div id="ai-prompt-panel" class="hidden" style="margin-bottom:1.5rem;">
        <div class="section-card">
            <div class="section-card-header">
                <h2><span aria-hidden="true">🤖</span> Prompt IA — Générer un formulaire + workflow à partir d'un document</h2>
            </div>
            <div class="section-card-body">
                <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Copiez le prompt ci-dessous, ajoutez votre document administratif, et collez le JSON retourné par l'IA dans le champ d'importation ci-dessus. Le JSON généré inclura les champs du formulaire <strong>et</strong> le circuit de validation (workflow).</p>
                <div class="field">
                    <label>Prompt à copier-coller <button type="button" onclick="(function(btn){var txt=document.getElementById('ai-prompt').innerText;try{navigator.clipboard.writeText(txt).then(function(){btn.textContent='✓ Copié !';setTimeout(function(){btn.textContent='📋 Copier'},2000)}).catch(function(){var ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);btn.textContent='✓ Copié !';setTimeout(function(){btn.textContent='📋 Copier'},2000)})}catch(e){var ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);btn.textContent='✓ Copié !';setTimeout(function(){btn.textContent='📋 Copier'},2000)}})(this)" style="font-size:.75rem;padding:.2rem .6rem;margin-left:.5rem;cursor:pointer;background:var(--c-primary);color:#fff;border:none;border-radius:4px;">📋 Copier</button></label>
                    <pre id="ai-prompt" style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:6px;font-size:.78rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;max-height:500px;overflow-y:auto;">Tu es un assistant qui génère des formulaires administratifs ET leur circuit de validation (workflow) au format JSON pour l'application "<?= h(get_app_name()) ?>".

Consignes :
- Analyse le document administratif fourni ci-dessous.
- Génère un JSON strictement conforme au schéma suivant.
- Le JSON doit contenir DEUX parties : les champs du formulaire (fields) ET le circuit de validation (steps).
- Ne fais JAMAIS référence à ton propre rôle, ne mets aucune explication hors JSON.

--- CHAMPS DU FORMULAIRE (fields) ---
- Chaque champ doit avoir un field_name technique en snake_case (sans accents, généré automatiquement depuis le label).
  Exemple : "Date d'arrivée" → "date_arrivee", "Type de demande" → "type_demande".
- field_type peut être : "text", "email", "date", "select", "checkbox", "textarea", "file".
- Utilise "email" pour les champs de courriel (adresse email) — cela garantit la validation HTML5 du format email.
- Pour "select", mets les options dans le tableau "options". Pour les autres types, "options" vaut null.
- Regroupe les champs par thème dans card_group (ex: "Identité", "Affectation", "IT", "Logistique", "Finance", "Demande").
- required : true si le champ est obligatoire, false sinon.
- hint : texte d'aide optionnel affiché sous le champ (ex: "Nom Prénom", "ex : 16h30").
- ordre : position du champ dans le formulaire (1, 2, 3...).
- filled_by : "demandeur" (défaut, rempli par l'agent) ou "validator" (réservé aux validateurs du circuit).
- validator_step : OBLIGATOIRE. Pour filled_by="demandeur" → mettre null. Pour filled_by="validator" → label EXACT d'une étape définie dans "steps" (ex: "Validation RH").
- visibility : OPTIONNEL, réservé UNIQUEMENT aux champs field_type="file". Valeurs : "all" (défaut, visible par tous) ou "owner_only" (visible uniquement par l'owner, caché des validateurs). NE PAS ajouter visibility sur les autres types de champs (text, select, etc.) — il sera ignoré.

--- GLOSSAIRE MÉTIER (vocabulaire administratif DREETS) ---
Utilise ces définitions pour comprendre les acronymes et termes métier AVANT d'appliquer les règles d'inférence field_type.

ACRONYMES / SIGLES :
- FEB    = Fiche d'Expression du Besoin → document administratif (field_type="file")
- RQTH   = Reconnaissance de la Qualité de Travailleur Handicapé → statut binaire (field_type="checkbox")
- SG     = Secrétaire Général → rôle validateur direction
- DSI    = Direction des Systèmes d'Information → rôle validateur IT
- RH     = Ressources Humaines → rôle validateur RH
- DREETS = Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités → organisme émetteur

COLONNES DE SUIVI (post-décision, à typer comme filled_by="validator" ou à exclure) :
- "Reste à traiter", "Actions à mettre en œuvre", "Matériel remis", "Clôturé le", "Traité par" → post-décision

OPTIONS MÉTIER STANDARD (si non précisées dans le document, utiliser ces valeurs par défaut) :
- "Origine de la demande" → ["Par l'agent", "Par le médecin de prévention", "Par le manager", "Autre"]
- "Décision"             → ["Acceptée", "Refusée", "Acceptée avec réserves", "Reportée"]
- "Type de congé"        → ["Congé annuel", "RTT", "Congé maladie", "Congé sans solde"]

--- INFÉRENCE DU FIELD_TYPE (règles générales, tout formulaire) ---
Utilise ces critères SÉMANTIQUES (le sens du champ), pas le label exact :
- "file" → le champ représente un document, une pièce jointe, un scan, un justificatif, une fiche, une attestation, un devis, une ordonnance. Signal : verbes "joindre", "uploader", "fournir", "annexer", ou mention d'un document physique (PDF, scan, copie).
- "checkbox" → le champ représente un état binaire (oui/non, présence/absence d'un statut). Signal : acronyme de statut (RQTH, CDI, télétravail actif...), ou formulation "bénéficie de", "est reconnu", "est titulaire de".
- "select" → le champ a un nombre fini d'options prévisibles à partir du contexte métier. Signal : "type de", "nature de", "catégorie", "origine de", "statut", "motif de".
- "textarea" → contenu libre long (avis, motif, description, observations, commentaires, bilan).
- "text" → contenu libre court non catégorisable autrement (nom, prénom, référence, numéro, libellé court).

Si tu identifies des ambiguïtés AVANT de générer le JSON :
→ Pose tes questions en langage naturel à l'utilisateur (une seule liste numérotée).
→ Attends ses réponses AVANT de produire le JSON.
→ Ne génère JAMAIS un JSON avec des valeurs inventées quand une question à l'utilisateur suffit.
Exemples d'ambiguïtés qui déclenchent une question : acronyme inconnu, libellé trop court dont le sens est incertain, champ dont le type ou les options ne peuvent pas être déduits du glossaire métier, circuit de validation dont les acteurs ne sont pas clairs, ou adresses email des validateurs absentes du document.
- Les adresses email des validateurs ne sont JAMAIS inventées ni remplacées par un placeholder (ex: "medecin@example.fr"). Si elles ne figurent pas dans le document → TOUJOURS inclure dans les questions : "Quelle est l'adresse email du validateur [nom du step] ?"

Si les options d'un select ne sont pas explicitement listées dans le document source :
→ Utiliser les OPTIONS MÉTIER STANDARD du glossaire si le label correspond.
→ Sinon, INCLURE ce champ dans ta liste de questions : demande à l'utilisateur quelles sont les valeurs possibles.
→ NE JAMAIS inventer des options aléatoires.

PRIORITÉ ABSOLUE : Les réponses de l'utilisateur à tes questions écrasent toutes les règles d'inférence du prompt. Si l'utilisateur dit "texte libre" → field_type="textarea", sans exception. Si l'utilisateur dit "liste fixe" → field_type="select". Ne jamais revenir à une inférence automatique après confirmation.

--- EXCLUSION DE CHAMPS (règles générales) ---
NE PAS générer un champ si :
- Son label contient une date absolue figée ET spécifique (ex: "Situation au 04/06/2026", "État au XX/XX/XXXX"). Un label court générique comme "DATE" ou "Date" n'est PAS une date figée — inclure le champ et poser une question si le sens est ambigu.
- Il représente une action POST-décision (ex: "Actions mises en œuvre", "Envoyé le", "Clôturé par", "Reste à traiter") → ce sont des champs filled_by="validator", pas demandeur.
- Il décrit un suivi interne purement administratif (ex: "Numéro de dossier", "Date de traitement") non saisissable par l'agent.
IMPORTANT : Si tu comptes exclure une colonne du document source, signale-le EXPLICITEMENT à l'utilisateur AVANT de générer : "La colonne X semble être [raison] — dois-je la supprimer, la garder en tant que champ, ou créer un step dédié ?". Ne supprime JAMAIS silencieusement un champ du document source.

--- INFÉRENCE filled_by (règle générale) ---
- filled_by="demandeur" par défaut (l'agent saisit la valeur au moment de la demande).
- filled_by="validator" si le champ :
  - Est rempli APRÈS soumission par un tiers (avis, décision, remise, validation, bilan, observations internes)
  - Ne peut pas être connu du demandeur au moment de la soumission
  - Représente une action de traitement, une conclusion ou un suivi POST-décision

--- CIRCUIT DE VALIDATION / WORKFLOW (steps) ---
- Les étapes représentent le circuit de validation du formulaire : une demande passe par chaque étape dans l'ordre, et les validateurs de chaque étape doivent approuver avant de passer à la suivante.
- Déduis les steps UNIQUEMENT depuis les colonnes du document qui représentent un avis/validation/décision d'un acteur (ex: "Avis médecin", "Validation manager", "Décision SG"). 1 colonne avis = 1 step. Ne pas inventer de steps absents du document.
- Chaque étape a :
  - label : nom descriptif de l'étape (ex: "Validation manager", "Validation RH", "Validation DSI").
  - ordre : numéro séquentiel (1 = première validation, 2 = deuxième, etc.).
  - actif : true (toujours true pour les nouvelles étapes).
  - recipients : tableau d'adresses email. Trois formats possibles :
    1. Adresse email statique du service validateur (ex: "rh@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>", "dsi@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>").
    2. Référence dynamique vers un champ du formulaire avec la syntaxe {{field_name}} — utile quand le validateur dépend de l'agent qui remplit le formulaire. Exemple : si le formulaire a un champ "email_superieur", utilise "{{email_superieur}}" pour que la demande soit envoyée au supérieur hiérarchique saisi par l'agent.
    3. Référence spéciale {{owner}} — le propriétaire du formulaire (l'admin qui a créé le formulaire). Utiliser quand le document indique que "l'owner", "le responsable" ou "l'administrateur du formulaire" doit valider, sans préciser d'adresse email. Ne PAS demander d'email dans ce cas.
- ATTENTION : Si le document mentionne un supérieur hiérarchique, un manager direct, ou un validateur dont l'email dépend de l'agent, il FAUT créer un champ email dans les fields ET utiliser la syntaxe {{field_name}} dans les recipients de l'étape correspondante.
- Si le document indique que "l'owner" ou "le responsable du formulaire" doit valider → utiliser {{owner}} comme recipient, SANS demander d'adresse email.
- Minimum 1 étape de validation. Typiquement 2 à 4 étapes selon la complexité du processus décrit dans le document.
- Si le document ne mentionne pas explicitement le circuit, INCLURE une question dans ta liste : demande à l'utilisateur quels sont les validateurs et leur ordre. Ne déduis JAMAIS un circuit sans confirmation.

--- CHAMPS VALIDATEUR (filled_by) ---
- Par défaut, tous les champs sont remplis par le demandeur (filled_by="demandeur").
- Pour certains champs réservés aux validateurs (ex: "Avis médecin", "Décision SG", "Matériel remis", "Bilan de départ"), utilisez filled_by="validator".
- Ces champs ne s'afficheront PAS au demandeur mais apparaîtront à l'étape de validation concernée.
- validator_step : OBLIGATOIRE sur tous les champs (null pour demandeur, label exact pour validator).
  - Pour filled_by="demandeur" → mettre validator_step: null (pas d'étape associée).
  - Pour filled_by="validator" → mettre le LABEL EXACT d'une étape définie dans "steps".
  - Note : le label doit correspondre EXACTEMENT au label d'une étape. Si le label d'étape change, le champ sera affiché à toutes les étapes (fallback).
- Exemples de champs validator typiques :
  - "Décision de validation" (select: Accepté/Refusé/Accepté avec réserves) sur l'étape finale
  - "Avis du médecin" (textarea) sur l'étape "Validation médecine du travail"
  - "Matériel remis" (text) sur l'étape "Validation logistique"
  - "Bilan de départ" (textarea) sur l'étape finale
- Limitez le nombre de champs validator (généralement 1 à 3 par formulaire).

--- SCHÉMA JSON ATTENDU ---
{
  "schema_version": "1.0",
  "form": {
    "label": "Nom du formulaire",
    "description": "Description courte du formulaire"
  },
  "fields": [
    {
      "label": "Libellé du champ visible par l'utilisateur",
      "field_type": "text | email | date | select | checkbox | textarea | file",
      "field_name": "nom_technique_snake_case",
      "options": null,
      "required": true,
      "ordre": 1,
      "card_group": "Nom de la section",
      "hint": "",
      "filled_by": "demandeur",
      "validator_step": null
    }
  ],
  "steps": [
    {
      "label": "Nom de l'étape de validation",
      "ordre": 1,
      "actif": true,
      "recipients": ["email-validateur@exemple.fr"]
    }
  ]
}

--- EXEMPLE COMPLET ---
{
  "schema_version": "1.0",
  "form": {
    "label": "Demande de congé",
    "description": "Formulaire de demande de congé avec validation hiérarchique"
  },
  "fields": [
    { "label": "Nom complet", "field_type": "text", "field_name": "nom_complet", "options": null, "required": true, "ordre": 1, "card_group": "Identité", "hint": "Nom Prénom", "filled_by": "demandeur", "validator_step": null },
    { "label": "Courriel du supérieur hiérarchique", "field_type": "email", "field_name": "email_superieur", "options": null, "required": true, "ordre": 2, "card_group": "Identité", "hint": "Adresse email de votre manager direct", "filled_by": "demandeur", "validator_step": null },
    { "label": "Date de début", "field_type": "date", "field_name": "date_debut", "options": null, "required": true, "ordre": 3, "card_group": "Demande", "hint": "", "filled_by": "demandeur", "validator_step": null },
    { "label": "Date de fin", "field_type": "date", "field_name": "date_fin", "options": null, "required": true, "ordre": 4, "card_group": "Demande", "hint": "", "filled_by": "demandeur", "validator_step": null },
    { "label": "Type de congé", "field_type": "select", "field_name": "type_conge", "options": ["Congé annuel", "Congé maladie", "Congé sans solde", "RTT"], "required": true, "ordre": 5, "card_group": "Demande", "hint": "", "filled_by": "demandeur", "validator_step": null },
    { "label": "Motif", "field_type": "textarea", "field_name": "motif", "options": null, "required": false, "ordre": 6, "card_group": "Demande", "hint": "", "filled_by": "demandeur", "validator_step": null },
    { "label": "CV détaillé", "field_type": "file", "field_name": "cv_detaille", "options": null, "required": false, "ordre": 7, "card_group": "Demande", "hint": "Visible uniquement par l'admin RH", "filled_by": "demandeur", "validator_step": null, "visibility": "owner_only" },
    { "label": "Décision finale", "field_type": "select", "field_name": "decision_finale", "options": ["Accepté", "Refusé", "Accepté avec réserves"], "required": true, "ordre": 8, "card_group": "Décision", "hint": "", "filled_by": "validator", "validator_step": "Validation RH" }
  ],
  "steps": [
    { "label": "Validation supérieur hiérarchique", "ordre": 1, "actif": true, "recipients": ["{{email_superieur}}"] },
    { "label": "Validation RH", "ordre": 2, "actif": true, "recipients": ["rh@<?= h(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>"] }
  ]
}

Voici le document administratif à analyser :

[COLLEZ VOTRE DOCUMENT ICI]</pre>
                </div>
            </div>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}

/**
 * Panneau « Créer un nouveau formulaire » (affiché quand aucun
 * formulaire n'est sélectionné).
 *
 * @param array<string,mixed> $ctx Contexte (non utilisé directement mais
 *                                 conservé pour homogénéité de l'API)
 * @return string HTML du panneau
 */
function render_new_form_panel(array $ctx): string {
    ob_start();
    ?>
    <!-- ── New form creation ──────────────────────────────────── -->
    <div class="section-card">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📋</span> Créer un nouveau formulaire</h2>
        </div>
        <div class="section-card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_form">
                <div class="form-grid">
                    <div class="field">
                        <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                        <input type="text" name="label" required placeholder="ex: Accueil agent" autofocus>
                        <span class="hint">L'identifiant technique (slug) est généré automatiquement à partir du libellé.</span>
                    </div>
                    <div class="field full-width">
                        <label>Description</label>
                        <textarea name="description" placeholder="Description du formulaire"></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Créer le formulaire</button>
            </form>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    return $html === false ? '' : $html;
}
