<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\FieldType;

/**
 * Validation des données POST d'un formulaire dynamique.
 *
 * Extrait de FormController pour réduire la taille du contrôleur.
 * Méthodes statiques pures — utilisent les superglobales ($_POST, $_FILES)
 * directement comme le faisait le code historique.
 */
final class FormValidationHandler
{
    /**
     * Valide les champs obligatoires (sauf File) et le format email.
     *
     * @param list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $form_fields
     * @return array<string, string> Erreurs indexées par field_name
     */
    public static function validateFields(array $form_fields): array
    {
        $errors = [];
        foreach ($form_fields as $field) {
            $field_type = (string) $field['field_type'];
            $field_name = (string) $field['field_name'];

            if ($field_type === FieldType::File->value) {
                continue; // les fichiers sont validés dans validateFiles()
            }
            $value = trim((string) ($_POST[$field_name] ?? ''));
            // B-FIX1 (2026-09-01) : '0' est une valeur légitime (option de select,
            // quantité...) — seul l'absence de valeur ('') bloque un champ requis
            if ((bool) $field['required'] && $value === '') {
                $errors[$field_name] = 'Ce champ est obligatoire';
                continue;
            }
            // B-F1 : validation format email pour les champs field_type=email
            if ($value !== '' && $field_type === FieldType::Email->value
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field_name] = 'Adresse email invalide';
            }
        }
        return $errors;
    }

    /**
     * Filtre les champs dont la condition d'affichage n'est pas satisfaite.
     *
     * B-FIX2 (2026-09-01) : les champs conditionnels masqués côté client
     * (data-condition non satisfaite, géré par JS) ne doivent pas être
     * requis côté serveur — sinon la soumission est bloquée par des champs
     * invisibles. La condition est évaluée sur les données POST en réutilisant
     * ConditionEvaluator (source unique de vérité, mêmes sémantiques que le
     * rendu). Une condition vide/invalide est évaluée à true → le champ
     * reste validé (comportement conservateur).
     *
     * @param array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $form_fields
     * @param array<string, mixed> $post_data
     * @return list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public static function filterConditionallyHidden(array $form_fields, array $post_data): array
    {
        return array_values(array_filter(
            $form_fields,
            fn(array $f): bool => \App\Workflow\ConditionEvaluator::evaluateFieldCondition($f, $post_data)
        ));
    }

    /**
     * Valide les fichiers uploadés obligatoires.
     *
     * @param list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $form_fields
     * @return array<string, string> Erreurs indexées par field_name
     */
    public static function validateFiles(array $form_fields): array
    {
        $errors = [];
        foreach ($form_fields as $field) {
            $field_type = (string) $field['field_type'];
            $field_name = (string) $field['field_name'];

            if ($field_type === FieldType::File->value) {
                if ((bool) $field['required'] && (!isset($_FILES[$field_name]['name']) || $_FILES[$field_name]['name'] === '')) {
                    $errors[$field_name] = 'Ce fichier est obligatoire';
                }
            }
        }
        return $errors;
    }

    /**
     * Valide le consentement RGPD.
     *
     * @return string|null Message d'erreur si absent, null si OK
     */
    public static function validateRgpdConsent(): ?string
    {
        if (($_POST['rgpd_consent'] ?? '') === '') {
            return 'Vous devez accepter le traitement de vos données pour soumettre le formulaire.';
        }
        return null;
    }
}
