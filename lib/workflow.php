<?php
declare(strict_types=1);

/**
 * Workflow engine — thin delegation to WorkflowEngine OOP service.
 *
 * All functions delegate to \App\Workflow\WorkflowEngine via the DI container.
 * The procedural API is preserved for backward compatibility with pages/ and tests/.
 *
 * @package lib
 */

// ── MOTEUR WORKFLOW ───────────────────────────────────────────

/**
 * Récupère un token avec tout le contexte métier associé (A-18).
 */
function get_token_with_context(string $token_value): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getTokenWithContext($token_value);
}

/**
 * Récupère un token par son ID avec contexte métier (A-18).
 */
function get_token_by_id_with_context(string $token_id): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getTokenByIdWithContext($token_id);
}

/**
 * Récupère les étapes actives du workflow d'un formulaire avec les destinataires.
 */
function get_workflow_steps(string $form_id): array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getWorkflowSteps($form_id);
}

/**
 * Récupère une soumission avec le label du formulaire associé (A-08).
 */
function get_submission_with_form_label(string $submission_id): ?array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->getSubmissionWithFormLabel($submission_id);
}

/**
 * Résout les références dynamiques {{field_name}} dans une adresse email de destinataire.
 */
function resolve_dynamic_recipient(string $recipient, array $form_data, ?string $submission_id = null): string {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->resolveDynamicRecipient($recipient, $form_data, $submission_id);
}

/**
 * Déclenche la prochaine étape d'une soumission.
 */
function advance_workflow(string $submission_id): void {
    \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->advanceWorkflow($submission_id);
}

/**
 * Valide ou refuse un token.
 *
 * @param string $token   Le token à valider
 * @param string $action  'valider' ou 'refuser'
 * @param string $comment Commentaire optionnel
 * @param string $done_by Email du user logged-on qui a cliqué (v10.0.2)
 * @return array<string, mixed> Résultat
 */
function validate_token(string $token, string $action = 'valider', string $comment = '', string $done_by = ''): array {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->validateToken($token, $action, $comment, $done_by);
}

// ── ACTIVE SUBMISSIONS CHECK ───────────────────────────────────

/**
 * Vérifie si un formulaire a des soumissions actives (en_cours)
 */
function has_active_submissions(string $form_id): int {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->hasActiveSubmissions($form_id);
}

/**
 * Vérifie si une étape a des soumissions actives (tokens en cours sur cette étape)
 */
function has_active_step_submissions(string $step_id): int {
    return \App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)
        ->hasActiveStepSubmissions($step_id);
}
