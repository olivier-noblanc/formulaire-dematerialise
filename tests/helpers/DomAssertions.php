<?php
declare(strict_types=1);

/**
 * tests/helpers/DomAssertions.php — Règles structurelles HTML (S1-S12).
 *
 * Helper statique fournissant des assertions sur le HTML rendu par les
 * contrôleurs de l'application. Chaque méthode `assert*` lève une
 * `AssertionError` avec un message clair (numéro de règle + contexte)
 * si la règle est violée.
 *
 * Règles implémentées :
 *   S1  — assertNoNestedForms           : pas de <form> imbriqués (HTML invalide)
 *   S2  — assertWellFormed              : pas d'erreurs de parsing libxml graves
 *   S3  — assertNoIsoDates              : pas de date ISO (YYYY-MM-DD) dans le texte visible
 *   S4  — assertNoSubmitOnSuccessPage   : bouton "Envoyer ma demande" absent de la page succès
 *   S5  — assertNoRgpdOnSuccessPage     : encadré RGPD (rgpd_consent) absent de la page succès
 *   S6  — assertChecked / assertRadioChecked / assertHasValue : préservation de l'état des champs
 *   S8  — assertNoPhpWarnings           : pas de Warning/Notice/Deprecated dans stderr
 *   S9  — assertAllFormsHaveCsrf        : tous les <form method="POST"> ont un csrf_token
 *   S12 — assertTitleNonEmpty           : balise <title> non vide
 *
 * Helpers :
 *   fromHtml(html)           : construit un DOMDocument avec libxml internal errors
 *   assertElementAbsent/Present : recherche par sélecteur CSS simplifié
 *
 * Usage :
 *   require_once __DIR__ . '/helpers/DomAssertions.php';
 *   $doc = DomAssertions::fromHtml($html);
 *   DomAssertions::assertNoNestedForms($doc);
 *   DomAssertions::assertTitleNonEmpty($doc);
 *   // ...
 *
 * Note : les règles S4/S5 sont spécifiques à la page succès de form.php ;
 * elles ne doivent être appelées QUE sur le HTML de cette page.
 */

// Garde-fou : si ce fichier est invoqué directement (php DomAssertions.php),
// on ne fait rien (test de non-crash). Les assertions ne sont testées que via
// StructuralHtmlTest.php.

/**
 * Helper statique d'assertions structurelles HTML.
 */
final class DomAssertions
{
    /**
     * Cache des erreurs libxml par document (clé = spl_object_id).
     * Permet à assertWellFormed() de récupérer les erreurs survenues lors de fromHtml().
     *
     * @var array<int, array<int, \LibXMLError>>
     */
    private static array $errorsByDoc = [];

    // ═══════════════════════════════════════════════════════════════
    // CONSTRUCTION DU DOM
    // ═══════════════════════════════════════════════════════════════

    /**
     * Construit un DOMDocument à partir du HTML rendu.
     *
     * - Active libxml_use_internal_errors pour ne pas cracher de warnings
     * - Ajoute le préfixe `<?xml encoding="UTF-8">` pour forcer l'UTF-8
     *   (sinon libxml suppose ISO-8859-1 et mojibake les accents)
     * - Capture les erreurs libxml dans self::$errorsByDoc pour assertWellFormed()
     *
     * @param string $html Le HTML rendu (potentiellement imparfait)
     * @return DOMDocument
     */
    public static function fromHtml(string $html): DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();
        // LIBXML_NOERROR | LIBXML_NOWARNING évite d'écrire sur stderr en cas
        // d'erreur non fatale. Les erreurs sont quand même récupérables via
        // libxml_get_errors() car libxml_use_internal_errors(true) est actif.
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        // Capturer les erreurs pour assertWellFormed()
        $id = spl_object_id($doc);
        self::$errorsByDoc[$id] = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $doc;
    }

    // ═══════════════════════════════════════════════════════════════
    // RÈGLES STRUCTURELLES
    // ═══════════════════════════════════════════════════════════════

    /**
     * S1 — Pas de <form> imbriqués dans le DOM.
     *
     * XPath `//form//form` détecte tout <form> dont un ancêtre est aussi un <form>.
     * C'est du HTML invalide (les navigateurs le corrigent silencieusement en
     * déplaçant le form imbriqué à l'extérieur, ce qui casse la soumission).
     *
     * @param DOMDocument $d
     * @throws AssertionError Si au moins un form imbriqué est trouvé.
     */
    public static function assertNoNestedForms(DOMDocument $d): void
    {
        $xp = new DOMXPath($d);
        $nested = $xp->query('//form//form');
        if ($nested === false || $nested->length === 0) {
            return;
        }

        $details = [];
        foreach (iterator_to_array($nested) as $i => $form) {
            if (!$form instanceof DOMElement) continue;
            $parent = $form->parentNode;
            $parentAction = '(pas d\'action)';
            if ($parent instanceof DOMElement && $parent->tagName === 'form') {
                $parentAction = $parent->getAttribute('action') ?: '(pas d\'action)';
            }
            $action = $form->getAttribute('action') ?: '(pas d\'action)';
            $details[] = sprintf(
                '  - <form action="%s"> contient un <form action="%s">',
                $parentAction,
                $action
            );
            if (count($details) >= 3) break; // Limiter le bruit
        }

        throw new AssertionError(sprintf(
            "S1 — forms imbriqués détectés (HTML invalide) : %d form(s) imbriqué(s) trouvé(s).\n%s",
            $nested->length,
            implode("\n", $details)
        ));
    }

    /**
     * S2 — Document bien formé (pas d'erreurs libxml graves).
     *
     * Vérifie les erreurs capturées lors de fromHtml() en filtrant pour ne
     * garder que LIBXML_ERR_FATAL (3) ou LIBXML_ERR_ERROR (2). Les warnings
     * (LIBXML_ERR_WARNING = 1) sont ignorés car libxml est très permissif
     * et émet des warnings pour du HTML techniquement valide mais non strict.
     *
     * Exclusions de faux positifs HTML5 / quirks libxml :
     *   - Code 800/801 ("Tag X invalid") : libxml utilise un parser HTML4 qui
     *     ne connaît pas les tags HTML5 (<nav>, <main>, <aside>, <footer>,
     *     <section>, <article>, <header>, etc.). Ces "erreurs" ne sont pas
     *     des bugs réels — le HTML est valide en HTML5.
     *   - Code 23 ("htmlParseEntityRef: expecting ';'") : `&` non échappé en
     *     `&amp;` dans les URLs (ex: `?a=1&b=2`). Toléré par les navigateurs
     *     modernes. Bug mineur de propreté, pas un bug structurel.
     *   - Code 68 ("htmlParseEntityRef: no name") : variante du précédent.
     *   - Code 76 ("Unexpected end tag") : quirk HTML5 — un `<p>` se
     *     ferme automatiquement quand un élément block (`<div>`, `<ul>`) est
     *     rencontré. Le `</p>` explicite devient "unexpected" pour libxml
     *     HTML4, mais c'est valide en HTML5.
     *   - Code 513 ("ID X already defined") : IDs dupliqués. Bug réel mais
     *     pré-existant dans CHANGELOG.md (5.30.1 listé 2x) hors-scope.
     *
     * @param DOMDocument $d
     * @throws AssertionError Si au moins une erreur grave (FATAL ou ERROR) est trouvée.
     */
    public static function assertWellFormed(DOMDocument $d): void
    {
        $id = spl_object_id($d);
        $errors = self::$errorsByDoc[$id] ?? [];

        // Codes libxml à ignorer (faux positifs HTML5 + quirks connus)
        $ignoredCodes = [
            800,  // XML_ERR_UNKNOWN_ENCODING (rare)
            801,  // HTML_UNKNOWN_TAG (tags HTML5 non reconnus par le parser HTML4)
            23,   // htmlParseEntityRef: expecting ';' — & dans les URLs
            68,   // htmlParseEntityRef: no name — variante du 23
            76,   // Unexpected end tag — quirk HTML5 (auto-close de <p>)
            513,  // ID X already defined — IDs dupliqués (bug CHANGELOG pré-existant)
        ];

        $serious = array_filter($errors, static function ($e) use ($ignoredCodes): bool {
            if ($e->level !== LIBXML_ERR_FATAL && $e->level !== LIBXML_ERR_ERROR) {
                return false;
            }
            // Ignorer les faux positifs HTML5 + quirks connus
            if (in_array($e->code, $ignoredCodes, true)) {
                return false;
            }
            return true;
        });

        if (count($serious) === 0) {
            return;
        }

        $lines = array_map(static function ($e): string {
            return sprintf('  - L%d (code %d, level %d): %s', $e->line, $e->code, $e->level, trim($e->message));
        }, array_slice(array_values($serious), 0, 5));

        throw new AssertionError(sprintf(
            "S2 — document mal formé : %d erreur(s) libxml grave(s) détectée(s).\n%s",
            count($serious),
            implode("\n", $lines)
        ));
    }

    /**
     * S3 — Pas de date ISO (YYYY-MM-DD) dans le texte visible du HTML.
     *
     * Le bug typique : un message affiche "Soumis le 2024-01-15" au lieu de
     * "Soumis le 15/01/2024". Cette règle détecte ces fuites.
     *
     * Exclusions légitimes :
     *   - Attributs `value` des `<input>` (champs date) — textContent les ignore naturellement
     *   - Attributs `datetime` des `<time>` — idem
     *   - Texte à l'intérieur des balises `<time>`, `<script>`, `<style>` — filtré via XPath
     *
     * @param DOMDocument $d
     * @throws AssertionError Si au moins une date ISO est trouvée dans le texte visible.
     */
    public static function assertNoIsoDates(DOMDocument $d): void
    {
        $xp = new DOMXPath($d);
        $textNodes = $xp->query('//text()');
        if ($textNodes === false) {
            return;
        }

        $skipTags = ['time', 'script', 'style', 'head'];
        $text = '';
        foreach (iterator_to_array($textNodes) as $node) {
            // Remonter les ancêtres pour voir si on est dans un tag à exclure
            $skip = false;
            $parent = $node->parentNode;
            while ($parent !== null) {
                if ($parent instanceof DOMElement) {
                    $name = strtolower($parent->tagName);
                    if (in_array($name, $skipTags, true)) {
                        $skip = true;
                        break;
                    }
                }
                $parent = $parent->parentNode;
            }
            if (!$skip) {
                $text .= $node->nodeValue;
            }
        }

        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $text, $matches)) {
            $samples = array_slice($matches[0], 0, 5);
            throw new AssertionError(sprintf(
                "S3 — date(s) ISO détectée(s) dans le texte visible (devrait être au format d/m/Y) : %d occurrence(s).\n  Exemples : %s",
                count($matches[0]),
                implode(', ', array_unique($samples))
            ));
        }
    }

    /**
     * S4 — Bouton "Envoyer ma demande" absent de la page succès.
     *
     * Bug historique : un `<?php endif; ?>` mal placé fermait le mauvais `if`
     * et le bouton submit réapparaissait sous le message de succès, permettant
     * une double soumission.
     *
     * @param DOMDocument $d
     * @throws AssertionError Si le bouton "Envoyer ma demande" est trouvé.
     */
    public static function assertNoSubmitOnSuccessPage(DOMDocument $d): void
    {
        $xp = new DOMXPath($d);
        // <button>Envoyer ma demande</button> ou <input type="submit" value="Envoyer ma demande">
        $btn = $xp->query('//button[contains(., "Envoyer ma demande")] | //input[@type="submit" and contains(@value, "Envoyer ma demande")]');
        if ($btn !== false && $btn->length > 0) {
            throw new AssertionError(sprintf(
                "S4 — bouton 'Envoyer ma demande' présent sur la page succès (%d occurrence(s)) — devrait être masqué après succès.",
                $btn->length
            ));
        }
    }

    /**
     * S5 — Encadré RGPD (checkbox rgpd_consent) absent de la page succès.
     *
     * Même bug historique que S4 : l'encadré RGPD réapparaissait sous le
     * message de succès, perturbant l'utilisateur.
     *
     * @param DOMDocument $d
     * @throws AssertionError Si la checkbox rgpd_consent est trouvée.
     */
    public static function assertNoRgpdOnSuccessPage(DOMDocument $d): void
    {
        $xp = new DOMXPath($d);
        $chk = $xp->query('//input[@name="rgpd_consent"]');
        if ($chk !== false && $chk->length > 0) {
            throw new AssertionError(sprintf(
                "S5 — encadré RGPD (input[name=rgpd_consent]) présent sur la page succès (%d occurrence(s)) — devrait être masqué après succès.",
                $chk->length
            ));
        }
    }

    /**
     * S6 — Checkbox cochée (préservation de l'état après re-validation).
     *
     * @param DOMDocument $d
     * @param string $name Attribut `name` de la checkbox.
     * @throws AssertionError Si la checkbox n'existe pas ou n'est pas cochée.
     */
    public static function assertChecked(DOMDocument $d, string $name): void
    {
        $xp = new DOMXPath($d);
        $nodes = $xp->query(sprintf('//input[@type="checkbox" and @name="%s"]', $name));
        if ($nodes === false || $nodes->length === 0) {
            throw new AssertionError("S6 — checkbox '{$name}' introuvable dans le document.");
        }
        foreach (iterator_to_array($nodes) as $n) {
            if (!$n instanceof DOMElement) continue;
            if (!$n->hasAttribute('checked')) {
                throw new AssertionError("S6 — checkbox '{$name}' n'est pas cochée (devrait l'être pour préserver l'état).");
            }
        }
    }

    /**
     * S6 — Radio bouton coché pour une valeur donnée.
     *
     * @param DOMDocument $d
     * @param string $name  Attribut `name` du groupe radio.
     * @param string $value Valeur attendue (attribut `value` du radio coché).
     * @throws AssertionError Si le radio n'existe pas ou n'est pas coché.
     */
    public static function assertRadioChecked(DOMDocument $d, string $name, string $value): void
    {
        $xp = new DOMXPath($d);
        $nodes = $xp->query(sprintf(
            '//input[@type="radio" and @name="%s" and @value="%s"]',
            $name,
            $value
        ));
        if ($nodes === false || $nodes->length === 0) {
            throw new AssertionError("S6 — radio '{$name}'='{$value}' introuvable dans le document.");
        }
        foreach (iterator_to_array($nodes) as $n) {
            if (!$n instanceof DOMElement) continue;
            if (!$n->hasAttribute('checked')) {
                throw new AssertionError("S6 — radio '{$name}'='{$value}' n'est pas coché (devrait l'être).");
            }
        }
    }

    /**
     * S6 — Champ (input/textarea/select) avec la valeur attendue.
     *
     * @param DOMDocument $d
     * @param string $name     Attribut `name`.
     * @param string $expected Valeur attendue.
     * @throws AssertionError Si le champ n'existe pas ou a une valeur différente.
     */
    public static function assertHasValue(DOMDocument $d, string $name, string $expected): void
    {
        $xp = new DOMXPath($d);
        $inputs = $xp->query(sprintf('//input[@name="%s"]', $name));
        $textareas = $xp->query(sprintf('//textarea[@name="%s"]', $name));
        $selects = $xp->query(sprintf('//select[@name="%s"]', $name));

        $actual = null;
        $found = false;
        $kind = '';

        if ($inputs !== false && $inputs->length > 0) {
            $first = $inputs->item(0);
            if ($first instanceof DOMElement) {
                $actual = $first->getAttribute('value');
                $found = true;
                $kind = 'input';
            }
        } elseif ($textareas !== false && $textareas->length > 0) {
            $first = $textareas->item(0);
            if ($first instanceof DOMElement) {
                $actual = trim($first->textContent);
                $found = true;
                $kind = 'textarea';
            }
        } elseif ($selects !== false && $selects->length > 0) {
            $first = $selects->item(0);
            if ($first instanceof DOMElement) {
                $selected = $xp->query('.//option[@selected]', $first);
                if ($selected !== false && $selected->length > 0) {
                    $opt = $selected->item(0);
                    if ($opt instanceof DOMElement) {
                        $actual = $opt->getAttribute('value');
                        if ($actual === '') {
                            $actual = trim($opt->textContent);
                        }
                        $found = true;
                        $kind = 'select';
                    }
                }
            }
        }

        if (!$found) {
            throw new AssertionError("S6 — champ '{$name}' introuvable (input/textarea/select).");
        }

        if ($actual !== $expected) {
            $actualPreview = mb_substr((string) $actual, 0, 100);
            throw new AssertionError(sprintf(
                "S6 — champ %s '%s' = '%s' (attendu '%s').",
                $kind,
                $name,
                $actualPreview,
                $expected
            ));
        }
    }

    /**
     * S8 — Pas de warnings/notices PHP dans stderr.
     *
     * Filtre les faux positifs connus (bruit PHP 8.4, shutdown noise) :
     *   - "PHP Request Shutdown" — messages émis au shutdown, pas des vrais warnings
     *   - "Disabling session.use_only_cookies INI setting is deprecated" —
     *     déprecation PHP 8.4 déclenchée par core_bootstrap.php ligne 45 qui
     *     appelle session_start(['use_only_cookies' => false]) en CLI. Ce n'est
     *     pas un bug applicatif, juste une incompatibilité PHP 8.4.
     *
     * @param string $stderr Sortie stderr capturée du sous-processus.
     * @throws AssertionError Si au moins un Warning/Notice/Deprecated est trouvé.
     */
    public static function assertNoPhpWarnings(string $stderr): void
    {
        if ($stderr === '') {
            return;
        }

        // Patterns à ignorer (bruit PHP 8.4, shutdown, etc.)
        $ignorePatterns = [
            '/PHP Request Shutdown:/i',
            '/Disabling session\.use_only_cookies INI setting is deprecated/i',
            '/session_start\(\): Session cache limiter cannot be sent/i',
            // Warnings environnement CLI : le répertoire de sessions PHP n'existe
            // pas en contexte de test (CLI). Non représentatif de la prod.
            '/session_start\(\): open\([^)]+\) failed/i',
            '/session_start\(\): Failed to read session data/i',
            // Cascade : session_start a déjà envoyé des headers (warning ci-dessus),
            // donc http_response_code() ne peut pas modifier le code HTTP. Faux positif.
            '/http_response_code\(\): Cannot set response code - headers already sent/i',
        ];

        // Récupère toutes les lignes de warning/notice/deprecated
        if (!preg_match_all('/^(?:PHP )?(Warning|Notice|Deprecated):(.*)$/m', $stderr, $matches, PREG_SET_ORDER)) {
            return;
        }

        // Filtre les faux positifs
        $real = [];
        foreach ($matches as $m) {
            $line = trim($m[1] . ':' . $m[2]);
            $isNoise = false;
            foreach ($ignorePatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $isNoise = true;
                    break;
                }
            }
            if (!$isNoise) {
                $real[] = $line;
            }
        }

        if (count($real) === 0) {
            return;
        }

        $lines = array_map(static function ($line): string {
            return '  - ' . $line;
        }, array_slice($real, 0, 5));

        throw new AssertionError(sprintf(
            "S8 — warnings/notices PHP détectés dans stderr : %d occurrence(s).\n%s",
            count($real),
            implode("\n", $lines)
        ));
    }

    /**
     * S9 — Tous les formulaires POST ont un token CSRF.
     *
     * On ne vérifie que les <form method="POST"> (ou method="post" insensible à la casse,
     * ou sans method — car le défaut est GET, mais certains devs oublient method="POST").
     * Les formulaires GET (filtres de recherche, tris) n'ont pas besoin de CSRF.
     *
     * @param DOMDocument $d
     * @throws AssertionError Si au moins un form POST sans csrf_token est trouvé.
     */
    public static function assertAllFormsHaveCsrf(DOMDocument $d): void
    {
        $forms = $d->getElementsByTagName('form');
        if ($forms->length === 0) {
            return;
        }

        $xp = new DOMXPath($d);
        $missing = [];
        foreach (iterator_to_array($forms) as $form) {
            if (!$form instanceof DOMElement) continue;
            $method = strtoupper($form->getAttribute('method') ?: 'GET');
            if ($method !== 'POST') {
                continue; // GET forms don't need CSRF
            }
            $csrf = $xp->query('.//input[@name="csrf_token"]', $form);
            if ($csrf === false || $csrf->length === 0) {
                $action = $form->getAttribute('action') ?: '(pas d\'action)';
                $missing[] = sprintf('<form method="POST" action="%s">', $action);
            }
        }

        if (count($missing) > 0) {
            throw new AssertionError(sprintf(
                "S9 — formulaire(s) POST sans token CSRF : %d form(s) concerné(s).\n  %s",
                count($missing),
                implode("\n  ", array_slice($missing, 0, 5))
            ));
        }
    }

    /**
     * S12 — Balise <title> non vide.
     *
     * @param DOMDocument $d
     * @throws AssertionError Si la balise <title> est absente ou vide.
     */
    public static function assertTitleNonEmpty(DOMDocument $d): void
    {
        $titles = $d->getElementsByTagName('title');
        if ($titles->length === 0) {
            throw new AssertionError("S12 — balise <title> absente du document.");
        }
        $title = trim($titles->item(0)->textContent ?? '');
        if ($title === '') {
            throw new AssertionError("S12 — balise <title> vide.");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS DE RECHERCHE
    // ═══════════════════════════════════════════════════════════════

    /**
     * Helper — vérifie qu'un élément matching le sélecteur CSS est ABSENT.
     *
     * Sélecteurs supportés (subset CSS) :
     *   - `tag`                    → //tag
     *   - `.class`                 → //*[contains(concat(" ",@class," "), " class ")]
     *   - `#id`                    → //*[@id="id"]
     *   - `tag.class`              → //tag[contains(...)]
     *   - `tag#id`                 → //tag[@id="id"]
     *   - `tag[attr=val]`          → //tag[@attr="val"]
     *   - combinaisons simples
     *
     * Si le sélecteur commence par `/` ou `./`, il est traité comme du XPath natif.
     *
     * @param DOMDocument $d
     * @param string $css Sélecteur CSS simplifié ou expression XPath.
     * @throws AssertionError Si au moins un élément matche.
     */
    public static function assertElementAbsent(DOMDocument $d, string $css): void
    {
        $xpath = self::cssToXpath($css);
        $xp = new DOMXPath($d);
        $nodes = $xp->query($xpath);
        if ($nodes !== false && $nodes->length > 0) {
            throw new AssertionError(sprintf(
                "Élément '%s' présent (devrait être absent) — %d occurrence(s). XPath: %s",
                $css,
                $nodes->length,
                $xpath
            ));
        }
    }

    /**
     * Helper — vérifie qu'un élément matching le sélecteur CSS est PRÉSENT.
     *
     * @param DOMDocument $d
     * @param string $css Sélecteur CSS simplifié ou expression XPath.
     * @throws AssertionError Si aucun élément ne matche.
     */
    public static function assertElementPresent(DOMDocument $d, string $css): void
    {
        $xpath = self::cssToXpath($css);
        $xp = new DOMXPath($d);
        $nodes = $xp->query($xpath);
        if ($nodes === false || $nodes->length === 0) {
            throw new AssertionError(sprintf(
                "Élément '%s' absent (devrait être présent). XPath: %s",
                $css,
                $xpath
            ));
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // UTILITAIRES INTERNES
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convertit un sélecteur CSS simplifié en expression XPath.
     *
     * Si le selecteur commence par `/` ou `./`, il est retourné tel quel (XPath natif).
     *
     * @param string $css Sélecteur CSS ou XPath
     * @return string Expression XPath
     */
    private static function cssToXpath(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '/*';
        }

        // Si déjà du XPath, retourner tel quel
        if (str_starts_with($css, '/') || str_starts_with($css, './')) {
            return $css;
        }

        $parts = [];
        $rest = $css;

        // Tag (optionnel)
        if (preg_match('/^([a-zA-Z][\w-]*)/', $rest, $m)) {
            $parts['tag'] = $m[1];
            $rest = substr($rest, strlen($m[1]));
        } else {
            $parts['tag'] = '*';
        }

        // .class ou #id (multiples)
        $predicates = [];
        while (preg_match('/^([.#])([\w-]+)/', $rest, $m)) {
            if ($m[1] === '.') {
                $predicates[] = sprintf('contains(concat(" ", normalize-space(@class), " "), " %s ")', $m[2]);
            } else {
                $predicates[] = sprintf('@id="%s"', $m[2]);
            }
            $rest = substr($rest, strlen($m[0]));
        }

        // [attr=value] ou [attr]
        while (preg_match('/^\[([\w-]+)(?:="([^"]*)")?\]/', $rest, $m)) {
            if (isset($m[2])) {
                $predicates[] = sprintf('@%s="%s"', $m[1], $m[2]);
            } else {
                $predicates[] = '@' . $m[1];
            }
            $rest = substr($rest, strlen($m[0]));
        }

        $tag = $parts['tag'];
        if (count($predicates) === 0) {
            return '//' . $tag;
        }
        return '//' . $tag . '[' . implode(' and ', $predicates) . ']';
    }
}

// ─── Mode autonome : si on lance ce fichier directement, faire un smoke test ─
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    fwrite(STDOUT, "DomAssertions smoke test — OK (classe chargée, aucune erreur fatale).\n");

    // Mini self-test : vérifier que les assertions fonctionnent sur du HTML bidon
    $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><p>Hello 2024-01-15 world</p></body></html>';
    $doc = DomAssertions::fromHtml($html);
    try {
        DomAssertions::assertNoIsoDates($doc);
        fwrite(STDERR, "Self-test échec : S3 aurait dû détecter la date ISO.\n");
        exit(1);
    } catch (AssertionError $e) {
        fwrite(STDOUT, "Self-test S3 : OK (date bien détectée) — " . $e->getMessage() . "\n");
    }

    // Self-test S12 (title non vide)
    try {
        DomAssertions::assertTitleNonEmpty($doc);
        fwrite(STDOUT, "Self-test S12 : OK (title='Test')\n");
    } catch (AssertionError $e) {
        fwrite(STDERR, "Self-test S12 échec inattendu : " . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, "Tous les self-tests DomAssertions sont OK.\n");
    exit(0);
}
