<?php
declare(strict_types=1);

use App\Enum\FieldType;
use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Validation\RecipientAddress;

// Source de vérité unique des groupes `card_group` du seed (aucune dépendance à
// l'autoload : même disponibilité que les autres fichiers de migration).
require_once __DIR__ . '/form_field_groups.php';

/**
 * v47 — durcissement des ordres et des destinataires.
 *
 * Domaines couverts :
 *  - `form_fields.ordre` : NOT NULL, entier, >= 0 ;
 *  - `steps.ordre`       : entier positif (> 0) ;
 *  - `step_recipients.email` : email classique ou référence `{{...}}`, jamais
 *    vide ni tronquée.
 *
 * `form_fields` : trois rotations sont reconnues et réparées de façon
 * déterministe (voir le préflight) :
 *  - signature A, `required` porte l'ancien ordre (entier >= 2) ;
 *  - signature B, observée en production, `required` reste un booléen 0/1,
 *    `ordre` porte le nom du groupe, `card_group` l'ancien hint et `hint` est
 *    vide ;
 *  - signature C (rotation partielle), `required` reste 0/1 et `ordre` reste
 *    la position entière réelle de la ligne, mais `card_group` porte l'ancien
 *    hint et `hint` est vide : seul le segment `card_group`/`hint` a tourné.
 *    Le groupe réel n'est jamais deviné : il doit être prouvé par le contexte
 *    du même formulaire (voir la passe de résolution), sinon la ligne bloque.
 * Toute autre combinaison reste bloquante : aucune réparation silencieuse,
 * aucun ID perdu.
 *
 * Cause historique : le dépôt contient des reconstructions positionnelles
 * `INSERT INTO ... SELECT *` (v43/v44 au moment de v47, v30/v31/v33 avant le
 * squash). Elles constituent un mécanisme réel de permutation dès que l'ordre
 * physique de la table déployée diffère de la table reconstruite. L'origine
 * exacte de la rotation observée en production n'est pas prouvée par
 * l'historique disponible (le premier commit du dépôt porte déjà l'ordre
 * canonique) : v47 ne revendique donc pas une cause racine unique et conserve
 * la détection + renumérotation explicites décidées, tout en supprimant le
 * risque positionnel des rebuilds (listes de colonnes explicites).
 *
 * Portée des CHECK : elles expriment des domaines (type/valeur), pas une
 * sémantique de colonne. Une valeur légale placée dans la mauvaise colonne
 * (ex. `ordre = 0` vs `required = 0`) reste légalement valide ; une
 * permutation sémantique est détectée par le préflight, pas par SQLite.
 *
 * `step_recipients` ne possède pas de colonne `ordre` dans le schéma courant :
 * ce domaine est donc durci sur le destinataire, pas sur un ordre inventé.
 *
 * Réparation : seules les valeurs tronquées documentées de
 * `RecipientAddress::KNOWN_TRUNCATED` sont corrigées, et uniquement si le
 * libellé de l'étape correspond exactement à la correspondance documentée.
 * Toute autre valeur invalide bloque la migration avant le moindre BEGIN :
 * aucune normalisation silencieuse, aucun ID perdu.
 *
 * Le rebuild des trois tables est atomique (BEGIN IMMEDIATE, FK hors
 * transaction comme v43/v44/v46), avec contrôle des comptes de lignes et du
 * schéma après reconstruction.
 *
 * Renumérotation : `form_fields.ordre` n'est jamais réécrit sur la table en
 * place. Les valeurs réparées sont injectées via une table TEMP (`v47_field_fixes`)
 * puis projetées par jointure dans `form_fields_new` (table neuve, donc vide) au
 * moment du rebuild. Cette stratégie est indépendante de tout index
 * `(form_id, ordre)` : une base déployée pourrait en porter un (créé hors dépôt)
 * qu'une suite d'`UPDATE` ligne à ligne violerait en cours de permutation
 * (ex. signature B : la ligne à l'ordre 3 reçoit 1 alors que la ligne à l'ordre 1
 * n'a pas encore reçu 2).
 *
 * Colonnes à liste fermée (`field_type`, `filled_by`, `visibility`) : le
 * préflight les compare aux enums `App\Enum\*` AVANT le moindre BEGIN et
 * remonte colonne + ID + valeur. Les CHECK du rebuild en sont le miroir SQL
 * (SQLite ne lit pas un enum) : sans ce préflight, une valeur héritée invalide
 * ne produirait qu'un « CHECK constraint failed » générique au milieu du
 * rebuild, sans ID.
 */
function apply_migration_v47(PDO $pdo, int $current_version): int
{
    if ($current_version >= 47 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 47');
    if ($done === false) {
        throw new RuntimeException('v47: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 47);
    }

    // ── Préflight strict : ordre des étapes (entier > 0) ──
    $invalid = [];
    $rows = $pdo->query(
        "SELECT id FROM steps WHERE ordre IS NULL OR typeof(ordre) <> 'integer' OR ordre < 1 LIMIT 10"
    );
    if ($rows === false) {
        throw new RuntimeException('v47: steps.ordre preflight query failed');
    }
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $invalid[] = 'steps.ordre id=' . $id;
    }
    $rows = null;

    // ── Préflight strict : colonnes à liste fermée ──
    // Source de vérité PHP = les enums `App\Enum\*` (règle « aucune liste de
    // valeurs recopiée »). Les CHECK du rebuild en sont le miroir SQL. Sans ce
    // préflight, une valeur héritée invalide remonterait en
    // « CHECK constraint failed » au milieu du rebuild, sans colonne ni ID.
    $invalid = array_merge(
        $invalid,
        v47_off_list_values($pdo, 'field_type', 'form_fields.field_type', v47_enum_values(FieldType::cases())),
        v47_off_list_values($pdo, 'filled_by', 'form_fields.filled_by', v47_enum_values(FilledBy::cases())),
        v47_off_list_values($pdo, 'visibility', 'form_fields.visibility', v47_enum_values(FieldVisibility::cases())),
    );

    // ── Préflight form_fields + réparation des rotations connues ──
    // Signature A (rotation legacy par position, `required` = ancien ordre) :
    //   required   = ancien ordre (entier >= 2)
    //   ordre      = ancien card_group (0/vide si défaut, sinon texte du groupe)
    //   card_group = ancien hint (vide, ou l'un des textes de hint documentés)
    //   hint       = ''
    //
    // Signature B (rotation réelle observée en production, `required` = 0/1) :
    //   required   = 0 ou 1 (booléen métier conservé)
    //   ordre      = nom du groupe (`Identité de l'agent`, `Informatique (IT)`, ...)
    //   card_group = ancien hint (vide, documenté, ou texte libre non reconnu
    //                comme groupe)
    //   hint       = ''
    //
    // La signature B n'est acceptée que si le texte d'`ordre` est un groupe
    // suffisamment établi : groupe documenté du seed, groupe déjà observé dans
    // un `card_group` sain, ou texte répété sur au moins deux lignes. Un texte
    // isolé et inconnu reste ambigu et bloque. Un `card_group` qui est lui-même
    // un groupe établi bloque également : deux groupes dans la même ligne sont
    // ambigus.
    //
    // Signature C (rotation partielle, observée en production) :
    //   required   = 0 ou 1 (booléen métier intact)
    //   ordre      = position entière réelle >= 1 (intacte)
    //   card_group = ancien hint (uniquement un texte de `legacyHintGroups`)
    //   hint       = ''
    // Seuls `card_group` et `hint` ont tourné d'un cran. Le groupe réel n'est
    // PAS contenu dans la ligne : il est déduit du contexte du même formulaire,
    // et seulement s'il est prouvé. Deux preuves acceptées :
    //   (1) encadrement : la ligne saine la plus proche avant et la plus proche
    //       après l'`ordre` observé portent le MÊME groupe établi ;
    //   (2) unanimité : toutes les lignes saines du formulaire partagent un
    //       unique groupe établi.
    // Aucun hint->groupe n'est codé en dur (ce serait une supposition, pas une
    // preuve), et un `card_group` qui n'est pas un hint documenté n'entre jamais
    // dans cette signature. Sans preuve : fail-closed avec les groupes
    // candidats (`before`, `after`, `form_groups`) dans le diagnostic.
    //
    // Réparations déterministes :
    //   signature A : card_group := ordre (0/vide -> 'Général'),
    //                 hint := card_group, required := 0,
    //                 ordre := séquentiel après le plus grand ordre sain.
    //   signature B : card_group := ordre, hint := card_group actuel,
    //                 required := 0/1 conservé,
    //                 ordre := 1..N pour tout le formulaire (groupes ordonnés
    //                 par première apparition, lignes d'un groupe par ordre
    //                 source puis rowid).
    //   signature C : card_group := groupe prouvé par le contexte du formulaire,
    //                 hint := card_group actuel, required := 0/1 conservé,
    //                 ordre := position observée conservée (renumérotée 1..N
    //                 seulement si le même formulaire porte aussi une
    //                 signature B).
    // Toute autre combinaison (hint non vide, `ordre` numérique positif, texte
    // d'ordre non établi, card_group non documenté, signature C sans groupe
    // prouvable) est fail-closed : aucune réparation silencieuse.
    $rotations = [];
    /** @var list<array{rowid:int,id:string,form_id:string,old_ordre:int,hint_text:string,group_text:string}> $repairable */
    $repairable = [];
    /** @var list<array{rowid:int,id:string,form_id:string,required:int,hint_text:string,group_text:string}> $groupRepairable */
    $groupRepairable = [];
    /** @var list<array{rowid:int,id:string,form_id:string,required:int,ordre:int,card_group:string}> $partialHintCandidates */
    $partialHintCandidates = [];
    /** @var list<array{rowid:int,id:string,form_id:string,required:int,ordre:int,hint_text:string,group:string}> $hintRepairs */
    $hintRepairs = [];
    /**
     * @var array<string, list<array{
     *   rowid:int,
     *   id:string,
     *   required:int|null,
     *   ordre:int|string,
     *   card_group:string,
     *   hint:string,
     *   kind:string,
     *   group_key:string,
     *   source_rank:int|null
     * }>> $allRowsByForm
     */
    $allRowsByForm = [];
    $legacyHintGroups = [
        'Préciser les droits ou le rôle valideur le cas échéant',
        'Préciser les applications métiers nécessaires',
        'Préciser les répertoires et droits attendus',
        'Adresse email du service chargé de compléter les informations d\'affectation',
        'Site et numéro de bureau',
        'Adresse email personnelle de l\'agent',
        'Cocher si du mobilier est nécessaire',
        'À compléter si du mobilier est demandé',
        'Matériel de base, à prévoir systématiquement',
        'Matériel attribué par défaut',
        'Préciser les autres équipements nécessaires',
    ];
    // Groupes documentés du seed, via la source partagée
    // `classes/migrations/form_field_groups.php` (également consommée par
    // `seed_default_forms.php` et surveillée par
    // `scripts/check_form_field_groups.php` en CI). La liste reste fermée : un
    // texte isolé hors de ces groupes (et sans preuve par répétition) ne doit
    // jamais être converti.
    $legacyGroups = default_form_field_card_groups();
    $rows = $pdo->query(
        'SELECT rowid AS rid, id, form_id, required, ordre, card_group, hint '
        . 'FROM form_fields ORDER BY form_id, rowid'
    );
    if ($rows === false) {
        throw new RuntimeException('v47: form_fields rotation preflight query failed');
    }
    $fieldRows = $rows->fetchAll(PDO::FETCH_ASSOC);
    $rows = null;

    // Groupes établis par les données : un `card_group` sain (ordre entier) ou
    // un texte d'`ordre` répété au moins deux fois. Cela couvre les groupes
    // personnalisés multi-champs sans accepter un texte isolé arbitraire.
    $knownGroups = array_fill_keys($legacyGroups, true);
    $ordreTextSeen = [];
    foreach ($fieldRows as $row) {
        $ordreRaw = $row['ordre'];
        $validOrdre = (is_int($ordreRaw) && $ordreRaw >= 0)
            || (is_string($ordreRaw) && preg_match('/^\d+$/', $ordreRaw) === 1);
        if ($validOrdre) {
            $group = trim((string) ($row['card_group'] ?? ''));
            if ($group !== '' && !in_array($group, $legacyHintGroups, true)) {
                $knownGroups[$group] = true;
            }
            continue;
        }
        if (is_string($ordreRaw)) {
            $text = trim($ordreRaw);
            if ($text !== '' && preg_match('/^-?\d+$/', $text) !== 1) {
                $ordreTextSeen[$text] = ($ordreTextSeen[$text] ?? 0) + 1;
            }
        }
    }
    foreach ($ordreTextSeen as $text => $count) {
        if ($count >= 2 && !in_array($text, $legacyHintGroups, true)) {
            $knownGroups[$text] = true;
        }
    }

    foreach ($fieldRows as $row) {
        $required = $row['required'];
        $requiredInt = is_int($required)
            ? $required
            : (is_string($required) && preg_match('/^-?\d+$/', $required) === 1 ? (int) $required : null);

        $ordreRaw = $row['ordre'];
        $ordreZero = $ordreRaw === 0 || $ordreRaw === '0';
        $ordreDefault = $ordreZero || (is_string($ordreRaw) && trim($ordreRaw) === '');
        $ordreText = is_string($ordreRaw)
            && trim($ordreRaw) !== ''
            && preg_match('/^-?\d+$/', trim($ordreRaw)) !== 1;
        $ordreTextValue = $ordreText ? trim((string) $ordreRaw) : '';

        // Position entière utilisable (0 = défaut historique, donc pas une
        // position) ; sert uniquement à la signature C, qui conserve l'`ordre`
        // réel de la ligne.
        $ordreInt = null;
        if (is_int($ordreRaw) && $ordreRaw >= 0) {
            $ordreInt = $ordreRaw;
        } elseif (is_string($ordreRaw) && preg_match('/^\d+$/', trim($ordreRaw)) === 1) {
            $ordreInt = (int) trim($ordreRaw);
        }

        $cardGroup = trim((string) ($row['card_group'] ?? ''));
        $hint = trim((string) ($row['hint'] ?? ''));

        $invalidRequired = $requiredInt === null
            || $requiredInt < 0
            || ($requiredInt !== 0 && $requiredInt !== 1);
        $knownHintInCardGroup = $hint === '' && in_array($cardGroup, $legacyHintGroups, true);
        $cardGroupIsEstablishedGroup = $cardGroup !== '' && isset($knownGroups[$cardGroup]);
        $cardGroupIsHint = $cardGroup === '' || !$cardGroupIsEstablishedGroup;
        $exactRotation = $requiredInt !== null
            && $requiredInt >= 2
            && ($ordreDefault || $ordreText)
            && $hint === ''
            && ($cardGroup === '' || $knownHintInCardGroup);
        $groupRotation = $requiredInt !== null
            && ($requiredInt === 0 || $requiredInt === 1)
            && $ordreText
            && isset($knownGroups[$ordreTextValue])
            && $hint === ''
            && $cardGroupIsHint;

        // Signature C (rotation partielle) : seul le segment `card_group`/`hint`
        // a tourné. `required` (0/1) et `ordre` (position entière >= 1) portent
        // encore leurs valeurs métier, `card_group` contient un hint documenté
        // et `hint` est vide. La réparation dépend du groupe réel, prouvé par
        // le contexte du formulaire dans la passe de résolution.
        $partialHintRotation = $requiredInt !== null
            && ($requiredInt === 0 || $requiredInt === 1)
            && $ordreInt !== null
            && $ordreInt >= 1
            && $knownHintInCardGroup;

        $meta = [
            'rowid' => (int) $row['rid'],
            'id' => (string) $row['id'],
            'required' => $requiredInt,
            'ordre' => is_int($ordreRaw) ? $ordreRaw : (is_string($ordreRaw) ? $ordreRaw : ''),
            'card_group' => $cardGroup,
            'hint' => $hint,
            'kind' => 'healthy',
            'group_key' => $cardGroup === '' ? 'Général' : $cardGroup,
            'source_rank' => null,
        ];

        if ($exactRotation) {
            $meta['kind'] = 'old';
            $meta['group_key'] = $ordreDefault ? 'Général' : $ordreTextValue;
            $meta['source_rank'] = $requiredInt;
            $repairable[] = [
                'rowid' => (int) $row['rid'],
                'id' => (string) $row['id'],
                'form_id' => (string) $row['form_id'],
                'old_ordre' => (int) $requiredInt,
                'hint_text' => $cardGroup,
                'group_text' => $ordreDefault ? '' : $ordreTextValue,
            ];
        } elseif ($groupRotation) {
            $meta['kind'] = 'group';
            $meta['group_key'] = $ordreTextValue;
            $groupRepairable[] = [
                'rowid' => (int) $row['rid'],
                'id' => (string) $row['id'],
                'form_id' => (string) $row['form_id'],
                'required' => (int) $requiredInt,
                'hint_text' => $cardGroup,
                'group_text' => $ordreTextValue,
            ];
        } elseif ($partialHintRotation) {
            $meta['kind'] = 'hint_card';
            $meta['source_rank'] = $ordreInt;
            // Groupe réel inconnu à ce stade : résolu par contexte plus bas.
            $meta['group_key'] = '';
            $partialHintCandidates[] = [
                'rowid' => (int) $row['rid'],
                'id' => (string) $row['id'],
                'form_id' => (string) $row['form_id'],
                'required' => $requiredInt === null ? 0 : $requiredInt,
                'ordre' => $ordreInt === null ? 0 : $ordreInt,
                'card_group' => $cardGroup,
            ];
        } else {
            $validOrdre = (is_int($ordreRaw) && $ordreRaw >= 0)
                || (is_string($ordreRaw) && preg_match('/^\d+$/', $ordreRaw) === 1);
            if ($validOrdre) {
                $meta['source_rank'] = (int) $ordreRaw;
            } elseif ($ordreText) {
                $reason = 'ordre text is not an established group';
                if (isset($knownGroups[$ordreTextValue])) {
                    if ($hint !== '') {
                        $reason = 'hint is not empty';
                    } elseif ($cardGroupIsEstablishedGroup) {
                        $reason = 'card_group is an established group, not a hint';
                    } elseif ($invalidRequired) {
                        $reason = 'required is not 0/1';
                    }
                }
                $rotations[] = 'id=' . $row['id']
                    . ' required=' . json_encode($required)
                    . ' ordre=' . json_encode($ordreRaw, JSON_UNESCAPED_UNICODE)
                    . ' card_group=' . json_encode($cardGroup, JSON_UNESCAPED_UNICODE)
                    . ' hint=' . json_encode($hint, JSON_UNESCAPED_UNICODE)
                    . ' reason=' . $reason;
            } else {
                $invalid[] = 'form_fields.ordre id=' . $row['id']
                    . ' value=' . json_encode($ordreRaw);
            }

            if ($invalidRequired) {
                $invalid[] = 'form_fields.required id=' . $row['id']
                    . ' value=' . json_encode($required)
                    . ($ordreZero ? ' (possible ordre déplacé)' : '');
            }

            if ($knownHintInCardGroup && !$ordreText) {
                $reasonDetail = $ordreInt === null || $ordreInt < 1
                    ? 'ordre is not a positive integer position'
                    : 'required is not 0/1';
                $rotations[] = 'id=' . $row['id']
                    . ' required=' . json_encode($required)
                    . ' ordre=' . json_encode($ordreRaw)
                    . ' card_group=' . json_encode($cardGroup, JSON_UNESCAPED_UNICODE)
                    . ' reason=known hint in card_group without the full signature ('
                    . $reasonDetail . ')';
            }

            // Ambiguïté restante : `card_group` porte un hint documenté alors
            // que `hint` est déjà rempli → deux hints dans la même ligne, donc
            // ni ligne saine, ni signature C (qui exige `hint=''`), ni hint
            // récupérable. Aucune devinette : fail-closed.
            if ($hint !== '' && !$ordreText && in_array($cardGroup, $legacyHintGroups, true)) {
                $rotations[] = 'id=' . $row['id']
                    . ' required=' . json_encode($required)
                    . ' ordre=' . json_encode($ordreRaw)
                    . ' card_group=' . json_encode($cardGroup, JSON_UNESCAPED_UNICODE)
                    . ' hint=' . json_encode($hint, JSON_UNESCAPED_UNICODE)
                    . ' reason=known hint in card_group while hint is not empty (two hints in one row)';
            }
        }

        $allRowsByForm[(string) $row['form_id']][] = $meta;
    }

    // ── Signature C : résolution du groupe réel, uniquement par preuve ──
    // Le groupe n'est jamais déduit du hint observé (aucun mapping codé en
    // dur) : il vient du contexte du MÊME formulaire, et seulement si ce
    // contexte est univoque. Les candidats sont déjà retirés des sources
    // (kind `hint_card`), les lignes saines d'un `card_group` documenté ne
    // sont pas des sources (un hint n'est pas un groupe).
    foreach ($partialHintCandidates as $candidate) {
        $formId = $candidate['form_id'];
        $sources = [];
        foreach ($allRowsByForm[$formId] ?? [] as $meta) {
            $rank = $meta['source_rank'];
            if ($meta['kind'] !== 'healthy' || !is_int($rank) || $rank < 0) {
                continue;
            }
            $group = $meta['card_group'];
            if ($group === '' || !isset($knownGroups[$group])) {
                continue;
            }
            $sources[] = ['ordre' => $rank, 'group' => $group];
        }

        $before = null;
        $after = null;
        foreach ($sources as $source) {
            if ($source['ordre'] < $candidate['ordre']) {
                if ($before === null || $source['ordre'] > $before['ordre']) {
                    $before = $source;
                }
            } elseif ($source['ordre'] > $candidate['ordre']) {
                if ($after === null || $source['ordre'] < $after['ordre']) {
                    $after = $source;
                }
            }
        }

        $formGroups = [];
        foreach ($sources as $source) {
            if (!in_array($source['group'], $formGroups, true)) {
                $formGroups[] = $source['group'];
            }
        }

        $bracketedGroup = $before !== null
            && $after !== null
            && $before['group'] === $after['group']
            ? $before['group']
            : null;
        $unanimousGroup = count($formGroups) === 1 ? $formGroups[0] : null;
        $provenGroup = $bracketedGroup ?? $unanimousGroup;

        if ($provenGroup === null) {
            $rotations[] = 'id=' . $candidate['id']
                . ' required=' . json_encode($candidate['required'])
                . ' ordre=' . json_encode($candidate['ordre'])
                . ' card_group=' . json_encode($candidate['card_group'], JSON_UNESCAPED_UNICODE)
                . ' hint=' . json_encode('', JSON_UNESCAPED_UNICODE)
                . ' reason=known hint in card_group, real group not provable from form context'
                . ' before=' . json_encode($before === null ? null : $before['group'], JSON_UNESCAPED_UNICODE)
                . ' after=' . json_encode($after === null ? null : $after['group'], JSON_UNESCAPED_UNICODE)
                . ' form_groups=' . json_encode($formGroups, JSON_UNESCAPED_UNICODE);
            continue;
        }

        foreach ($allRowsByForm[$formId] as $index => $meta) {
            if ($meta['rowid'] !== $candidate['rowid']) {
                continue;
            }
            $allRowsByForm[$formId][$index]['group_key'] = $provenGroup;
        }

        $hintRepairs[] = [
            'rowid' => $candidate['rowid'],
            'id' => $candidate['id'],
            'form_id' => $formId,
            'required' => $candidate['required'],
            'ordre' => $candidate['ordre'],
            'hint_text' => $candidate['card_group'],
            'group' => $provenGroup,
        ];
    }

    // ── Ordre de renumérotation (déterministe et documenté) ──
    // Signature A : les lignes réparées sont triées par `required` ASC (ancien
    // ordre métier conservé), puis `rowid`, et reçoivent des ordres contigus
    // strictement supérieurs au plus grand `ordre` sain du formulaire.
    //
    // Signature B : le formulaire entier est renuméroté 1..N. Les groupes sont
    // ordonnés par première apparition (rowid minimal), puis les lignes d'un
    // même groupe par ordre source (`ordre` sain ou ancien ordre de la
    // signature A), et enfin par rowid. Cela rend l'ordre indépendant des
    // collisions et stable entre deux exécutions.
    $groupFormIds = [];
    foreach ($groupRepairable as $candidate) {
        $groupFormIds[$candidate['form_id']] = true;
    }

    /**
     * @var list<array{
     *   rowid:int,
     *   id:string,
     *   form_id:string,
     *   repaired:bool,
     *   required:int,
     *   hint:string,
     *   card_group:string,
     *   ordre:int
     * }> $assignments
     */
    $assignments = [];

    foreach (array_keys($groupFormIds) as $formIdKey) {
        $formId = (string) $formIdKey;
        $rowsForForm = $allRowsByForm[$formId] ?? [];
        if ($rowsForForm === []) {
            throw new RuntimeException('v47: group rotation rows missing for form ' . $formId);
        }

        $groupFirstRowid = [];
        foreach ($rowsForForm as $meta) {
            $group = $meta['group_key'];
            if (!isset($groupFirstRowid[$group]) || $meta['rowid'] < $groupFirstRowid[$group]) {
                $groupFirstRowid[$group] = $meta['rowid'];
            }
        }

        /**
         * @var list<array{
         *   group_first:int,
         *   rank:int,
         *   rowid:int,
         *   meta:array{
         *     rowid:int,
         *     id:string,
         *     required:int|null,
         *     ordre:int|string,
         *     card_group:string,
         *     hint:string,
         *     kind:string,
         *     group_key:string,
         *     source_rank:int|null
         *   }
         * }> $sortable
         */
        $sortable = [];
        foreach ($rowsForForm as $meta) {
            $sortable[] = [
                'group_first' => $groupFirstRowid[$meta['group_key']],
                'rank' => $meta['source_rank'] ?? PHP_INT_MAX,
                'rowid' => $meta['rowid'],
                'meta' => $meta,
            ];
        }
        usort(
            $sortable,
            static fn(array $a, array $b): int => [$a['group_first'], $a['rank'], $a['rowid']]
                <=> [$b['group_first'], $b['rank'], $b['rowid']]
        );

        $ordre = 1;
        foreach ($sortable as $entry) {
            $meta = $entry['meta'];
            $repaired = $meta['kind'] !== 'healthy';
            $requiredValue = $meta['required'] ?? 0;
            $hintValue = $meta['hint'];
            $cardGroupValue = $meta['card_group'];

            if ($meta['kind'] === 'group') {
                $cardGroupValue = $meta['group_key'];
                $hintValue = $meta['card_group'];
            } elseif ($meta['kind'] === 'old') {
                $requiredValue = 0;
                $cardGroupValue = $meta['group_key'];
                $hintValue = $meta['card_group'];
            } elseif ($meta['kind'] === 'hint_card') {
                // Signature C : groupe prouvé par le contexte, hint rendu à sa
                // colonne, `required` conservé. Seul l'`ordre` suit la
                // renumérotation 1..N, imposée par la signature B du formulaire.
                $cardGroupValue = $meta['group_key'];
                $hintValue = $meta['card_group'];
            }

            $assignments[] = [
                'rowid' => $meta['rowid'],
                'id' => $meta['id'],
                'form_id' => $formId,
                'repaired' => $repaired,
                'required' => $requiredValue,
                'hint' => $hintValue,
                'card_group' => $cardGroupValue,
                'ordre' => $ordre,
            ];
            $ordre++;
        }
    }

    /** @var array<string, list<array{rowid:int,id:string,form_id:string,old_ordre:int,hint_text:string,group_text:string}>> $byForm */
    $byForm = [];
    foreach ($repairable as $candidate) {
        if (isset($groupFormIds[$candidate['form_id']])) {
            continue; // déjà couvert par la renumérotation 1..N du formulaire
        }
        $byForm[$candidate['form_id']][] = $candidate;
    }
    foreach ($byForm as $formId => $list) {
        usort($list, static fn(array $a, array $b): int => [$a['old_ordre'], $a['rowid']] <=> [$b['old_ordre'], $b['rowid']]);

        // Base = après le plus grand ordre sain (entier) du formulaire. Les
        // lignes en rotation portent soit 0, soit le groupe texte : on ne
        // retient donc que les ordres entiers pour ne jamais dépasser une
        // ligne saine ni produire de doublon.
        $maxQuery = $pdo->prepare(
            "SELECT COALESCE(MAX(ordre), 0) FROM form_fields "
            . "WHERE form_id = ? AND typeof(ordre) = 'integer' AND ordre >= 0"
        );
        $maxQuery->execute([$formId]);
        $next = (int) $maxQuery->fetchColumn() + 1;
        $maxQuery = null;

        foreach ($list as $candidate) {
            $assignments[] = [
                'rowid' => $candidate['rowid'],
                'id' => $candidate['id'],
                'form_id' => $formId,
                'repaired' => true,
                'required' => 0,
                'hint' => $candidate['hint_text'],
                'card_group' => $candidate['group_text'] === '' ? 'Général' : $candidate['group_text'],
                'ordre' => $next,
            ];
            $next++;
        }
    }

    // Signature C hors formulaire renuméroté (aucune signature B) : seuls
    // `card_group` et `hint` changent. `ordre` et `required` observés — déjà
    // valides (entier >= 1, booléen 0/1) — sont conservés tels quels, donc les
    // positions existantes du formulaire ne bougent pas.
    foreach ($hintRepairs as $candidate) {
        if (isset($groupFormIds[$candidate['form_id']])) {
            continue; // déjà couvert par la renumérotation 1..N du formulaire
        }
        $assignments[] = [
            'rowid' => $candidate['rowid'],
            'id' => $candidate['id'],
            'form_id' => $candidate['form_id'],
            'repaired' => true,
            'required' => $candidate['required'],
            'hint' => $candidate['hint_text'],
            'card_group' => $candidate['group'],
            'ordre' => $candidate['ordre'],
        ];
    }

    // Contrôle des doublons avant écriture : chaque ordre attribué doit être
    // unique dans son formulaire.
    $seen = [];
    foreach ($assignments as $assignment) {
        $key = $assignment['form_id'] . ':' . $assignment['ordre'];
        if (isset($seen[$key])) {
            throw new RuntimeException('v47: duplicate ordre computed for form ' . $assignment['form_id']);
        }
        $seen[$key] = true;
    }

    // ── Préflight strict : destinataires valides ou réparables de façon sûre ──
    $rows = $pdo->query(
        'SELECT sr.id, sr.step_id, sr.email, s.label AS step_label '
        . 'FROM step_recipients sr LEFT JOIN steps s ON s.id = sr.step_id'
    );
    if ($rows === false) {
        throw new RuntimeException('v47: step_recipients preflight query failed');
    }
    $repairs = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $email = (string) $row['email'];
        if (RecipientAddress::isValid($email)) {
            continue;
        }

        $stepLabel = isset($row['step_label']) ? (string) $row['step_label'] : '';
        $repaired = RecipientAddress::knownRepair($email, $stepLabel);
        if ($repaired !== null) {
            $repairs[] = ['id' => (string) $row['id'], 'email' => $repaired];
            continue;
        }

        $invalid[] = 'step_recipients.email id=' . $row['id']
            . ' step=' . $row['step_id'];
    }
    $rows = null;

    if ($invalid !== [] || $rotations !== []) {
        $diagnostics = [];
        if ($invalid !== []) {
            $diagnostics[] = 'invalid ordre/recipient/required/enumerated values for '
                . implode(', ', array_slice($invalid, 0, 10))
                . (count($invalid) > 10 ? ' … (' . count($invalid) . ' total)' : '');
        }
        if ($rotations !== []) {
            $diagnostics[] = 'ambiguous form_fields.card_group/hint rotation (not one of the exact known signatures, not repaired) for '
                . implode(', ', array_slice($rotations, 0, 10))
                . (count($rotations) > 10 ? ' … (' . count($rotations) . ' total)' : '');
        }
        throw new RuntimeException('v47 blocked: ' . implode('; ', $diagnostics));
    }

    $counts = [];
    foreach (['form_fields', 'steps', 'step_recipients'] as $table) {
        $countQuery = $pdo->query("SELECT COUNT(*) FROM {$table}");
        if ($countQuery === false) {
            throw new RuntimeException("v47: {$table} count query failed");
        }
        $counts[$table] = (int) $countQuery->fetchColumn();
        $countQuery = null;
    }

    // PRAGMA foreign_keys est sans effet dans une transaction.
    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) {
        throw new RuntimeException('v47: foreign key query failed');
    }
    $foreignKeys = (int) $fkQuery->fetchColumn() === 1;
    $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');

        // ── Réparation form_fields : valeurs réparées isolées en table TEMP ──
        // Signature A : card_group := groupe observé, hint := hint observé,
        //               required := 0, ordre := séquentiel calculé.
        // Signature B : card_group := groupe observé, hint := hint observé,
        //               required := 0/1 conservé, ordre := 1..N du formulaire.
        // Signature C : card_group := groupe prouvé par le contexte, hint :=
        //               hint observé, required et ordre observés conservés.
        // Les lignes saines d'un formulaire de signature B ne reçoivent que
        // leur nouvelle position (`repaired = false`, autres colonnes NULL).
        //
        // Aucun UPDATE sur la table vivante : la signature B permute des ordres
        // (la ligne à l'ordre 3 reçoit 1, celle à l'ordre 1 reçoit 2), donc une
        // réécriture ligne à ligne collisionnerait avec tout index
        // UNIQUE (form_id, ordre) qu'un déploiement porterait hors dépôt. Les
        // valeurs sont projetées par jointure dans la table neuve (vide), ce qui
        // rend la renumérotation indépendante de l'index et atomique.
        $pdo->exec(
            'CREATE TEMP TABLE IF NOT EXISTS v47_field_fixes ('
            . 'field_rowid INTEGER PRIMARY KEY, '
            . 'hint TEXT, card_group TEXT, required INTEGER, ordre INTEGER NOT NULL)'
        );
        $pdo->exec('DELETE FROM v47_field_fixes');
        if ($assignments !== []) {
            $fixInsert = $pdo->prepare(
                'INSERT INTO v47_field_fixes (field_rowid, hint, card_group, required, ordre) '
                . 'VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($assignments as $assignment) {
                if ($assignment['repaired']) {
                    $fixInsert->execute([
                        $assignment['rowid'],
                        $assignment['hint'],
                        $assignment['card_group'],
                        $assignment['required'],
                        $assignment['ordre'],
                    ]);
                } else {
                    // Ligne saine : seule la position change, le reste est
                    // laissé intact par COALESCE au moment du rebuild.
                    $fixInsert->execute([
                        $assignment['rowid'],
                        null,
                        null,
                        null,
                        $assignment['ordre'],
                    ]);
                }
            }
            $fixInsert = null;
        }

        $update = $pdo->prepare('UPDATE step_recipients SET email = ? WHERE id = ?');
        foreach ($repairs as $repair) {
            $update->execute([$repair['email'], $repair['id']]);
        }
        $update = null;

        // ── form_fields : ordre NOT NULL, entier, >= 0 ──
        $pdo->exec('DROP TABLE IF EXISTS form_fields_new');
        $pdo->exec(
            "CREATE TABLE form_fields_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, "
            . "label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text','email','date','select','checkbox','textarea','file')), "
            . "field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT '', required INTEGER NOT NULL DEFAULT 0 CHECK (typeof(required) = 'integer' AND required IN (0,1)), "
            . "ordre INTEGER NOT NULL DEFAULT 0 CHECK (typeof(ordre) = 'integer' AND ordre >= 0), "
            . "card_group TEXT DEFAULT 'Général', filled_by TEXT NOT NULL DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur','validator')), "
            . "validator_step TEXT DEFAULT '', visibility TEXT NOT NULL DEFAULT 'all' CHECK (visibility IN ('all','owner_only')), "
            . "`condition` TEXT DEFAULT '', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)"
        );
        $formFieldColumns = 'id, form_id, label, field_type, field_name, options, hint, '
            . 'required, ordre, card_group, filled_by, validator_step, visibility, `condition`';
        // Projection par nom de colonne des deux côtés, avec les réparations
        // appliquées en une seule passe (table cible vide → aucun état
        // intermédiaire partagé avec les ordres existants).
        $pdo->exec(
            "INSERT INTO form_fields_new ({$formFieldColumns}) "
            . 'SELECT f.id, f.form_id, f.label, f.field_type, f.field_name, f.options, '
            . 'COALESCE(x.hint, f.hint), COALESCE(x.required, f.required), '
            . 'COALESCE(x.ordre, f.ordre), COALESCE(x.card_group, f.card_group), '
            . 'f.filled_by, f.validator_step, f.visibility, f.`condition` '
            . 'FROM form_fields f LEFT JOIN temp.v47_field_fixes x ON x.field_rowid = f.rowid'
        );
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id,filled_by)');

        // ── steps : ordre entier, >= 1 ──
        $pdo->exec('DROP TABLE IF EXISTS steps_new');
        $pdo->exec(
            "CREATE TABLE steps_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, "
            . "ordre INTEGER NOT NULL CHECK (typeof(ordre) = 'integer' AND ordre >= 1), "
            . "actif INTEGER NOT NULL DEFAULT 1 CHECK (typeof(actif) = 'integer' AND actif IN (0,1)), `condition` TEXT DEFAULT '', "
            . 'FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)'
        );
        $stepColumns = 'id, form_id, label, ordre, actif, `condition`';
        $pdo->exec(
            "INSERT INTO steps_new ({$stepColumns}) SELECT {$stepColumns} FROM steps"
        );
        $pdo->exec('DROP TABLE steps');
        $pdo->exec('ALTER TABLE steps_new RENAME TO steps');

        // ── step_recipients : email classique ou référence {{...}} ──
        // La CHECK SQL est un miroir volontairement grossier du contrat PHP
        // `RecipientAddress` (pas de regex en SQLite) : elle bloque les formes
        // vides/tronquées/espacées, mais c'est `RecipientAddress::isValid()`
        // qui reste la source de vérité du format exact.
        $pdo->exec('DROP TABLE IF EXISTS step_recipients_new');
        $pdo->exec(
            "CREATE TABLE step_recipients_new (id TEXT PRIMARY KEY NOT NULL, step_id TEXT NOT NULL, email TEXT NOT NULL CHECK ("
            . "email <> '' "
            . "AND trim(email) = email "
            . "AND instr(email, ' ') = 0 "
            . "AND instr(email, char(9)) = 0 "
            . "AND instr(email, char(10)) = 0 "
            . "AND instr(email, char(13)) = 0 "
            . "AND ("
            . "(email LIKE '{{%}}' AND length(email) > 4) "
            . "OR ("
            . "length(email) - length(replace(email, '@', '')) = 1 "
            . "AND instr(email, '@') > 1 "
            . "AND substr(email, -1) <> '@'"
            . ")"
            . ")), FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE)"
        );
        $recipientColumns = 'id, step_id, email';
        $pdo->exec(
            "INSERT INTO step_recipients_new ({$recipientColumns}) "
            . "SELECT {$recipientColumns} FROM step_recipients"
        );
        $pdo->exec('DROP TABLE step_recipients');
        $pdo->exec('ALTER TABLE step_recipients_new RENAME TO step_recipients');

        // ── Vérifications post-rebuild ──
        foreach ($counts as $table => $before) {
            $afterQuery = $pdo->query("SELECT COUNT(*) FROM {$table}");
            if ($afterQuery === false) {
                throw new RuntimeException("v47: {$table} post-rebuild count failed");
            }
            $after = (int) $afterQuery->fetchColumn();
            $afterQuery = null;
            if ($after !== $before) {
                throw new RuntimeException("v47: {$table} row count changed during rebuild");
            }
        }

        $schema = [];
        foreach (['form_fields', 'steps', 'step_recipients'] as $table) {
            $schemaQuery = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'");
            if ($schemaQuery === false) {
                throw new RuntimeException("v47: {$table} schema query failed");
            }
            $schema[$table] = (string) $schemaQuery->fetchColumn();
            $schemaQuery = null;
        }
        if (!str_contains($schema['form_fields'], "typeof(ordre) = 'integer'")
            || !str_contains($schema['form_fields'], "typeof(required) = 'integer'")
            || !str_contains($schema['form_fields'], 'required IN (0,1)')
            || !str_contains($schema['steps'], "typeof(ordre) = 'integer'")
            || !str_contains($schema['steps'], "typeof(actif) = 'integer'")
            || !str_contains($schema['steps'], 'ordre >= 1')
            || !str_contains($schema['step_recipients'], "LIKE '{{%}}'")) {
            throw new RuntimeException('v47: ordre/recipient CHECK missing after rebuild');
        }

        // ── Vérification des réparations et de l'unicité des ordres ──
        if ($assignments !== []) {
            $verify = $pdo->prepare(
                'SELECT hint, card_group, required, ordre FROM form_fields WHERE id = ?'
            );
            foreach ($assignments as $assignment) {
                $verify->execute([$assignment['id']]);
                $repaired = $verify->fetch(PDO::FETCH_ASSOC);
                if (!is_array($repaired)
                    || (int) $repaired['required'] !== $assignment['required']
                    || (int) $repaired['ordre'] !== $assignment['ordre']) {
                    throw new RuntimeException(
                        'v47: repaired form_fields row not persisted for id=' . $assignment['id']
                    );
                }
                if ($assignment['repaired']
                    && ((string) $repaired['hint'] !== $assignment['hint']
                        || (string) $repaired['card_group'] !== $assignment['card_group'])) {
                    throw new RuntimeException(
                        'v47: repaired form_fields group/hint not persisted for id=' . $assignment['id']
                    );
                }
            }
            $verify = null;

            // Unicité contrôlée par ordre attribué, pas par intervalle : la
            // signature C conserve les ordres observés et peut laisser des
            // trous entre deux réparations (une ligne saine entre deux lignes
            // réparées), ce qu'un comptage `BETWEEN min AND max` interpréterait
            // à tort comme un doublon. Signatures A et B restent couvertes :
            // elles attribuent explicitement tous les ordres qu'elles touchent.
            /** @var array<string, list<int>> $ordresByForm */
            $ordresByForm = [];
            foreach ($assignments as $assignment) {
                $ordresByForm[$assignment['form_id']][] = $assignment['ordre'];
            }
            $duplicateQuery = $pdo->prepare(
                'SELECT COUNT(*) FROM form_fields WHERE form_id = ? AND ordre = ?'
            );
            foreach ($ordresByForm as $formId => $ordres) {
                foreach (array_unique($ordres) as $ordre) {
                    $duplicateQuery->execute([$formId, $ordre]);
                    if ((int) $duplicateQuery->fetchColumn() !== 1) {
                        throw new RuntimeException(
                            'v47: duplicate ordre detected for form ' . $formId
                            . ' after repair (ordre=' . $ordre . ')'
                        );
                    }
                }
            }
            $duplicateQuery = null;
        }

        // Table de travail confinée à cette connexion : supprimée avant COMMIT
        // pour ne laisser aucun résidu sur une connexion de longue vie.
        $pdo->exec('DROP TABLE IF EXISTS v47_field_fixes');

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (47, datetime('now'))");
        $pdo->exec('COMMIT');
        return 47;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}

/**
 * Valeurs d'un enum string-backed, sous forme de `list<string>`.
 *
 * Utilisé par les préflights v47 pour que les listes fermées (`field_type`,
 * `filled_by`, `visibility`) ne soient jamais recopiées : l'enum reste la
 * source de vérité, les CHECK SQL n'en sont que le miroir.
 *
 * @param array<int, \BackedEnum> $cases
 * @return list<string>
 */
function v47_enum_values(array $cases): array
{
    $values = [];
    foreach ($cases as $case) {
        $values[] = (string) $case->value;
    }

    return $values;
}

/**
 * Préflight d'une colonne `form_fields` à liste fermée.
 *
 * Retourne un diagnostic par ligne fautive (colonne + ID + valeur réelle) au
 * lieu de laisser SQLite échouer plus tard sur un « CHECK constraint failed »
 * générique. Aucune réparation : une valeur hors liste bloque la migration.
 *
 * @param list<string> $allowed Valeurs autorisées (issues d'un enum)
 * @return list<string>
 */
function v47_off_list_values(PDO $pdo, string $column, string $label, array $allowed): array
{
    // Colonne interpolée : liste blanche explicite, jamais une entrée externe.
    if (!in_array($column, ['field_type', 'filled_by', 'visibility'], true)) {
        throw new RuntimeException('v47: preflight refused for column ' . $column);
    }
    if ($allowed === []) {
        throw new RuntimeException('v47: empty allowed list for column ' . $column);
    }

    $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, {$column} AS value FROM form_fields "
        . "WHERE {$column} IS NULL OR typeof({$column}) <> 'text' "
        . "OR {$column} NOT IN ({$placeholders}) LIMIT 10"
    );
    if ($stmt === false) {
        throw new RuntimeException('v47: ' . $column . ' preflight query failed');
    }
    $stmt->execute($allowed);

    $diagnostics = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $diagnostics[] = $label
            . ' id=' . $row['id']
            . ' value=' . json_encode($row['value'], JSON_UNESCAPED_UNICODE);
    }
    $stmt = null;

    return $diagnostics;
}