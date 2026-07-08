<?php
declare(strict_types=1);

/**
 * JSON schema validation for form import/export.
 *
 * validate_form_json() — valide la structure JSON d'un formulaire avant import
 * format_validation_results() — formate les erreurs/warnings en HTML + bloc copiable pour LLM
 *
 * @package lib
 */

// ── JSON Schema Validation ─────────────────────────────────────
/**
 * Validate a form JSON against the expected schema (v1.0).
 * Returns an array: ['valid' => bool, 'errors' => string[], 'warnings' => string[]]
 * Errors = blocking issues, Warnings = non-blocking suggestions.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function validate_form_json(array $data): array {
    $errors = [];
    $warnings = [];
    $valid_field_types = ['text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file'];

    // ── Top-level structure ──────────────────────────────────
    if (!isset($data['schema_version'])) {
        $warnings[] = 'Propriété "schema_version" manquante. Ajoutez "schema_version": "1.0" pour garantir la compatibilité future.';
    } elseif ($data['schema_version'] !== '1.0') {
        $warnings[] = 'schema_version = "' . $data['schema_version'] . '". La version supportée est "1.0". L\'import sera tenté mais pourrait échouer.';
    }

    // ── form object ─────────────────────────────────────────
    if (!isset($data['form']) || !is_array($data['form'])) {
        $errors[] = 'Propriété "form" manquante ou n\'est pas un objet. Attendu : { "form": { "label": "...", "description": "..." } }';
    } else {
        if (empty($data['form']['label']) || !is_string($data['form']['label'])) {
            $errors[] = 'form.label est requis et doit être une chaîne de caractères non vide.';
        } elseif (strlen($data['form']['label']) > 255) {
            $errors[] = 'form.label est trop long (' . strlen($data['form']['label']) . ' caractères). Maximum : 255.';
        }
        if (isset($data['form']['description']) && !is_string($data['form']['description'])) {
            $errors[] = 'form.description doit être une chaîne de caractères.';
        }
        if (isset($data['form']['actif']) && !is_bool($data['form']['actif']) && !is_numeric($data['form']['actif'])) {
            $warnings[] = 'form.actif devrait être true/false ou 1/0. Trouvé : ' . gettype($data['form']['actif']);
        }
    }

    // ── fields array ────────────────────────────────────────
    if (!isset($data['fields'])) {
        $errors[] = 'Propriété "fields" manquante. Le JSON doit contenir un tableau "fields" (même vide) avec la définition des champs du formulaire.';
    } elseif (!is_array($data['fields'])) {
        $errors[] = '"fields" doit être un tableau. Trouvé : ' . gettype($data['fields']);
    } else {
        if (count($data['fields']) === 0) {
            $warnings[] = 'Le tableau "fields" est vide. Le formulaire n\'aura aucun champ — l\'utilisateur ne pourra rien saisir.';
        }
        $seen_field_names = [];
        foreach ($data['fields'] as $i => $f) {
            $idx = $i + 1;
            $prefix = "fields[$idx]";

            if (!is_array($f)) {
                $errors[] = "$prefix n'est pas un objet. Chaque champ doit être un objet { label, field_type, field_name, ... }.";
                continue;
            }

            // label
            if (empty($f['label']) || !is_string($f['label'])) {
                $errors[] = "$prefix.label est requis et doit être une chaîne non vide.";
            }

            // field_type
            if (empty($f['field_type'])) {
                $errors[] = "$prefix.field_type est requis. Valeurs possibles : " . implode(', ', array_map(fn($t) => '"'.$t.'"', $valid_field_types));
            } elseif (!in_array($f['field_type'], $valid_field_types, true)) {
                $errors[] = "$prefix.field_type = \"{$f['field_type']}\" n'est pas valide. Valeurs possibles : " . implode(', ', array_map(fn($t) => '"'.$t.'"', $valid_field_types));
            }

            // field_name
            if (!empty($f['field_name'])) {
                if (!is_string($f['field_name'])) {
                    $errors[] = "$prefix.field_name doit être une chaîne. Trouvé : " . gettype($f['field_name']);
                } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $f['field_name'])) {
                    $warnings[] = "$prefix.field_name = \"{$f['field_name']}\" n'est pas en snake_case valide. Format attendu : minuscules, chiffres et underscores uniquement, commençant par une lettre. Exemple : \"date_arrivee\", \"type_demande\".";
                }
                if (in_array(strtolower($f['field_name']), $seen_field_names)) {
                    $errors[] = "$prefix.field_name = \"{$f['field_name']}\" est en doublon. Chaque champ doit avoir un field_name unique.";
                }
                $seen_field_names[] = strtolower($f['field_name']);
            } else {
                $warnings[] = "$prefix.field_name est vide. Un nom technique sera généré automatiquement depuis le label, mais il est recommandé de le fournir explicitement en snake_case.";
            }

            // options for select
            if (($f['field_type'] ?? '') === 'select') {
                if (empty($f['options']) || !is_array($f['options'])) {
                    $errors[] = "$prefix : field_type = \"select\" mais \"options\" est manquant ou n'est pas un tableau. Exemple : \"options\": [\"Option A\", \"Option B\"]";
                } else {
                    foreach ($f['options'] as $j => $opt) {
                        if (!is_string($opt) || trim($opt) === '') {
                            $errors[] = "$prefix.options[" . ($j+1) . "] doit être une chaîne non vide.";
                        }
                    }
                    if (count($f['options']) < 2) {
                        $warnings[] = "$prefix : field_type = \"select\" mais options ne contient que " . count($f['options']) . " valeur(s). Un sélecteur devrait avoir au moins 2 options.";
                    }
                }
            } elseif (isset($f['options']) && $f['field_type'] !== 'select') {
                $warnings[] = "$prefix : field_type = \"{$f['field_type']}\" mais \"options\" est renseigné. Seul le type \"select\" utilise les options.";
            }

            // required
            if (isset($f['required']) && !is_bool($f['required']) && !is_numeric($f['required'])) {
                $warnings[] = "$prefix.required devrait être true/false ou 1/0. Trouvé : " . json_encode($f['required']);
            }

            // ordre
            if (isset($f['ordre']) && (!is_numeric($f['ordre']) || $f['ordre'] < 1)) {
                $warnings[] = "$prefix.ordre devrait être un entier >= 1. Trouvé : " . json_encode($f['ordre']);
            }

            // card_group
            if (isset($f['card_group']) && !is_string($f['card_group'])) {
                $errors[] = "$prefix.card_group doit être une chaîne de caractères.";
            } elseif (empty($f['card_group'])) {
                $warnings[] = "$prefix.card_group est vide. Il sera placé dans le groupe \"Général\" par défaut. Recommandé : regrouper les champs par thème (ex: \"Identité\", \"Affectation\", \"IT\").";
            }

            // hint
            if (isset($f['hint']) && !is_string($f['hint'])) {
                $errors[] = "$prefix.hint doit être une chaîne de caractères.";
            }

            // ── P2-A : validation filled_by et validator_step ──
            if (isset($f['filled_by'])) {
                if (!is_string($f['filled_by'])) {
                    $errors[] = "$prefix.filled_by doit être une chaîne. Trouvé : " . gettype($f['filled_by']);
                } elseif (!in_array($f['filled_by'], ['demandeur', 'validator'], true)) {
                    $errors[] = "$prefix.filled_by = \"{$f['filled_by']}\" n'est pas valide. Valeurs attendues : \"demandeur\" ou \"validator\".";
                }
            }

            if (isset($f['validator_step']) && $f['validator_step'] !== '' && !is_string($f['validator_step'])) {
                $errors[] = "$prefix.validator_step doit être une chaîne (UUID du step, label du step, ou chaîne vide).";
            }

            // Cohérence : si filled_by='validator' et validator_step vide → warning
            if (($f['filled_by'] ?? '') === 'validator' && empty($f['validator_step'])) {
                $warnings[] = "$prefix : filled_by = \"validator\" mais validator_step est vide. Le champ sera disponible sur TOUTES les étapes (champ global). Précisez validator_step pour le limiter à une étape.";
            }

            // Cohérence : si filled_by='demandeur' et validator_step renseigné → warning
            if (($f['filled_by'] ?? 'demandeur') === 'demandeur' && !empty($f['validator_step'])) {
                $warnings[] = "$prefix : filled_by = \"demandeur\" mais validator_step est renseigné. validator_step sera ignoré pour les champs demandeur. Mettez filled_by = \"validator\" si vous voulez un champ validateur.";
            }

            // Si filled_by='validator', vérifier que validator_step correspond à un step existant
            if (($f['filled_by'] ?? '') === 'validator' && !empty($f['validator_step'])) {
                $step_match = false;
                foreach ($data['steps'] ?? [] as $s) {
                    if (($s['label'] ?? '') === $f['validator_step'] || ($s['id'] ?? '') === $f['validator_step']) {
                        $step_match = true;
                        break;
                    }
                }
                if (!$step_match) {
                    $warnings[] = "$prefix : validator_step = \"{$f['validator_step']}\" ne correspond à aucune étape définie dans \"steps\". Le champ validator ne sera jamais affiché. Utilisez le label exact d'une étape existante.";
                }
            }

            // ── FILE-VISIBILITY : validation du champ visibility ──
            // 'all'        = visible par tous (validateurs + owner) — défaut, comportement historique.
            // 'owner_only' = visible uniquement par l'owner du formulaire (caché des validateurs).
            // Na de sens que pour field_type='file' ; un warning est émis sinon.
            if (isset($f['visibility'])) {
                if (!is_string($f['visibility'])) {
                    $errors[] = "$prefix.visibility doit être une chaîne.";
                } elseif (!in_array($f['visibility'], ['all', 'owner_only'], true)) {
                    $errors[] = "$prefix.visibility = \"{$f['visibility']}\" n'est pas valide. Valeurs attendues : \"all\" ou \"owner_only\".";
                }
            }
            // Warning si visibility='owner_only' mais field_type != 'file'
            if (($f['visibility'] ?? 'all') === 'owner_only' && ($f['field_type'] ?? '') !== 'file') {
                $warnings[] = "$prefix : visibility = \"owner_only\" mais field_type n'est pas \"file\". visibility sera ignoré.";
            }
        }
    }

    // ── steps array ─────────────────────────────────────────
    if (!isset($data['steps'])) {
        $warnings[] = 'Propriété "steps" manquante. Le formulaire sera créé sans circuit de validation. Ajoutez un tableau "steps" pour définir le workflow. Exemple : { "steps": [{ "label": "Validation manager", "ordre": 1, "actif": true, "recipients": ["manager@' . \App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr') . '"] }] }';
    } elseif (!is_array($data['steps'])) {
        $errors[] = '"steps" doit être un tableau. Trouvé : ' . gettype($data['steps']);
    } else {
        if (count($data['steps']) === 0) {
            $warnings[] = 'Le tableau "steps" est vide. Le formulaire n\'aura aucun circuit de validation — les demandes ne pourront pas être approuvées.';
        }
        $seen_ordres = [];
        foreach ($data['steps'] as $i => $s) {
            $idx = $i + 1;
            $prefix = "steps[$idx]";

            if (!is_array($s)) {
                $errors[] = "$prefix n'est pas un objet. Chaque étape doit être un objet { label, ordre, actif, recipients }.";
                continue;
            }

            // label
            if (empty($s['label']) || !is_string($s['label'])) {
                $errors[] = "$prefix.label est requis et doit être une chaîne non vide. Exemple : \"Validation manager\", \"Validation RH\".";
            }

            // ordre
            if (!isset($s['ordre'])) {
                $warnings[] = "$prefix.ordre est manquant. L'ordre sera auto-incrémenté, mais il est recommandé de le spécifier explicitement.";
            } elseif (!is_numeric($s['ordre']) || $s['ordre'] < 1) {
                $errors[] = "$prefix.ordre doit être un entier >= 1. Trouvé : " . json_encode($s['ordre']);
            } else {
                $o = (int)$s['ordre'];
                if (in_array($o, $seen_ordres)) {
                    $warnings[] = "$prefix.ordre = $o est en doublon avec une autre étape. Les étapes de même ordre sont validées en parallèle — assurez-vous que c'est intentionnel.";
                }
                $seen_ordres[] = $o;
            }

            // actif
            if (isset($s['actif']) && !is_bool($s['actif']) && !is_numeric($s['actif'])) {
                $warnings[] = "$prefix.actif devrait être true/false ou 1/0.";
            }

            // v19 — Condition d'exécution (branches conditionnelles)
            // Accepte :
            //   - null / absent / '' → pas de condition (exécuter toujours)
            //   - objet {field, op, value} → on valide le format
            //   - string JSON → on valide que c'est du JSON décodable
            $raw_cond = $s['condition'] ?? null;
            $valid_ops_json = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];
            if ($raw_cond !== null && $raw_cond !== '') {
                if (is_array($raw_cond)) {
                    if (empty($raw_cond['field']) || !is_string($raw_cond['field'])) {
                        $errors[] = "$prefix.condition.field est requis et doit être une chaîne (nom technique du champ validateur).";
                    } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', (string)$raw_cond['field'])) {
                        $warnings[] = "$prefix.condition.field = \"{$raw_cond['field']}\" n'est pas en snake_case valide. Format attendu : minuscules, chiffres et underscores, commençant par une lettre.";
                    }
                    $cond_op = $raw_cond['op'] ?? '';
                    if (!is_string($cond_op) || !in_array($cond_op, $valid_ops_json, true)) {
                        $errors[] = "$prefix.condition.op doit être l'une des valeurs : " . implode(', ', array_map(fn($o) => "\"$o\"", $valid_ops_json)) . ". Trouvé : " . json_encode($cond_op);
                    }
                    if (isset($raw_cond['value']) && !is_string($raw_cond['value'])) {
                        $warnings[] = "$prefix.condition.value devrait être une chaîne. Trouvé : " . gettype($raw_cond['value']);
                    }
                } elseif (is_string($raw_cond)) {
                    $decoded_cond = json_decode($raw_cond, true);
                    if (!is_array($decoded_cond)) {
                        $errors[] = "$prefix.condition est une chaîne mais n'est pas un JSON valide. Utilisez un objet {field, op, value} ou supprimez la propriété.";
                    }
                } else {
                    $errors[] = "$prefix.condition doit être un objet {field, op, value}, une chaîne JSON valide, ou null/absent.";
                }

                // Warning si une condition est définie sur une étape d'ordre 1
                // (la première étape s'exécute toujours — la condition sera
                // évaluée mais n'a généralement pas de sens, sauf si l'admin
                // sait vraiment ce qu'il fait).
                $s_ordre = $s['ordre'] ?? null;
                if (is_numeric($s_ordre) && (int)$s_ordre === 1) {
                    $warnings[] = "$prefix : une condition d'exécution est définie sur une étape d'ordre 1. La première étape s'exécute toujours à la création de la soumission — la condition sera évaluée mais peut ne pas avoir d'effet (le champ validateur référencé n'est pas encore rempli).";
                }
            }

            // recipients
            if (!isset($s['recipients']) || !is_array($s['recipients'])) {
                $errors[] = $prefix . '.recipients est requis et doit être un tableau d\'adresses email. Exemple : ["manager@' . \App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr') . '", "rh@' . \App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr') . '"]';
            } else {
                if (count($s['recipients']) === 0) {
                    $warnings[] = "$prefix.recipients est vide. L\'étape n\'aura aucun validateur — personne ne pourra approuver cette étape.";
                }
                foreach ($s['recipients'] as $j => $email) {
                    if (!is_string($email)) {
                        $errors[] = "$prefix.recipients[" . ($j+1) . "] doit être une chaîne (adresse email ou référence {{field_name}}).";
                    } elseif (preg_match('/^\{\{[a-z][a-z0-9_]*\}\}$/', $email)) {
                        // Référence dynamique {{field_name}} — valide
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "$prefix.recipients[" . ($j+1) . "] = \"$email\" n'est ni une adresse email valide ni une référence {{field_name}}. Format attendu : prenom.nom@" . \App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr') . ", service@" . \App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr') . " ou {{nom_du_champ}}";
                    }
                }
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
 * Format validation results as an HTML message block with copyable text for LLM feedback.
 *
 * @param array<string, mixed> $result
 */
function format_validation_results(array $result): string {
    $html = '';

    if (!empty($result['errors'])) {
        $html .= '<div class="msg-error" role="alert" aria-live="assertive" style="margin-bottom:.5rem;"><strong>' . count($result['errors']) . ' erreur(s)</strong> bloquante(s) :';
        $html .= '<ul style="margin:.5rem 0 0 1.2rem;font-size:.85rem;">';
        foreach ($result['errors'] as $e) {
            $html .= '<li>' . h($e) . '</li>';
        }
        $html .= '</ul></div>';
    }

    if (!empty($result['warnings'])) {
        $html .= '<div class="msg-warning" role="status" aria-live="polite" style="margin-bottom:.5rem;background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:.75rem 1rem;border-radius:6px;"><strong>' . count($result['warnings']) . ' avertissement(s)</strong> (non bloquant) :';
        $html .= '<ul style="margin:.5rem 0 0 1.2rem;font-size:.85rem;">';
        foreach ($result['warnings'] as $w) {
            $html .= '<li>' . h($w) . '</li>';
        }
        $html .= '</ul></div>';
    }

    // Copyable text block for LLM feedback
    if (!empty($result['errors']) || !empty($result['warnings'])) {
        $copy_text = "Le JSON généré contient des erreurs. Merci de corriger et de régénérer le JSON.\n\n";
        if (!empty($result['errors'])) {
            $copy_text .= "ERREURS BLOQUANTES :\n";
            foreach ($result['errors'] as $e) {
                $copy_text .= "- $e\n";
            }
        }
        if (!empty($result['warnings'])) {
            $copy_text .= "\nAVERTISSEMENTS :\n";
            foreach ($result['warnings'] as $w) {
                $copy_text .= "- $w\n";
            }
        }
        $html .= '<div style="margin-top:.75rem;">';
        $html .= '<label style="font-size:.82rem;font-weight:bold;">Message à copier-coller à l\'IA pour corriger le JSON : ';
        $html .= '<button type="button" onclick="(function(btn){var txt=document.getElementById(\'validation-feedback\').innerText;try{navigator.clipboard.writeText(txt).then(function(){btn.textContent=\'✓ Copié !\';setTimeout(function(){btn.textContent=\'📋 Copier le message\'},2000)}).catch(function(){var ta=document.createElement(\'textarea\');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand(\'copy\');document.body.removeChild(ta);btn.textContent=\'✓ Copié !\';setTimeout(function(){btn.textContent=\'📋 Copier le message\'},2000)})}catch(e){var ta=document.createElement(\'textarea\');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand(\'copy\');document.body.removeChild(ta);btn.textContent=\'✓ Copié !\';setTimeout(function(){btn.textContent=\'📋 Copier le message\'},2000)}})(this)" style="font-size:.75rem;padding:.2rem .6rem;margin-left:.5rem;cursor:pointer;background:var(--c-primary);color:#fff;border:none;border-radius:4px;">📋 Copier le message</button></label>';
        $html .= '<pre id="validation-feedback" style="background:#1e293b;color:#e2e8f0;padding:.75rem;border-radius:6px;font-size:.78rem;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-height:250px;overflow-y:auto;margin-top:.25rem;">' . h($copy_text) . '</pre>';
        $html .= '</div>';
    }

    return $html;
}
