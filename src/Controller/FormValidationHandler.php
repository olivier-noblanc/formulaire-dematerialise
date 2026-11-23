<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\DateHelper;
use App\Enum\FieldType;
use App\Repository\FormFieldsTrait;

/**
 * Validation des données POST d'un formulaire dynamique.
 *
 * Extrait de FormController pour réduire la taille du contrôleur.
 * Méthodes statiques pures — utilisent les superglobales ($_POST, $_FILES)
 * directement comme le faisait le code historique.
 */
final class FormValidationHandler
{
    /** @phpstan-import-type FormFieldRow from FormFieldsTrait */
    /**
     * Valide les champs obligatoires (sauf File) et le format email.
     *
     * @param list<FormFieldRow> $form_fields
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
            if ($field['required'] === 1 && $value === '') {
                $errors[$field_name] = 'Ce champ est obligatoire';
                continue;
            }
            // B-F1 : validation format email pour les champs field_type=email
            if ($value !== '' && $field_type === FieldType::Email->value
                && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$field_name] = 'Adresse email invalide';
            }
            // FIX-C (2026-09-03) : validation du format des champs date côté
            // serveur via DateHelper::parseDate (source unique de vérité) —
            // JJ/MM/AAAA ou AAAA-MM-JJ, calendrier réel. Le navigateur envoie
            // AAAA-MM-JJ via <input type="date">, mais un POST forgé doit être
            // rejeté avec une erreur explicite.
            if ($value !== '' && $field_type === FieldType::Date->value
                && !DateHelper::parseDate($value) instanceof \DateTimeImmutable) {
                $errors[$field_name] = 'Date invalide (format attendu : JJ/MM/AAAA ou AAAA-MM-JJ)';
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
     * @param list<FormFieldRow> $form_fields
     * @param array<string, mixed> $post_data
     * @return list<FormFieldRow>
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
     * @param list<FormFieldRow> $form_fields
     * @return array<string, string> Erreurs indexées par field_name
     */
    public static function validateFiles(array $form_fields): array
    {
        $errors = [];
        foreach ($form_fields as $field) {
            $field_type = (string) $field['field_type'];
            $field_name = (string) $field['field_name'];

            if ($field_type === FieldType::File->value) {
                if ($field['required'] === 1 && (!isset($_FILES[$field_name]['name']) || $_FILES[$field_name]['name'] === '')) {
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
