<?php
declare(strict_types=1);

namespace App\Render;

/**
 * Render de la page "Gestion des formulaires" (admin_forms.php).
 *
 * Absorbe les 6 fichiers lib/admin_forms_render* en une seule classe.
 * Les méthodes produisent le même HTML que les fonctions originales.
 */
final class AdminFormsRenderer
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── CSS ──────────────────────────────────────────────────────

    /**
     * CSS spécifique à la page admin_forms.php.
     */
    public function getPageCss(): string
    {
        return <<<'CSS'
                    .container { max-width: 1200px; }

                    /* ── Section cards with colored headers ──────────────── */
                    .section-card {
                        background: var(--c-surface);
                        border: 1px solid var(--c-border);
                        border-radius: var(--r-md);
                        margin-bottom: 1.5rem;
                        overflow: hidden;
                    }
                    .section-card-header {
                        background: var(--c-primary-dark);
                        color: var(--c-text-inverse);
                        padding: .75rem 1.25rem;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    }
                    .section-card-header h2 {
                        color: var(--c-text-inverse);
                        border: none;
                        margin: 0;
                        padding: 0;
                        font-size: 1.05rem;
                    }
                    .section-card-header a {
                        color: var(--c-text-inverse);
                        text-decoration: none;
                        font-size: .82rem;
                        opacity: .85;
                    }
                    .section-card-header a:hover {
                        opacity: 1;
                    }
                    .section-card-header button.btn-secondary {
                        color: var(--c-sidebar-text);
                        background: var(--c-surface);
                        border: 1px solid var(--c-border);
                        font-size: .82rem;
                        opacity: .95;
                    }
                    .section-card-header button.btn-secondary:hover {
                        opacity: 1;
                        background: var(--c-primary-50);
                    }
                    .section-card-header button:not(.btn-secondary) {
                        color: var(--c-text-inverse);
                        font-size: .82rem;
                        opacity: .85;
                    }
                    .section-card-header button:not(.btn-secondary):hover {
                        opacity: 1;
                    }
                    .section-card-body {
                        padding: 1.25rem;
                    }

                    /* ── Workflow diagram ────────────────────────────────── */
                    .workflow-diagram {
                        display: flex;
                        align-items: flex-start;
                        gap: 0;
                        padding: 1.5rem 0.5rem;
                        overflow-x: auto;
                    }
                    .workflow-step-group {
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        min-width: 150px;
                        max-width: 200px;
                        flex-shrink: 0;
                    }
                    .workflow-arrow {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-width: 40px;
                        flex-shrink: 0;
                        padding-top: 0;
                        align-self: stretch;
                        display: flex;
                        align-items: center;
                    }
                    .workflow-arrow::after {
                        content: '→';
                        font-size: 1.8rem;
                        color: var(--c-primary-dark);
                        font-weight: bold;
                    }
                    .workflow-box {
                        background: var(--c-primary-dark);
                        color: var(--c-text-inverse);
                        border-radius: var(--r-md);
                        padding: .75rem 1rem;
                        text-align: center;
                        width: 100%;
                        margin-bottom: .5rem;
                        box-shadow: var(--shadow-colored);
                    }
                    .workflow-box.inactive {
                        background: #b0b0b0;
                        box-shadow: none;
                    }
                    .workflow-box .wb-label {
                        font-weight: bold;
                        font-size: .88rem;
                        margin-bottom: .25rem;
                    }
                    .workflow-box .wb-ordre {
                        font-size: .72rem;
                        opacity: .8;
                        margin-bottom: .35rem;
                    }
                    .workflow-box .wb-emails {
                        font-size: .72rem;
                        opacity: .75;
                        line-height: 1.4;
                        word-break: break-all;
                    }
                    .workflow-box.inactive .wb-label { opacity: .7; }
                    .workflow-box.inactive .wb-ordre { opacity: .5; }
                    .workflow-box.inactive .wb-emails { opacity: .5; }
                    .workflow-empty {
                        text-align: center;
                        padding: 2rem;
                        color: #888;
                        font-style: italic;
                    }

                    /* ── Step list items ─────────────────────────────────── */
                    .step-card {
                        border: 1px solid var(--c-border);
                        border-radius: var(--r-sm);
                        padding: .75rem 1rem;
                        margin-bottom: .75rem;
                        background: var(--c-bg-warm);
                        display: flex;
                        justify-content: space-between;
                        align-items: flex-start;
                        gap: 1rem;
                    }
                    .step-card.editing {
                        background: #f0f4ff;
                        border-color: var(--c-primary-dark);
                    }
                    .step-info { flex: 1; }
                    .step-info .step-label { font-weight: bold; color: var(--c-primary-dark); }
                    .step-info .step-meta { font-size: .82rem; color: #666; margin-top: .25rem; }
                    .step-info .step-meta .badge-ok { margin-left: .5rem; }
                    .step-actions { display: flex; gap: .4rem; flex-shrink: 0; }
                    .recipient-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .4rem; }
                    .recipient-chip {
                        background: #e3f2fd;
                        border: 1px solid #90caf9;
                        border-radius: 12px;
                        padding: .15rem .6rem;
                        font-size: .76rem;
                        color: #1565c0;
                        display: inline-flex;
                        align-items: center;
                        gap: .3rem;
                    }
                    .recipient-chip form {
                        display: inline;
                    }
                    .recipient-chip .chip-delete {
                        background: none;
                        border: none;
                        color: #c0392b;
                        cursor: pointer;
                        font-size: .9rem;
                        padding: 0;
                        line-height: 1;
                    }

                    /* ── Field table improvements ────────────────────────── */
                    .fields-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
                    .fields-table thead th {
                        background: var(--c-primary-dark);
                        color: var(--c-text-inverse);
                        padding: .55rem .6rem;
                        text-align: left;
                        font-weight: normal;
                        white-space: nowrap;
                    }
                    .fields-table tbody td {
                        padding: .5rem .6rem;
                        border-bottom: 1px solid #eee;
                        vertical-align: middle;
                    }
                    .fields-table tbody tr:hover { background: #f0f4ff; }
                    .field-type-badge {
                        display: inline-flex;
                        align-items: center;
                        gap: .3rem;
                        background: #e8eaf6;
                        color: var(--c-primary-dark);
                        border-radius: var(--r-sm);
                        padding: .2rem .5rem;
                        font-size: .78rem;
                        font-weight: bold;
                    }
                    .required-star {
                        color: #c0392b;
                        font-weight: bold;
                        font-size: 1rem;
                        margin-left: 2px;
                    }

                    /* ── Preview button ──────────────────────────────────── */
                    .btn-preview {
                        background: var(--c-success);
                        color: var(--c-text-inverse);
                        padding: .5rem 1rem;
                        border: none;
                        border-radius: var(--r-sm);
                        font-size: .85rem;
                        font-family: inherit;
                        cursor: pointer;
                        text-decoration: none;
                        display: inline-flex;
                        align-items: center;
                        gap: .3rem;
                    }
                    .btn-preview:hover { background: #219a52; }

                    /* ── Form grid ───────────────────────────────────────── */
                    .form-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: .75rem;
                    }
                    .form-grid .full-width {
                        grid-column: 1 / -1;
                    }
                    @media (max-width: 768px) {
                        .form-grid { grid-template-columns: 1fr; }
                    }

                    /* ── Step recipient section ──────────────────────────── */
                    .step-recipient-picker {
                        margin-top: 1rem;
                    }
                    .step-recipient-picker select {
                        max-width: 350px;
                    }

                    /* ── Add forms ───────────────────────────────────────── */
                    .add-sub-card {
                        background: #f9f9ff;
                        border: 1px dashed #aab;
                        border-radius: 5px;
                        padding: 1rem;
                        margin-top: 1rem;
                    }
                    .add-sub-card h4 {
                        font-size: .92rem;
                        color: var(--c-primary-dark);
                        margin-bottom: .75rem;
                    }
            CSS;
    }

    // ── Field type helpers ───────────────────────────────────────

    /**
     * Catalogue des types de champ (label avec icône) pour les sélecteurs.
     */
    public function getFormFieldTypes(): array
    {
        return [
            'text'     => '<span aria-hidden="true">📝</span> Texte',
            'email'    => '<span aria-hidden="true">📧</span> Courriel',
            'date'     => '<span aria-hidden="true">📅</span> Date',
            'select'   => '<span aria-hidden="true">📋</span> Sélecteur',
            'checkbox' => '<span aria-hidden="true">☑</span> Case à cocher',
            'textarea' => '<span aria-hidden="true">📝</span> Zone de texte',
            'file'     => '<span aria-hidden="true">📎</span> Fichier',
        ];
    }

    /**
     * Icône HTML pour un type de champ donné.
     */
    public function fieldTypeIcon(string $type): string
    {
        $icons = [
            'text'     => '<span aria-hidden="true">📝</span>',
            'email'    => '<span aria-hidden="true">📧</span>',
            'date'     => '<span aria-hidden="true">📅</span>',
            'select'   => '<span aria-hidden="true">📋</span>',
            'checkbox' => '<span aria-hidden="true">☑️</span>',
            'textarea' => '<span aria-hidden="true">📝</span>',
            'file'     => '<span aria-hidden="true">📎</span>',
        ];
        return $icons[$type] ?? '<span aria-hidden="true">📄</span>';
    }

    /**
     * Libellé humain pour un type de champ donné.
     */
    public function fieldTypeLabel(string $type): string
    {
        $labels = [
            'text'     => 'Texte',
            'email'    => 'Courriel',
            'date'     => 'Date',
            'select'   => 'Sélecteur',
            'checkbox' => 'Case à cocher',
            'textarea' => 'Zone de texte',
            'file'     => 'Fichier',
        ];
        return $labels[$type] ?? $type;
    }

    /**
     * Convertit un JSON d'options en lignes de texte (pour textarea).
     */
    public function optionsToLines(?string $json): string
    {
        if (in_array($json, [null, '', '0'], true)) {
            return '';
        }
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return implode("\n", $decoded);
        }
        return $json;
    }

    // ── Panels ───────────────────────────────────────────────────

    /**
     * Panneau « Sélecteur de formulaire » + actions globales.
     */
    public function renderSelectorPanel(array $ctx): string
    {
        $forms   = $ctx['forms']   ?? [];
        $form_id = $ctx['form_id'] ?? '';

        ob_start();
        ?>
    <!-- ── Form selector ──────────────────────────────────────── -->
    <div class="form-selector">
        <form method="GET" style="display:inline-flex;gap:.5rem;align-items:center;">
            <select name="form_id">
                <option value="">— Sélectionner un formulaire —</option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?= $form['id'] ?>" <?= $form_id == $form['id'] ? 'selected' : '' ?>>
                        <?= \App\Core\App::html()->escape($form['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;">OK</button>
        </form>
        <a href="index.php?p=admin_forms" class="btn btn-primary">＋ Nouveau formulaire</a>
        <button type="button" onclick="document.getElementById('import-panel').classList.toggle('hidden')" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;"><span aria-hidden="true">📥</span> Importer JSON</button>
        <button type="button" onclick="document.getElementById('ai-prompt-panel').classList.toggle('hidden')" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;"><span aria-hidden="true">🤖</span> Prompt IA</button>
        <form method="POST" style="display:inline;">
            <?= \App\Core\App::security()->csrfField() ?>
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
     */
    public function renderImportJsonPanel(array $ctx): string
    {
        $preserved_json  = $ctx['preserved_json']  ?? '';
        $validation_html = $ctx['validation_html'] ?? '';

        ob_start();
        ?>
    <!-- ── Import JSON panel ──────────────────────────────────── -->
    <div id="import-panel" class="<?= empty($preserved_json) ? 'hidden' : '' ?>" style="margin-bottom:1.5rem;">
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
                    <?= \App\Core\App::security()->csrfField() ?>
                    <div class="field">
                        <label>Données JSON<span class="req">*</span></label>
                        <textarea name="json_data" rows="12" placeholder='{"schema_version":"1.0","form":{"label":"Mon formulaire","description":"..."},"fields":[{"label":"Nom","field_type":"text","field_name":"nom","required":1,"card_group":"Général","filled_by":"demandeur"},{"label":"Décision","field_type":"select","field_name":"decision","options":["Accepté","Refusé"],"required":1,"card_group":"Décision","filled_by":"validator","validator_step":"Validation manager"}],"steps":[{"label":"Validation manager","ordre":1,"recipients":["manager@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?>"]}]}' style="font-family:monospace;font-size:.8rem;"><?= \App\Core\App::html()->escape($preserved_json) ?></textarea>
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
     * Panneau « Prompt IA » : prompt pré-rempli à copier-coller.
     */
    public function renderPromptIaPanel(): string
    {
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
                    <pre id="ai-prompt" style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:6px;font-size:.78rem;line-height:1.6;white-space:pre-wrap;word-break:break-word;max-height:500px;overflow-y:auto;">Tu es un assistant qui génère des formulaires administratifs ET leur circuit de validation (workflow) au format JSON pour l'application "<?php 
        <?= \App\Core\App::html()->escape(NavigationRenderer::getAppName()) ?>
        ?>".

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
    1. Adresse email statique du service validateur (ex: "rh@<?php 
        <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?>
        ?>", "dsi@<?php 
        <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?>
        ?>").
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
    { "label": "Validation RH", "ordre": 2, "actif": true, "recipients": ["rh@<?php 
        <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?>
        ?>"] }
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
     * Panneau « Créer un nouveau formulaire ».
     */
    public function renderNewFormPanel(): string
    {
        ob_start();
        ?>
    <!-- ── New form creation ──────────────────────────────────── -->
    <div class="section-card">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📋</span> Créer un nouveau formulaire</h2>
        </div>
        <div class="section-card-body">
            <form method="POST">
                <?php 
        <?= \App\Core\App::security()->csrfField() ?>

        ?>
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

    // ── Form sections ────────────────────────────────────────────

    /**
     * Barre d'actions supérieure : prévisualisation, export JSON, retour.
     */
    public function renderTopActionBar(array $ctx): string
    {
        $form_id = $ctx['form_id'] ?? '';
        $form    = $ctx['form']    ?? null;
        if (!$form) {
            return '';
        }

        ob_start();
        ?>
    <!-- ── Top action bar ──────────────────────────────── -->
    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;">
        <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview" target="_blank"><span aria-hidden="true">👁</span> Prévisualiser le formulaire</a>
        <form method="POST" style="display:inline;">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="export_form">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">📤</span> Exporter JSON</button>
        </form>
        <a href="index.php?p=dashboard" class="btn btn-secondary">← Tableau de bord</a>
    </div>
    <?php
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    /**
     * SECTION A : Informations du formulaire + actions dupliquer/supprimer.
     */
    public function renderFormInfoSection(array $ctx): string
    {
        $form = $ctx['form'] ?? null;
        if (!$form) {
            return '';
        }

        ob_start();
        ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION A: Form info                             -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📋</span> Informations du formulaire</h2>
            <form method="POST" style="display:inline;">
                <?= \App\Core\App::security()->csrfField() ?>
                <input type="hidden" name="action" value="duplicate_form">
                <input type="hidden" name="source_form_id" value="<?= $form['id'] ?>">
                <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">📋</span> Dupliquer</button>
            </form>
            <form method="POST" style="display:inline;">
                <?= \App\Core\App::security()->csrfField() ?>
                <input type="hidden" name="action" value="delete_form">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <button type="submit" style="background:#c0392b;color:#fff;border:none;border-radius:3px;padding:.3rem .7rem;cursor:pointer;font-size:.8rem;font-family:inherit;" onclick="return confirm('Supprimer ce formulaire et toutes ses données ? Cette action est irréversible.');">Supprimer</button>
            </form>
        </div>
        <div class="section-card-body">
            <form method="POST">
                <?= \App\Core\App::security()->csrfField() ?>
                <input type="hidden" name="action" value="update_form">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <div class="form-grid">
                    <div class="field">
                        <label>Libellé (affiché dans l'interface)<span class="req">*</span></label>
                        <input type="text" name="label" value="<?= \App\Core\App::html()->escape($form['label']) ?>" required>
                        <span class="hint">Identifiant technique : <code><?= \App\Core\App::html()->escape($form['slug']) ?></code> (généré automatiquement)</span>
                    </div>
                    <div class="field full-width">
                        <label>Description</label>
                        <textarea name="description" placeholder="Description du formulaire"><?= \App\Core\App::html()->escape($form['description']) ?></textarea>
                    </div>
                    <div class="field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="actif" <?= $form['actif'] ? 'checked' : '' ?>> Formulaire actif
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </form>
        </div>
    </div>
    <?php
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    /**
     * Section « Propriétaires du formulaire ».
     */
    public function renderOwnersSection(array $ctx): string
    {
        $form    = $ctx['form']    ?? null;
        $form_id = $ctx['form_id'] ?? '';
        $owners  = $ctx['owners']  ?? [];
        if (!$form) {
            return '';
        }

        ob_start();
        ?>
    <!-- Section Propriétaires du formulaire -->
    <div class="section-card" id="owners">
        <div class="section-card-header">
            <h2>👥 Propriétaires du formulaire</h2>
        </div>
        <div class="section-card-body">
        <p class="hint" style="margin-bottom:1rem;">Les propriétaires peuvent accéder au tableau de suivi spécifique de ce formulaire via la page <a href="index.php?p=form_tracking&f=<?= \App\Core\App::html()->escape($form['id'] ?? '') ?>">Suivi propriétaire</a>.</p>

        <?php if (!empty($owners)): ?>
            <table class="data-table" style="margin-bottom:1rem;">
                <thead>
                    <tr>
                        <th>Courriel</th>
                        <th>Ajouté le</th>
                        <th style="width:80px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($owners as $owner): ?>
                    <tr>
                        <td><?= \App\Core\App::html()->displayUser($owner['email']) ?></td>
                        <td><?= \App\Core\App::html()->escape($owner['added_at']) ?></td>
                        <td>
                            <a href="index.php?p=confirm_action&action=remove_owner&id=<?= $owner['id'] ?>&form_id=<?= $form_id ?>" class="btn btn-sm btn-danger">Retirer</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#595959;font-style:italic;margin-bottom:1rem;">Aucun propriétaire défini. Seuls les administrateurs peuvent voir le tableau de suivi.</p>
        <?php endif; ?>

        <form method="POST" action="index.php?p=admin_forms&form_id=<?= $form_id ?>#owners">
            <?= \App\Core\App::security()->csrfField() ?>
            <input type="hidden" name="action" value="add_owner">
            <input type="hidden" name="form_id" value="<?= $form_id ?>">
            <div style="display:flex;gap:.5rem;align-items:center;">
                <input type="email" name="owner_email" placeholder="prenom.nom@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?>" required style="flex:1;">
                <button type="submit" class="btn btn-primary">Ajouter un propriétaire</button>
            </div>
        </form>

        <?php if (!empty($owners)): ?>
            <div style="margin-top:1rem;">
                <a href="index.php?p=form_tracking&f=<?= \App\Core\App::html()->escape($form['id'] ?? '') ?>" class="btn btn-secondary"><span aria-hidden="true">📊</span> Ouvrir le tableau de suivi</a>
            </div>
        <?php endif; ?>
        </div>
    </div>
    <?php
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    // ── Workflow section ──────────────────────────────────────────

    /**
     * SECTION B : Circuit de validation (diagramme visuel + liste des étapes).
     */
    public function renderWorkflowDiagramSection(array $ctx): string
    {
        $form_id        = $ctx['form_id']        ?? '';
        $steps          = $ctx['steps']          ?? [];
        $steps_by_ordre = $ctx['steps_by_ordre'] ?? [];
        $edit_step_id   = $ctx['edit_step_id']   ?? '';

        ob_start();
        ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION B: Workflow diagram + Steps              -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card" id="workflow">
        <div class="section-card-header">
            <h2>🔀 Circuit de validation</h2>
        </div>
        <div class="section-card-body">

            <!-- ── Visual Workflow Diagram ─────────────────── -->
            <?php if (!empty($steps_by_ordre)): ?>
                <div class="workflow-diagram">
                    <?php
                    $ordre_keys = array_keys($steps_by_ordre);
                $last_key = array_last($ordre_keys);
                ?>
                    <?php foreach ($steps_by_ordre as $ordre => $ordre_steps): ?>
                        <div class="workflow-step-group">
                            <?php foreach ($ordre_steps as $idx => $wstep): ?>
                                <div class="workflow-box <?= $wstep['actif'] ? '' : 'inactive' ?>" style="<?= count($ordre_steps) > 1 && $idx > 0 ? 'margin-top:.5rem;' : '' ?>">
                                    <div class="wb-label"><?= \App\Core\App::html()->escape($wstep['label']) ?></div>
                                    <div class="wb-ordre">Étape <?= \App\Core\App::html()->escape((string) $ordre) ?></div>
                                    <?php if (!empty($wstep['recipients'])): ?>
                                        <div class="wb-emails"><?= \App\Core\App::html()->escape(implode(', ', array_column($wstep['recipients'], 'email'))) ?></div>
                                    <?php else: ?>
                                        <div class="wb-emails" style="font-style:italic;">Aucun destinataire</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ordre !== $last_key): ?>
                            <div class="workflow-arrow"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="workflow-empty">Aucune étape définie. Ajoutez-en ci-dessous.</div>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid #dde;margin:1rem 0;">

            <!-- ── Add step form ───────────────────────────── -->
            <div class="add-sub-card">
                <h4>＋ Ajouter une étape</h4>
                <form method="POST">
                    <?= \App\Core\App::security()->csrfField() ?>
                    <input type="hidden" name="action" value="add_step">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label>Libellé de l'étape<span class="req">*</span></label>
                            <input type="text" name="label" required placeholder="ex: Validation RH">
                        </div>
                        <div class="field">
                            <label>Ordre (numéro)<span class="req">*</span></label>
                            <?php $step_column = array_column($steps, 'ordre'); ?>
                            <input type="number" name="ordre" required min="1" value="<?= $step_column !== [] ? max($step_column) + 1 : 1 ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter l'étape</button>
                </form>
            </div>

            <!-- ── Step list ───────────────────────────────── -->
            <?php if (!empty($steps)): ?>
                <div style="margin-top:1.25rem;">
                    <?php foreach ($steps as $step): ?>
                        <?php if ($edit_step_id === $step['id']): ?>
                            <!-- ── Edit step inline ──────────────────── -->
                            <div class="step-card editing" id="step-<?= \App\Core\App::html()->escape($step['id']) ?>">
                                <div class="step-info" style="width:100%;">
                                    <form method="POST">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="update_step">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Libellé<span class="req">*</span></label>
                                                <input type="text" name="label" value="<?= \App\Core\App::html()->escape($step['label']) ?>" required>
                                            </div>
                                            <div class="field">
                                                <label>Ordre<span class="req">*</span></label>
                                                <input type="number" name="ordre" value="<?= $step['ordre'] ?>" required min="1">
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="checkbox-label">
                                                <input type="checkbox" name="actif" <?= $step['actif'] ? 'checked' : '' ?>> Étape active
                                            </label>
                                        </div>

                                        <?php
                                    $step_ordre_int = (int) ($step['ordre'] ?? 0);
                            $can_have_condition = $step_ordre_int > 1;

                            $existing_condition = ['field' => '', 'op' => '', 'value' => ''];
                            $raw_condition = (string) ($step['condition'] ?? '');
                            if ($raw_condition !== '') {
                                $decoded = json_decode($raw_condition, true);
                                if (is_array($decoded)) {
                                    $existing_condition['field'] = (string) ($decoded['field'] ?? '');
                                    $existing_condition['op']   = (string) ($decoded['op'] ?? '');
                                    $existing_condition['value'] = (string) ($decoded['value'] ?? '');
                                }
                            }

                            $validator_fields = $form_id !== '' ? \App\Core\App::validatorData()->getFormValidatorFields((string) $form_id) : [];
                            ?>

                                        <?php if ($can_have_condition): ?>
                                            <details style="margin-top:.5rem;border-top:1px dashed #dde;padding-top:.5rem;">
                                                <summary style="cursor:pointer;font-size:.85rem;font-weight:bold;">🔀 Condition d'exécution (optionnel)</summary>
                                                <div class="form-grid" style="margin-top:.5rem;">
                                                    <div class="field">
                                                        <label>Champ validateur à tester</label>
                                                        <select name="condition_field">
                                                            <option value="">— Toujours exécuter (pas de condition) —</option>
                                                            <?php foreach ($validator_fields as $validator_field): ?>
                                                                <?php $vf_name = (string) ($validator_field['field_name'] ?? ''); ?>
                                                                <option value="<?= \App\Core\App::html()->escape($vf_name) ?>" <?= $existing_condition['field'] === $vf_name ? 'selected' : '' ?>>
                                                                    <?= \App\Core\App::html()->escape((string) ($validator_field['label'] ?? $vf_name)) ?> (<?= \App\Core\App::html()->escape($vf_name) ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Opérateur</label>
                                                        <select name="condition_op">
                                                            <?php
                                                $ops = [
                                                    'equals'     => 'Égal à',
                                                    'not_equals' => 'Différent de',
                                                    'contains'   => 'Contient',
                                                    'not_empty'  => 'Non vide',
                                                    'empty'      => 'Vide',
                                                ];
                                            foreach ($ops as $op_val => $op_label):
                                                ?>
                                                                <option value="<?= \App\Core\App::html()->escape($op_val) ?>" <?= $existing_condition['op'] === $op_val ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($op_label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="field">
                                                        <label>Valeur attendue</label>
                                                        <input type="text" name="condition_value" value="<?= \App\Core\App::html()->escape($existing_condition['value']) ?>" placeholder="ex: Acceptée">
                                                        <span class="hint" style="font-size:.7rem;color:#777;">Utilisé pour « Égal à », « Différent de », « Contient ». Ignoré pour « Non vide » / « Vide ».</span>
                                                    </div>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <div style="margin-top:.5rem;font-size:.78rem;color:#777;">
                                                ℹ️ La condition d'exécution n'est disponible qu'à partir de l'ordre 2 (la première étape s'exécute toujours).
                                            </div>
                                        <?php endif; ?>

                                        <div style="display:flex;gap:.5rem;">
                                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#step-<?= $step['id'] ?>" class="btn btn-secondary">Annuler</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="step-card" id="step-<?= \App\Core\App::html()->escape($step['id']) ?>">
                                <div class="step-info">
                                    <span class="step-label"><?= \App\Core\App::html()->escape($step['label']) ?></span>
                                    <div class="step-meta">
                                        Ordre <?= \App\Core\App::html()->escape((string) $step['ordre']) ?>
                                        <?php if ($step['actif']): ?>
                                            <span class="badge badge-ok">Actif</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#eee;color:#595959;">Inactif</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($step['recipients'])): ?>
                                        <div class="recipient-chips">
                                            <?php foreach ($step['recipients'] as $rcpt): ?>
                                                <span class="recipient-chip">
                                                    <?= \App\Core\App::html()->displayUser($rcpt['email']) ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?= \App\Core\App::security()->csrfField() ?>
                                                        <input type="hidden" name="action" value="delete_recipient">
                                                        <input type="hidden" name="recipient_id" value="<?= $rcpt['id'] ?>">
                                                        <button type="submit" class="chip-delete" title="Supprimer">×</button>
                                                    </form>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:.8rem;color:#999;margin-top:.3rem;">Aucun destinataire</div>
                                    <?php endif; ?>
                                </div>
                                <div class="step-actions">
                                    <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_step=<?= $step['id'] ?>#step-<?= $step['id'] ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;">Modifier</a>
                                    <form method="POST" style="display:inline;">
                                        <?= \App\Core\App::security()->csrfField() ?>
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="font-size:.78rem;padding:.3rem .6rem;" onclick="return confirm('Supprimer cette étape ? Les validateurs associés perdront leurs accès.');">Supprimer</button>
                                    </form>
                                    <!-- ── Mini-formulaire inline "＋ Destinataire" ─── -->
                                    <details style="display:inline-block;position:relative;">
                                        <summary class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;cursor:pointer;list-style:none;display:inline-block;">＋ Destinataire</summary>
                                        <div style="position:absolute;z-index:20;right:0;top:100%;background:#fff;border:1px solid var(--c-border);border-radius:6px;padding:.75rem;box-shadow:var(--shadow-md);min-width:320px;margin-top:.25rem;">
                                            <form method="POST">
                                                <?= \App\Core\App::security()->csrfField() ?>
                                                <input type="hidden" name="action" value="add_recipient">
                                                <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                                                <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                                <label style="font-size:.75rem;color:#595959;display:block;margin-bottom:.25rem;">Courriel du destinataire <span class="req">*</span></label>
                                                <input type="text" name="email" required placeholder="ex: prenom.nom@dreets.gouv.fr ou {{nom_du_champ}}" list="ldap-recipient-suggestions" autocomplete="off" style="width:100%;margin-bottom:.35rem;">
                                                <span class="hint" style="font-size:.7rem;color:#777;display:block;margin-bottom:.5rem;">Email statique ou référence dynamique <code>{{champ}}</code>.</span>
                                                <div style="display:flex;gap:.4rem;justify-content:flex-end;">
                                                    <button type="button" class="btn btn-secondary" style="font-size:.78rem;padding:.3rem .6rem;" onclick="this.closest('details').open=false;">Annuler</button>
                                                    <button type="submit" class="btn btn-primary" style="font-size:.78rem;padding:.3rem .6rem;">Ajouter</button>
                                                </div>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($steps)): ?>
                <?= (new \App\Render\LdapRenderer())->datalist('ldap-recipient-suggestions', '', 300) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    // ── Fields section ────────────────────────────────────────────

    /**
     * SECTION D : Champs du formulaire.
     */
    public function renderFormFieldsSection(array $ctx): string
    {
        $form_id         = $ctx['form_id']         ?? '';
        $form_fields     = $ctx['form_fields']     ?? [];
        $edit_field_id   = $ctx['edit_field_id']   ?? '';
        $existing_groups = $ctx['existing_groups'] ?? [];
        $steps           = $ctx['steps']           ?? [];
        $field_types     = $this->getFormFieldTypes();

        ob_start();
        ?>
    <!-- ══════════════════════════════════════════════════ -->
    <!-- SECTION D: Form fields                          -->
    <!-- ══════════════════════════════════════════════════ -->
    <div class="section-card" id="fields">
        <div class="section-card-header">
            <h2><span aria-hidden="true">📝</span> Champs du formulaire</h2>
            <a href="index.php?p=form_preview&form_id=<?= $form_id ?>" class="btn-preview" target="_blank" style="font-size:.8rem;"><span aria-hidden="true">👁</span> Prévisualiser</a>
        </div>
        <div class="section-card-body">
            <p style="font-size:.85rem;color:#666;margin-bottom:1rem;">Ces champs définissent le formulaire que les agents rempliront. <span class="required-star">*</span> = champ obligatoire.</p>

            <?php if (!empty($form_fields)): ?>
                <table class="fields-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Groupe</th>
                            <th>Libellé</th>
                            <th>Identifiant</th>
                            <th>Type</th>
                            <th>Options</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($form_fields as $form_field): ?>
                            <?php if ($edit_field_id === $form_field['id']): ?>
                                <!-- ── Edit field inline ──────────────── -->
                                <tr>
                                    <td colspan="7" style="background:#f0f4ff;padding:1rem;">
                                        <h4 style="margin-bottom:.75rem;color:#003189;">Modifier le champ</h4>
                                        <form method="POST">
                                            <?= \App\Core\App::security()->csrfField() ?>
                                            <input type="hidden" name="action" value="update_field">
                                            <input type="hidden" name="field_id" value="<?= $form_field['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Libellé<span class="req">*</span></label>
                                                    <input type="text" name="ff_label" value="<?= \App\Core\App::html()->escape($form_field['label']) ?>" required>
                                                </div>
                                                <div class="field">
                                                    <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                                                    <input type="text" name="ff_field_name" value="<?= \App\Core\App::html()->escape($form_field['field_name']) ?>" placeholder="Généré automatiquement depuis le libellé">
                                                </div>
                                                <div class="field">
                                                    <label>Type de champ</label>
                                                    <select name="ff_field_type">
                                                        <?php foreach ($field_types as $val => $lbl): ?>
                                                            <option value="<?= $val ?>" <?= $form_field['field_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Ordre</label>
                                                    <input type="number" name="ff_ordre" value="<?= $form_field['ordre'] ?>" min="0">
                                                </div>
                                                <div class="field">
                                                    <label>Groupe (carte)</label>
                                                    <?php if (!empty($existing_groups)): ?>
                                                        <select name="ff_card_group">
                                                            <?php foreach ($existing_groups as $existing_group): ?>
                                                                <option value="<?= \App\Core\App::html()->escape($existing_group) ?>" <?= $form_field['card_group'] === $existing_group ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($existing_group) ?></option>
                                                            <?php endforeach; ?>
                                                            <option value="__new__" <?= in_array($form_field['card_group'], $existing_groups) ? '' : 'selected' ?>>— Nouveau groupe —</option>
                                                        </select>
                                                    <?php endif; ?>
                                                    <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                                                    <?php if (empty($existing_groups)): ?>
                                                        <input type="hidden" name="ff_card_group" value="">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="field">
                                                    <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                                                    <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"><?= \App\Core\App::html()->escape($this->optionsToLines($form_field['options'] ?? '')) ?></textarea>
                                                </div>
                                                <div class="field">
                                                    <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                                                    <input type="text" name="ff_hint" value="<?= \App\Core\App::html()->escape($form_field['hint'] ?? '') ?>" placeholder="ex : en euros TTC">
                                                </div>
                                                <div class="field">
                                                    <label>Rempli par</label>
                                                    <select name="ff_filled_by">
                                                        <option value="demandeur" <?= ($form_field['filled_by'] ?? '') === 'demandeur' || ($form_field['filled_by'] ?? '') === '' ? 'selected' : '' ?>>Demandeur</option>
                                                        <option value="validator" <?= ($form_field['filled_by'] ?? '') === 'validator' ? 'selected' : '' ?>>Validateur</option>
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Étape de validation <span class="hint">(obligatoire si "Validateur" ; laisser vide pour toutes les étapes)</span></label>
                                                    <select name="ff_validator_step">
                                                        <option value="">— Champ global (toutes étapes) —</option>
                                                        <?php foreach ($steps as $step): ?>
                                                            <option value="<?= \App\Core\App::html()->escape($step['id']) ?>" <?= (($form_field['validator_step'] ?? '') === $step['id'] || ($form_field['validator_step'] ?? '') === $step['label']) ? 'selected' : '' ?>>
                                                                <?= \App\Core\App::html()->escape($step['label']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="field ff-visibility-field">
                                                    <label>Visibilité <span class="hint">(uniquement pour les pièces jointes)</span></label>
                                                    <select name="ff_visibility">
                                                        <option value="all" <?= (($form_field['visibility'] ?? 'all') === 'all') ? 'selected' : '' ?>>Tous (validateurs + owner)</option>
                                                        <option value="owner_only" <?= (($form_field['visibility'] ?? 'all') === 'owner_only') ? 'selected' : '' ?>>Owner uniquement (caché des validateurs)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="field" style="margin-top:.25rem;">
                                                <label class="checkbox-label">
                                                    <input type="checkbox" name="ff_required" <?= $form_field['required'] ? 'checked' : '' ?>> Champ obligatoire <span class="required-star">*</span>
                                                </label>
                                            </div>
                                            <div style="display:flex;gap:.5rem;margin-top:.5rem;">
                                                <button type="submit" class="btn btn-primary">Enregistrer</button>
                                                <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>#field-<?= $form_field['id'] ?>" class="btn btn-secondary">Annuler</a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr id="field-<?= \App\Core\App::html()->escape($form_field['id']) ?>">
                                    <td><?= \App\Core\App::html()->escape((string) $form_field['ordre']) ?></td>
                                    <td><span style="font-size:.8rem;color:#666;"><?= \App\Core\App::html()->escape($form_field['card_group']) ?></span></td>
                                    <td>
                                        <?= \App\Core\App::html()->escape($form_field['label']) ?>
                                        <?php if ($form_field['required']): ?>
                                            <span class="required-star" title="Champ obligatoire">*</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code style="font-size:.78rem;background:#eef;padding:.1rem .3rem;border-radius:2px;"><?= \App\Core\App::html()->escape($form_field['field_name']) ?></code></td>
                                    <td>
                                        <span class="field-type-badge">
                                            <?= $this->fieldTypeIcon($form_field['field_type']) ?>
                                            <?= $this->fieldTypeLabel($form_field['field_type']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= \App\Core\App::html()->escape($form_field['options'] ?? '') ?>">
                                        <?php
                                        $opts = $form_field['options'] ?? '';
                            if (!empty($opts)) {
                                $decoded = json_decode($opts, true);
                                if (is_array($decoded)) {
                                    echo \App\Core\App::html()->escape(implode(', ', $decoded));
                                } else {
                                    echo \App\Core\App::html()->escape($opts);
                                }
                            } else {
                                echo '—';
                            }
                            ?>
                                    </td>
                                    <td class="actions">
                                        <a href="index.php?p=admin_forms&form_id=<?= $form_id ?>&edit_field=<?= $form_field['id'] ?>#field-<?= $form_field['id'] ?>" class="btn btn-secondary" style="font-size:.76rem;padding:.25rem .5rem;">Modifier</a>
                                        <form method="POST" style="display:inline;">
                                            <?= \App\Core\App::security()->csrfField() ?>
                                            <input type="hidden" name="action" value="delete_field">
                                            <input type="hidden" name="field_id" value="<?= $form_field['id'] ?>">
                                            <input type="hidden" name="form_id" value="<?= $form_id ?>">
                                            <button type="submit" class="btn btn-danger" style="font-size:.76rem;padding:.25rem .5rem;" onclick="return confirm('Supprimer ce champ ? Les données associées seront perdues.');">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">📝</div>
                    <p>Aucun champ défini pour ce formulaire.</p>
                </div>
            <?php endif; ?>

            <!-- ── Add field form ──────────────────────────── -->
            <div class="add-sub-card">
                <h4>＋ Ajouter un champ</h4>
                <form method="POST">
                    <?= \App\Core\App::security()->csrfField() ?>
                    <input type="hidden" name="action" value="add_field">
                    <input type="hidden" name="form_id" value="<?= $form_id ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label>Libellé<span class="req">*</span></label>
                            <input type="text" name="ff_label" required placeholder="ex: Nom, Date de début">
                        </div>
                        <div class="field">
                            <label>Identifiant technique <span class="hint">(auto si vide)</span></label>
                            <input type="text" name="ff_field_name" placeholder="Généré automatiquement depuis le libellé">
                        </div>
                        <div class="field">
                            <label>Type de champ</label>
                            <select name="ff_field_type">
                                <?php foreach ($field_types as $val => $lbl): ?>
                                    <option value="<?= $val ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Ordre</label>
                            <input type="number" name="ff_ordre" min="0" value="<?= count($form_fields) + 1 ?>">
                        </div>
                        <div class="field">
                            <label>Groupe (carte)</label>
                            <?php if (!empty($existing_groups)): ?>
                                <select name="ff_card_group">
                                    <?php foreach ($existing_groups as $existing_group): ?>
                                        <option value="<?= \App\Core\App::html()->escape($existing_group) ?>" <?= $existing_group === 'Général' ? 'selected' : '' ?>><?= \App\Core\App::html()->escape($existing_group) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__new__">— Nouveau groupe —</option>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="ff_card_group" value="">
                            <?php endif; ?>
                            <input type="text" name="ff_card_group_new" placeholder="Nom du nouveau groupe" style="margin-top:.3rem;" value="">
                        </div>
                        <div class="field full-width">
                            <label>Options <span class="hint">(une par ligne, uniquement pour Sélecteur)</span></label>
                            <textarea name="ff_options" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
                        </div>
                        <div class="field full-width">
                            <label>Indication <span class="hint">(texte d'aide sous le champ)</span></label>
                            <input type="text" name="ff_hint" placeholder="ex : en euros TTC">
                        </div>
                        <div class="field">
                            <label>Rempli par</label>
                            <select name="ff_filled_by">
                                <option value="demandeur" selected>Demandeur</option>
                                <option value="validator">Validateur</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Étape de validation <span class="hint">(obligatoire si "Validateur" ; laisser vide pour toutes les étapes)</span></label>
                            <select name="ff_validator_step">
                                <option value="">— Champ global (toutes étapes) —</option>
                                <?php foreach ($steps as $step): ?>
                                    <option value="<?= \App\Core\App::html()->escape($step['id']) ?>">
                                        <?= \App\Core\App::html()->escape($step['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field ff-visibility-field">
                            <label>Visibilité <span class="hint">(uniquement pour les pièces jointes)</span></label>
                            <select name="ff_visibility">
                                <option value="all" selected>Tous (validateurs + owner)</option>
                                <option value="owner_only">Owner uniquement (caché des validateurs)</option>
                            </select>
                        </div>
                    </div>
                    <div class="field" style="margin-top:.25rem;">
                        <label class="checkbox-label">
                            <input type="checkbox" name="ff_required"> Champ obligatoire <span class="required-star">*</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top:.5rem;">Ajouter le champ</button>
                </form>
            </div>
</div>
    </div>
    <?php
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }

    // ── Page rendering ────────────────────────────────────────────

    /**
     * Rend la page complète "Gestion des formulaires".
     */
    public function renderPage(array $ctx): void
    {
        $form_id      = $ctx['form_id']      ?? '';
        $form         = $ctx['form']         ?? null;
        $error_msg    = $ctx['error_msg']    ?? '';
        $success_msg  = $ctx['success_msg']  ?? '';

        $page_css = $this->getPageCss();

        ob_start();
        ?>
        <h1><span aria-hidden="true">⚙</span> Gestion des formulaires</h1>

        <?php if (!empty($success_msg)): ?>
            <div class="msg-success" role="status" aria-live="polite"><?= \App\Core\App::html()->escape($success_msg) ?></div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="msg-error" role="alert" aria-live="assertive"><?= \App\Core\App::html()->escape($error_msg) ?></div>
        <?php endif; ?>

        <?= $this->renderSelectorPanel($ctx) ?>
        <?= $this->renderImportJsonPanel($ctx) ?>
        <?= $this->renderPromptIaPanel($ctx) ?>

        <?php if (empty($form_id)): ?>
            <?= $this->renderNewFormPanel($ctx) ?>
        <?php else: ?>
            <?php if ($form): ?>
                <?= $this->renderTopActionBar($ctx) ?>
                <?= $this->renderFormInfoSection($ctx) ?>
                <?= $this->renderWorkflowDiagramSection($ctx) ?>
                <?= $this->renderFormFieldsSection($ctx) ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($form): ?>
            <?= $this->renderOwnersSection($ctx) ?>
        <?php endif; ?>
</div>
<?php
        $content = ob_get_clean();
        if ($content === false) {
            $content = '';
        }
        echo (new NavigationRenderer())->page('Gestion des formulaires', 'forms', $page_css, $content);
    }
}
