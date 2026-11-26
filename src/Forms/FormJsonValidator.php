<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\SlugHelper;
use App\Enum\FieldType;
use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Validation\RecipientAddress;
use App\Workflow\ConditionEvaluator;

/**
 * Validation du schéma JSON des formulaires (import/export).
 *
 * Contrat v1.0 durci (audit 2026-11-26) :
 *  - listes de clés fermées (top-level, form, field, step, condition) :
 *    toute clé inconnue est une erreur, jamais ignorée ;
 *  - `required` / `actif` : booléen ou 0/1 explicite uniquement, aucune
 *    conversion silencieuse d'un numéro d'ordre ou d'une chaîne libre ;
 *  - `fields`, `steps` et `recipients` doivent être des listes JSON ;
 *  - `ordre` strictement entier : `form_fields.ordre >= 0`, `steps.ordre >= 1` ;
 *  - `validator_step` et `deadline_field` doivent référencer un élément
 *    existant du même document ;
 *  - doublons de `field_name` détectés, y compris les noms générés depuis
 *    le libellé ;
 *  - bornes de volume/longueur explicites (constantes `MAX_*`), documentées
 *    ici et appliquées avant toute écriture en base.
 */
final class FormJsonValidator
{
    /** Profondeur maximale de décodage JSON (conditions, import). */
    public const int JSON_MAX_DEPTH = 32;

    /** Nombre maximal de champs par formulaire. */
    public const int MAX_FIELDS = 200;
    /** Nombre maximal d'étapes par formulaire. */
    public const int MAX_STEPS = 50;
    /** Nombre maximal de destinataires par étape. */
    public const int MAX_RECIPIENTS_PER_STEP = 50;
    /** Nombre maximal d'options d'un champ select. */
    public const int MAX_OPTIONS_PER_FIELD = 200;
    /** Longueur maximale d'une option de select. */
    public const int MAX_OPTION_LENGTH = 255;
    /** Nombre maximal de valeurs dans une condition `in`. */
    public const int MAX_CONDITION_VALUES = 100;
    /** Ordre maximal accepté (form_fields et steps). */
    public const int MAX_ORDER = 1000;
    /** Longueur maximale d'un libellé (form/form/step). */
    public const int MAX_LABEL_LENGTH = 255;
    /** Longueur maximale d'un field_name. */
    public const int MAX_FIELD_NAME_LENGTH = 64;
    /** Longueur maximale de form.description. */
    public const int MAX_DESCRIPTION_LENGTH = 5000;
    /** Longueur maximale d'un hint. */
    public const int MAX_HINT_LENGTH = 1000;
    /** Longueur maximale d'un card_group. */
    public const int MAX_CARD_GROUP_LENGTH = 100;
    /** Longueur maximale d'une adresse email. */
    public const int MAX_EMAIL_LENGTH = 254;
    /** Délai de relance minimal (heures) — aligné sur le CHECK de `forms.relance_delai_h`. */
    public const int MIN_RELANCE_DELAI_H = 1;
    /** Délai de relance maximal (heures) — aligné sur le CHECK de `forms.relance_delai_h`. */
    public const int MAX_RELANCE_DELAI_H = 720;
    /** Nombre maximal de relances — aligné sur le CHECK de `forms.relance_max`. */
    public const int MAX_RELANCE_MAX = 20;

    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = ['schema_version', 'exported_at', 'form', 'fields', 'steps'];
    /** @var list<string> */
    private const array FORM_KEYS = ['label', 'slug', 'description', 'actif', 'deadline_field', 'relance_delai_h', 'relance_max'];
    /** @var list<string> */
    private const array FIELD_KEYS = ['label', 'field_type', 'field_name', 'options', 'required', 'ordre', 'card_group', 'hint', 'filled_by', 'validator_step', 'visibility'];
    /** @var list<string> */
    private const array STEP_KEYS = ['label', 'ordre', 'actif', 'recipients', 'condition'];
    /** @var list<string> */
    private const array CONDITION_KEYS = ['field', 'op', 'value'];
    /** @var list<string> */
    private const array DYNAMIC_RECIPIENT_ALIASES = ['owner', 'owner_email'];

    /**
     * Valide la structure JSON d'un formulaire contre le schéma v1.0.
     *
     * @param array<string, mixed> $data
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $warnings = [];
        $valid_field_types = array_map(static fn(FieldType $t): string => $t->value, FieldType::cases());
        $valid_filled_by = array_map(static fn(FilledBy $f): string => $f->value, FilledBy::cases());
        $valid_visibilities = array_map(static fn(FieldVisibility $v): string => $v->value, FieldVisibility::cases());

        // ── Top-level structure ──────────────────────────────────
        self::rejectUnknownKeys($data, self::TOP_LEVEL_KEYS, 'racine', $errors);

        if (!isset($data['schema_version'])) {
            $warnings[] = 'Propriété "schema_version" manquante. Ajoutez "schema_version": "1.0" pour garantir la compatibilité future.';
        } elseif (!is_string($data['schema_version'])) {
            $errors[] = '"schema_version" doit être une chaîne de caractères (valeur supportée : "1.0").';
        } elseif ($data['schema_version'] !== '1.0') {
            $warnings[] = 'schema_version = "' . $data['schema_version'] . '". La version supportée est "1.0". L\'import sera tenté mais pourrait échouer.';
        }

        if (isset($data['exported_at']) && !is_string($data['exported_at'])) {
            $warnings[] = '"exported_at" est ignoré à l\'import et devrait être une chaîne ISO-8601.';
        }

        // ── form object ─────────────────────────────────────────
        /** @var list<string> $all_field_names */
        $all_field_names = [];
        /** @var list<string> $seen_validator_field_names */
        $seen_validator_field_names = [];
        $deadline_field = '';
        $fields_declared = false;

        if (!isset($data['form']) || !is_array($data['form'])) {
            $errors[] = 'Propriété "form" manquante ou n\'est pas un objet. Attendu : { "form": { "label": "...", "description": "..." } }';
        } else {
            /** @var array<string, mixed> $form */
            $form = $data['form'];
            self::rejectUnknownKeys($form, self::FORM_KEYS, 'form', $errors);

            if (!isset($form['label']) || !is_string($form['label']) || trim($form['label']) === '') {
                $errors[] = 'form.label est requis et doit être une chaîne de caractères non vide.';
            } elseif (strlen($form['label']) > self::MAX_LABEL_LENGTH) {
                $errors[] = 'form.label est trop long (' . strlen($form['label']) . ' caractères). Maximum : ' . self::MAX_LABEL_LENGTH . '.';
            }
            if (isset($form['description'])) {
                if (!is_string($form['description'])) {
                    $errors[] = 'form.description doit être une chaîne de caractères.';
                } elseif (strlen($form['description']) > self::MAX_DESCRIPTION_LENGTH) {
                    $errors[] = 'form.description est trop long (' . strlen($form['description']) . ' caractères). Maximum : ' . self::MAX_DESCRIPTION_LENGTH . '.';
                }
            }
            if (isset($form['slug']) && !is_string($form['slug'])) {
                $errors[] = 'form.slug doit être une chaîne de caractères.';
            }
            // Note : form.slug est volontairement IGNORÉ à l'import (le slug est
            // régénéré depuis le label pour garantir l'unicité globale).
            if (array_key_exists('actif', $form) && !self::isStrictFlag($form['actif'])) {
                $errors[] = 'form.actif doit être true/false, 0 ou 1. Les autres valeurs sont refusées (aucune conversion automatique).';
            }
            if (array_key_exists('deadline_field', $form) && $form['deadline_field'] !== null) {
                if (!is_string($form['deadline_field'])) {
                    $errors[] = 'form.deadline_field doit être une chaîne (field_name existant), null ou vide.';
                } elseif (strlen($form['deadline_field']) > self::MAX_FIELD_NAME_LENGTH) {
                    $errors[] = 'form.deadline_field est trop long. Maximum : ' . self::MAX_FIELD_NAME_LENGTH . ' caractères.';
                } else {
                    $deadline_field = $form['deadline_field'];
                }
            }
            self::validateOptionalIntBound($form, 'relance_delai_h', self::MIN_RELANCE_DELAI_H, self::MAX_RELANCE_DELAI_H, 'form', $errors);
            self::validateOptionalIntBound($form, 'relance_max', 0, self::MAX_RELANCE_MAX, 'form', $errors);
        }

        // ── fields array ────────────────────────────────────────
        if (!isset($data['fields'])) {
            $errors[] = 'Propriété "fields" manquante. Le JSON doit contenir un tableau "fields" (même vide) avec la définition des champs du formulaire.';
        } elseif (!is_array($data['fields']) || !array_is_list($data['fields'])) {
            // B8 (audit 2026-09-14) : un objet JSON décode en tableau associatif
            // et ferait planter la boucle ($i + 1 sur clé chaîne) → exiger une liste.
            $errors[] = '"fields" doit être un tableau (liste) d\'objets. Un objet JSON "{...}" n\'est pas accepté (utilisez "[...]").';
        } else {
            $fields = $data['fields'];
            $fields_declared = true;
            if (count($fields) === 0) {
                $warnings[] = 'Le tableau "fields" est vide. Le formulaire n\'aura aucun champ — l\'utilisateur ne pourra rien saisir.';
            } elseif (count($fields) > self::MAX_FIELDS) {
                $errors[] = 'Le tableau "fields" contient ' . count($fields) . ' champ(s). Maximum : ' . self::MAX_FIELDS . '.';
            }

            $seen_field_names = [];
            foreach ($fields as $i => $f) {
                $idx = $i + 1;
                $prefix = "fields[$idx]";

                if (!is_array($f)) {
                    $errors[] = "$prefix n'est pas un objet. Chaque champ doit être un objet { label, field_type, field_name, ... }.";
                    continue;
                }

                self::rejectUnknownKeys($f, self::FIELD_KEYS, $prefix, $errors);

                // label
                $field_label = $f['label'] ?? null;
                if (!is_string($field_label) || trim($field_label) === '') {
                    $errors[] = "$prefix.label est requis et doit être une chaîne non vide.";
                } elseif (strlen($field_label) > self::MAX_LABEL_LENGTH) {
                    $errors[] = "$prefix.label est trop long (" . strlen($field_label) . ' caractères). Maximum : ' . self::MAX_LABEL_LENGTH . '.';
                }

                // field_type
                if (!isset($f['field_type']) || $f['field_type'] === '') {
                    $errors[] = "$prefix.field_type est requis. Valeurs possibles : " . implode(', ', array_map(static fn(string $t): string => '"' . $t . '"', $valid_field_types));
                } elseif (!is_string($f['field_type']) || !in_array($f['field_type'], $valid_field_types, true)) {
                    $errors[] = "$prefix.field_type = " . json_encode($f['field_type']) . " n'est pas valide. Valeurs possibles : " . implode(', ', array_map(static fn(string $t): string => '"' . $t . '"', $valid_field_types));
                }

                // field_name — un nom explicite doit être valide ; un nom absent
                // est généré depuis le label ET contrôlé comme les autres
                // (doublons inclus), sinon l'import créait deux colonnes
                // homonymes silencieusement.
                $resolved_name = '';
                if (array_key_exists('field_name', $f) && $f['field_name'] !== null && $f['field_name'] !== '') {
                    if (!is_string($f['field_name'])) {
                        $errors[] = "$prefix.field_name doit être une chaîne. Trouvé : " . gettype($f['field_name']);
                    } elseif (strlen($f['field_name']) > self::MAX_FIELD_NAME_LENGTH) {
                        $errors[] = "$prefix.field_name est trop long (" . strlen($f['field_name']) . ' caractères). Maximum : ' . self::MAX_FIELD_NAME_LENGTH . '.';
                    } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $f['field_name'])) {
                        $errors[] = "$prefix.field_name = \"{$f['field_name']}\" n'est pas en snake_case valide. Format attendu : minuscules, chiffres et underscores uniquement, commençant par une lettre. Exemple : \"date_arrivee\", \"type_demande\".";
                    } else {
                        $resolved_name = strtolower($f['field_name']);
                    }
                } else {
                    if (is_string($field_label) && trim($field_label) !== '') {
                        $generated = SlugHelper::generateFieldName($field_label);
                        $resolved_name = strtolower($generated);
                        $warnings[] = "$prefix.field_name est vide. Le nom technique généré depuis le label sera \"$generated\". Il est recommandé de le fournir explicitement en snake_case.";
                    } else {
                        $errors[] = "$prefix.field_name est vide et ne peut pas être généré : label invalide.";
                    }
                }

                if ($resolved_name !== '') {
                    if (in_array($resolved_name, $seen_field_names, true)) {
                        $errors[] = "$prefix.field_name = \"$resolved_name\" est en doublon. Chaque champ doit avoir un field_name unique (les noms générés depuis le label sont inclus dans ce contrôle).";
                    }
                    $seen_field_names[] = $resolved_name;
                    $all_field_names[] = $resolved_name;
                    if (($f['filled_by'] ?? FilledBy::Demandeur->value) === FilledBy::Validator->value) {
                        $seen_validator_field_names[] = $resolved_name;
                    }
                }

                // options for select — doit être une liste JSON de chaînes.
                if (($f['field_type'] ?? '') === FieldType::Select->value) {
                    $options = $f['options'] ?? null;
                    if (!is_array($options) || $options === []) {
                        $errors[] = "$prefix : field_type = \"select\" mais \"options\" est manquant ou n'est pas un tableau. Exemple : \"options\": [\"Option A\", \"Option B\"]";
                    } elseif (!array_is_list($options)) {
                        $errors[] = "$prefix.options doit être une liste JSON (\"[...]\"), pas un objet.";
                    } else {
                        if (count($options) > self::MAX_OPTIONS_PER_FIELD) {
                            $errors[] = "$prefix.options contient " . count($options) . ' valeur(s). Maximum : ' . self::MAX_OPTIONS_PER_FIELD . '.';
                        }
                        $seen_options = [];
                        foreach ($options as $j => $opt) {
                            if (!is_string($opt) || trim($opt) === '') {
                                $errors[] = "$prefix.options[" . ($j + 1) . '] doit être une chaîne non vide.';
                            } elseif (strlen($opt) > self::MAX_OPTION_LENGTH) {
                                $errors[] = "$prefix.options[" . ($j + 1) . '] est trop long. Maximum : ' . self::MAX_OPTION_LENGTH . ' caractères.';
                            } elseif (in_array($opt, $seen_options, true)) {
                                $warnings[] = "$prefix.options[" . ($j + 1) . "] = \"$opt\" est un doublon d'option.";
                            } else {
                                $seen_options[] = $opt;
                            }
                        }
                        if (count($options) < 2) {
                            $warnings[] = "$prefix : field_type = \"select\" mais options ne contient que " . count($options) . ' valeur(s). Un sélecteur devrait avoir au moins 2 options.';
                        }
                    }
                } elseif (isset($f['options'])) {
                    $field_type_label = is_string($f['field_type'] ?? null) ? $f['field_type'] : '?';
                    $warnings[] = "$prefix : field_type = \"$field_type_label\" mais \"options\" est renseigné. Seul le type \"select\" utilise les options.";
                }

                // required — booléen ou 0/1 explicite uniquement. Aucune
                // normalisation d'un numéro d'ordre ou d'une chaîne libre :
                // l'import doit être bloqué, pas corriger en silence.
                if (array_key_exists('required', $f) && !self::isStrictFlag($f['required'])) {
                    $errors[] = "$prefix.required doit être true/false, 0 ou 1. Trouvé : " . json_encode($f['required']) . '. Aucune conversion automatique n\'est appliquée.';
                }

                // ordre — contrat `form_fields.ordre` : entier >= 0.
                if (array_key_exists('ordre', $f)) {
                    $ordre_int = self::toStrictInt($f['ordre']);
                    if ($ordre_int === null) {
                        $errors[] = "$prefix.ordre doit être un entier >= 0. Trouvé : " . json_encode($f['ordre']) . '.';
                    } elseif ($ordre_int < 0) {
                        $errors[] = "$prefix.ordre = $ordre_int est négatif. form_fields.ordre doit être >= 0.";
                    } elseif ($ordre_int > self::MAX_ORDER) {
                        $errors[] = "$prefix.ordre = $ordre_int dépasse la borne autorisée (" . self::MAX_ORDER . ').';
                    }
                }

                // card_group
                if (isset($f['card_group']) && !is_string($f['card_group'])) {
                    $errors[] = "$prefix.card_group doit être une chaîne de caractères.";
                } elseif (isset($f['card_group']) && strlen($f['card_group']) > self::MAX_CARD_GROUP_LENGTH) {
                    $errors[] = "$prefix.card_group est trop long. Maximum : " . self::MAX_CARD_GROUP_LENGTH . ' caractères.';
                } elseif (!isset($f['card_group']) || $f['card_group'] === '') {
                    $warnings[] = "$prefix.card_group est vide. Il sera placé dans le groupe \"Général\" par défaut. Recommandé : regrouper les champs par thème (ex: \"Identité\", \"Affectation\", \"IT\").";
                }

                // hint
                // D5 (2026-09-15) : un hint purement numérique est une chaîne
                // légitime (round-trip export/import fidèle). Seul le type et la
                // longueur sont contrôlés.
                if (isset($f['hint'])) {
                    if (!is_string($f['hint'])) {
                        $errors[] = "$prefix.hint doit être une chaîne de caractères.";
                    } elseif (strlen($f['hint']) > self::MAX_HINT_LENGTH) {
                        $errors[] = "$prefix.hint est trop long. Maximum : " . self::MAX_HINT_LENGTH . ' caractères.';
                    }
                }

                // ── filled_by / validator_step ──
                $filled_by = $f['filled_by'] ?? FilledBy::Demandeur->value;
                if (isset($f['filled_by'])) {
                    if (!is_string($f['filled_by'])) {
                        $errors[] = "$prefix.filled_by doit être une chaîne. Trouvé : " . gettype($f['filled_by']);
                    } elseif (!in_array($f['filled_by'], $valid_filled_by, true)) {
                        $errors[] = "$prefix.filled_by = \"{$f['filled_by']}\" n'est pas valide. Valeurs attendues : " . implode(', ', array_map(static fn(string $v): string => '"' . $v . '"', $valid_filled_by)) . '.';
                    }
                }

                $validator_step = $f['validator_step'] ?? '';
                if (!is_string($validator_step)) {
                    $errors[] = "$prefix.validator_step doit être une chaîne (UUID du step, label du step, ou chaîne vide).";
                    $validator_step = '';
                } elseif (strlen($validator_step) > self::MAX_LABEL_LENGTH) {
                    $errors[] = "$prefix.validator_step est trop long. Maximum : " . self::MAX_LABEL_LENGTH . ' caractères.';
                }

                // Cohérence filled_by/validator_step : un champ demandeur ne peut
                // pas porter un validator_step (silencieusement ignoré au runtime).
                if ($filled_by === FilledBy::Demandeur->value && $validator_step !== '') {
                    $errors[] = "$prefix : filled_by = \"demandeur\" mais validator_step est renseigné. Un champ demandeur ne peut pas cibler une étape de validation — utilisez filled_by = \"validator\" ou supprimez validator_step.";
                }
                if ($filled_by === FilledBy::Validator->value && $validator_step === '') {
                    $warnings[] = "$prefix : filled_by = \"validator\" mais validator_step est vide. Le champ sera disponible sur TOUTES les étapes (champ global). Précisez validator_step pour le limiter à une étape.";
                }

                // ── FILE-VISIBILITY : validation du champ visibility ──
                if (isset($f['visibility'])) {
                    if (!is_string($f['visibility'])) {
                        $errors[] = "$prefix.visibility doit être une chaîne.";
                    } elseif (!in_array($f['visibility'], $valid_visibilities, true)) {
                        $errors[] = "$prefix.visibility = \"{$f['visibility']}\" n'est pas valide. Valeurs attendues : " . implode(', ', array_map(static fn(string $v): string => '"' . $v . '"', $valid_visibilities)) . '.';
                    }
                }
                if (($f['visibility'] ?? FieldVisibility::All->value) === FieldVisibility::OwnerOnly->value && ($f['field_type'] ?? '') !== FieldType::File->value) {
                    $warnings[] = "$prefix : visibility = \"owner_only\" mais field_type n'est pas \"file\". visibility sera ignoré.";
                }
            }
        }

        // deadline_field doit référencer un field_name réellement défini.
        if ($deadline_field !== '' && $fields_declared && !in_array(strtolower($deadline_field), $all_field_names, true)) {
            $errors[] = "form.deadline_field = \"$deadline_field\" ne correspond à aucun field_name défini dans \"fields\". La date cible des alertes serait introuvable.";
        }

        // ── steps array ─────────────────────────────────────────
        /** @var list<string> $step_references */
        $step_references = [];
        if (!isset($data['steps'])) {
            $warnings[] = 'Propriété "steps" manquante. Le formulaire sera créé sans circuit de validation. Ajoutez un tableau "steps" pour définir le workflow. Exemple : { "steps": [{ "label": "Validation manager", "ordre": 1, "actif": true, "recipients": ["manager@' . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . '"] }] }';
        } elseif (!is_array($data['steps']) || !array_is_list($data['steps'])) {
            $errors[] = '"steps" doit être un tableau (liste) d\'objets. Un objet JSON "{...}" n\'est pas accepté (utilisez "[...]").';
        } else {
            $steps = $data['steps'];
            if (count($steps) === 0) {
                $warnings[] = 'Le tableau "steps" est vide. Le formulaire n\'aura aucun circuit de validation — les demandes ne pourront pas être approuvées.';
            } elseif (count($steps) > self::MAX_STEPS) {
                $errors[] = 'Le tableau "steps" contient ' . count($steps) . ' étape(s). Maximum : ' . self::MAX_STEPS . '.';
            }

            $seen_ordres = [];
            foreach ($steps as $i => $s) {
                $idx = $i + 1;
                $prefix = "steps[$idx]";

                if (!is_array($s)) {
                    $errors[] = "$prefix n'est pas un objet. Chaque étape doit être un objet { label, ordre, actif, recipients }.";
                    continue;
                }

                self::rejectUnknownKeys($s, self::STEP_KEYS, $prefix, $errors);

                // label (référence possible de validator_step)
                if (!isset($s['label']) || !is_string($s['label']) || trim($s['label']) === '') {
                    $errors[] = "$prefix.label est requis et doit être une chaîne non vide. Exemple : \"Validation manager\", \"Validation RH\".";
                } elseif (strlen($s['label']) > self::MAX_LABEL_LENGTH) {
                    $errors[] = "$prefix.label est trop long. Maximum : " . self::MAX_LABEL_LENGTH . ' caractères.';
                } else {
                    $step_references[] = strtolower($s['label']);
                }
                // ordre — entier >= 1.
                if (!isset($s['ordre'])) {
                    $warnings[] = "$prefix.ordre est manquant. L'ordre sera auto-incrémenté, mais il est recommandé de le spécifier explicitement.";
                } else {
                    $ordre_int = self::toStrictInt($s['ordre']);
                    if ($ordre_int === null) {
                        $errors[] = "$prefix.ordre doit être un entier >= 1. Trouvé : " . json_encode($s['ordre']) . '.';
                    } elseif ($ordre_int < 1) {
                        $errors[] = "$prefix.ordre = $ordre_int est invalide. steps.ordre doit être >= 1.";
                    } elseif ($ordre_int > self::MAX_ORDER) {
                        $errors[] = "$prefix.ordre = $ordre_int dépasse la borne autorisée (" . self::MAX_ORDER . ').';
                    } else {
                        if (in_array($ordre_int, $seen_ordres, true)) {
                            $warnings[] = "$prefix.ordre = $ordre_int est en doublon avec une autre étape. Les étapes de même ordre sont validées en parallèle — assurez-vous que c'est intentionnel.";
                        }
                        $seen_ordres[] = $ordre_int;
                    }
                }

                // actif
                if (array_key_exists('actif', $s) && !self::isStrictFlag($s['actif'])) {
                    $errors[] = "$prefix.actif doit être true/false, 0 ou 1. Les autres valeurs sont refusées (aucune conversion automatique).";
                }

                // condition d'exécution (objet ou chaîne JSON, validée pareil)
                self::validateCondition($s['condition'] ?? null, $prefix, $seen_validator_field_names, $errors, $warnings);

                $s_ordre = $s['ordre'] ?? null;
                if (($s['condition'] ?? null) !== null && ($s['condition'] ?? '') !== '' && self::toStrictInt($s_ordre) === 1) {
                    $warnings[] = "$prefix : une condition d'exécution est définie sur une étape d'ordre 1. La première étape s'exécute toujours à la création de la soumission — la condition sera évaluée mais peut ne pas avoir d'effet (le champ validateur référencé n'est pas encore rempli).";
                }

                // recipients — liste JSON obligatoire.
                $recipients = $s['recipients'] ?? null;
                if (!is_array($recipients) || !array_is_list($recipients)) {
                    $errors[] = $prefix . '.recipients est requis et doit être un tableau (liste) d\'adresses email. Exemple : ["manager@' . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . '", "rh@' . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . '"]';
                } else {
                    if (count($recipients) === 0) {
                        $warnings[] = "$prefix.recipients est vide. L'étape n'aura aucun validateur — personne ne pourra approuver cette étape.";
                    } elseif (count($recipients) > self::MAX_RECIPIENTS_PER_STEP) {
                        $errors[] = "$prefix.recipients contient " . count($recipients) . ' destinataire(s). Maximum : ' . self::MAX_RECIPIENTS_PER_STEP . '.';
                    }
                    foreach ($recipients as $j => $email) {
                        if (!is_string($email)) {
                            $errors[] = "$prefix.recipients[" . ($j + 1) . '] doit être une chaîne (adresse email ou référence {{field_name}}).';
                        } elseif (strlen($email) > self::MAX_EMAIL_LENGTH + 4) {
                            $errors[] = "$prefix.recipients[" . ($j + 1) . '] est trop long. Maximum : ' . (self::MAX_EMAIL_LENGTH + 4) . ' caractères.';
                        } elseif (RecipientAddress::isDynamic($email)) {
                            $dynamic_name = RecipientAddress::dynamicName($email);
                            if ($dynamic_name !== null
                                && !in_array($dynamic_name, self::DYNAMIC_RECIPIENT_ALIASES, true)
                                && !in_array($dynamic_name, $all_field_names, true)) {
                                $errors[] = "$prefix.recipients[" . ($j + 1) . "] = \"$email\" référence un champ inexistant. Utilisez {{owner}}, {{owner_email}} ou le field_name d'un champ défini.";
                            }
                        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = "$prefix.recipients[" . ($j + 1) . "] = \"$email\" n'est ni une adresse email valide ni une référence {{field_name}}. Format attendu : prenom.nom@" . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . ', service@' . \App\Core\App::settings()->get('email_domain', 'exemple.invalid') . ' ou {{nom_du_champ}}';
                        }
                    }
                }
            }
        }

        // validator_step doit référencer le label d'une étape déclarée dans
        // ce document. Le contrôle est fait après la boucle pour accepter une
        // référence vers une étape déclarée plus bas. Les UUID d'étapes ne sont
        // jamais acceptés : l'import génère de nouveaux IDs (l'export convertit
        // déjà les IDs en labels).
        if ($fields_declared && is_array($data['fields'] ?? null) && array_is_list($data['fields'])) {
            foreach ($data['fields'] as $i => $f) {
                if (!is_array($f)) {
                    continue;
                }
                $validator_step = $f['validator_step'] ?? '';
                if (!is_string($validator_step) || $validator_step === '') {
                    continue;
                }
                if (!in_array(strtolower($validator_step), $step_references, true)) {
                    $errors[] = 'fields[' . ($i + 1) . "] : validator_step = \"$validator_step\" ne correspond à aucune étape définie dans \"steps\". Le champ validator ne serait jamais affiché — utilisez le label exact d'une étape existante.";
                }
            }
        }

        return [
            'valid'    => count($errors) === 0,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Rejette les clés inconnues d'un objet JSON (whitelist stricte).
     *
     * @param array<string, mixed> $object
     * @param list<string>         $allowed
     * @param list<string>         $errors
     */
    private static function rejectUnknownKeys(array $object, array $allowed, string $prefix, array &$errors): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $errors[] = $prefix . ' contient une propriété inconnue "' . $key . '". Propriétés autorisées : ' . implode(', ', $allowed) . '.';
            }
        }
    }

    /**
     * Valide une propriété optionnelle entière bornée (null accepté).
     *
     * @param array<string, mixed> $object
     * @param list<string>         $errors
     */
    private static function validateOptionalIntBound(array $object, string $key, int $min, int $max, string $prefix, array &$errors): void
    {
        if (!array_key_exists($key, $object) || $object[$key] === null) {
            return;
        }
        $value = $object[$key];
        if (!is_int($value)) {
            $errors[] = "$prefix.$key doit être un entier ou null. Trouvé : " . json_encode($value) . '.';
            return;
        }
        if ($value < $min || $value > $max) {
            $errors[] = "$prefix.$key doit être compris entre $min et $max. Trouvé : $value.";
        }
    }

    /**
     * Valide une condition d'étape (objet ou chaîne JSON) de façon symétrique.
     *
     * @param list<string> $validator_field_names
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private static function validateCondition(mixed $raw, string $prefix, array $validator_field_names, array &$errors, array &$warnings): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        $cond = null;
        if (is_array($raw)) {
            $cond = $raw;
        } elseif (is_string($raw)) {
            try {
                $decoded = json_decode($raw, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // @silent-ok: catégorie 2 — entrée utilisateur attendue, convertie en erreur de validation bloquante ($errors), affichée et import avorté
                $errors[] = "$prefix.condition est une chaîne mais n'est pas un JSON valide (profondeur maximale : " . self::JSON_MAX_DEPTH . '). Utilisez un objet {field, op, value} ou supprimez la propriété.';
                return;
            }
            if (!is_array($decoded)) {
                $errors[] = "$prefix.condition doit décoder vers un objet {field, op, value}. Trouvé : " . gettype($decoded) . '.';
                return;
            }
            $cond = $decoded;
        } else {
            $errors[] = "$prefix.condition doit être un objet {field, op, value}, une chaîne JSON valide, ou null/absent.";
            return;
        }

        /** @var array<string, mixed> $cond */
        self::rejectUnknownKeys($cond, self::CONDITION_KEYS, "$prefix.condition", $errors);

        $cond_field = $cond['field'] ?? null;
        if (!is_string($cond_field) || $cond_field === '') {
            $errors[] = "$prefix.condition.field est requis et doit être une chaîne (nom technique du champ validateur).";
        } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $cond_field)) {
            $warnings[] = "$prefix.condition.field = \"$cond_field\" n'est pas en snake_case valide. Format attendu : minuscules, chiffres et underscores, commençant par une lettre.";
        } elseif (!in_array(strtolower($cond_field), $validator_field_names, true)) {
            $errors[] = "$prefix.condition.field = \"$cond_field\" ne correspond à aucun champ validateur (filled_by=\"validator\") de ce formulaire. Les conditions ne peuvent porter que sur des champs remplis par un validateur, pas sur un champ demandeur. Le champ référencé serait ignoré silencieusement par le moteur de workflow.";
        }

        $cond_op = $cond['op'] ?? '';
        if (!is_string($cond_op) || !in_array($cond_op, ConditionEvaluator::VALID_OPS, true)) {
            $errors[] = "$prefix.condition.op doit être l'une des valeurs : " . implode(', ', array_map(static fn(string $o): string => "\"$o\"", ConditionEvaluator::VALID_OPS)) . '. Trouvé : ' . json_encode($cond_op);
        }

        if (!array_key_exists('value', $cond)) {
            return;
        }
        $cond_value = $cond['value'];
        // B-FIX3c (2026-09-01) : value chaîne OU tableau de scalaires
        // (l'op "in" supporte les deux — cf. ConditionEvaluator)
        if (is_array($cond_value)) {
            if (!array_is_list($cond_value)) {
                $errors[] = "$prefix.condition.value doit être une chaîne ou une liste JSON de scalaires (op \"in\").";
            } elseif (count($cond_value) > self::MAX_CONDITION_VALUES) {
                $errors[] = "$prefix.condition.value contient " . count($cond_value) . ' valeur(s). Maximum : ' . self::MAX_CONDITION_VALUES . '.';
            } else {
                foreach ($cond_value as $v) {
                    if (!is_string($v) && !is_numeric($v)) {
                        $errors[] = "$prefix.condition.value doit être une chaîne ou une liste de chaînes/scalaires (op \"in\"). Élément invalide : " . json_encode($v);
                        break;
                    }
                }
            }
        } elseif (is_string($cond_value)) {
            if (strlen($cond_value) > self::MAX_OPTION_LENGTH) {
                $errors[] = "$prefix.condition.value est trop long. Maximum : " . self::MAX_OPTION_LENGTH . ' caractères.';
            }
        } else {
            $warnings[] = "$prefix.condition.value devrait être une chaîne ou un tableau de chaînes (op \"in\"). Trouvé : " . gettype($cond_value);
        }
    }

    /**
     * Drapeau strict : booléen ou 0/1 explicite (entier ou chaîne).
     * Aucune autre valeur n'est convertie.
     */
    public static function isStrictFlag(mixed $value): bool
    {
        return is_bool($value)
            || (is_int($value) && ($value === 0 || $value === 1))
            || (is_string($value) && ($value === '0' || $value === '1'));
    }

    /**
     * Normalise un drapeau déjà validé par {@see isStrictFlag()} en 0/1.
     */
    public static function normalizeFlag(mixed $value): int
    {
        return ((bool) $value) ? 1 : 0;
    }

    /**
     * Entier strict : int PHP ou chaîne de chiffres (avec signe optionnel).
     */
    private static function toStrictInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Formate les résultats de validation en HTML avec bloc copiable pour LLM.
     *
     * @param array{valid: bool, errors: list<string>, warnings: list<string>} $result
     */
    public static function formatResults(array $result): string
    {
        $html = '';

        if ($result['errors'] !== []) {
            $html .= '<div class="msg-error u-mb-05" role="alert" aria-live="assertive"><strong>' . count($result['errors']) . ' erreur(s)</strong> bloquante(s) :';
            $html .= '<ul class="u-fs-sm-ml-12-mt-05">';
            foreach ($result['errors'] as $e) {
                $html .= '<li>' . \App\Core\App::html()->escape($e) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ($result['warnings'] !== []) {
            $html .= '<div class="msg-warning u-mb-05-p-075-bg-warn" role="status" aria-live="polite"><strong>' . count($result['warnings']) . ' avertissement(s)</strong> (non bloquant) :';
            $html .= '<ul class="u-fs-sm-ml-12-mt-05">';
            foreach ($result['warnings'] as $w) {
                $html .= '<li>' . \App\Core\App::html()->escape($w) . '</li>';
            }
            $html .= '</ul></div>';
        }

        // Copyable text block for LLM feedback
        if ($result['errors'] !== [] || $result['warnings'] !== []) {
            $copy_text = "Le JSON généré contient des erreurs. Merci de corriger et de régénérer le JSON.\n\n";
            if ($result['errors'] !== []) {
                $copy_text .= "ERREURS BLOQUANTES :\n";
                foreach ($result['errors'] as $e) {
                    $copy_text .= "- $e\n";
                }
            }
            if ($result['warnings'] !== []) {
                $copy_text .= "\nAVERTISSEMENTS :\n";
                foreach ($result['warnings'] as $w) {
                    $copy_text .= "- $w\n";
                }
            }
            $html .= '<div class="u-mt-075">';
            $html .= '<label class="u-fs-sm2-fw-bold">Message à copier-coller à l\'IA pour corriger le JSON : ';
            $html .= '<button type="button" data-copy-target="#validation-feedback" class="btn-copy-sm">📋 Copier le message</button></label>';
            $html .= '<pre id="validation-feedback" class="u-fs-xs2-mt-025-p-075-bg-dark">' . \App\Core\App::html()->escape($copy_text) . '</pre>';
            $html .= '</div>';
        }

        return $html;
    }
}