<?php
declare(strict_types=1);

/**
 * Rendu HTML de l'assistant d'installation (install.php).
 *
 * Wrapper backward-compatible — délègue à App\Render\InstallRenderer.
 *
 * @package lib
 * @see /install.php
 */

function install_page_css(): string {
    return (new \App\Render\InstallRenderer())->pageCss();
}

function render_install_stepper(int $step): string {
    return (new \App\Render\InstallRenderer())->renderStepper($step);
}

function render_install_messages(array $messages, array $error_messages): string {
    return (new \App\Render\InstallRenderer())->renderMessages($messages, $error_messages);
}

function render_install_step1(array $prerequisites, bool $all_prereqs_ok): string {
    return (new \App\Render\InstallRenderer())->renderStep1($prerequisites, $all_prereqs_ok);
}

function render_install_step2(array $d): string {
    return (new \App\Render\InstallRenderer())->renderStep2($d);
}

function render_install_step3(?array $confirm_config, string $install_dir = ''): string {
    return (new \App\Render\InstallRenderer())->renderStep3($confirm_config, $install_dir);
}

function render_install_page(array $p): void {
    (new \App\Render\InstallRenderer())->renderPage($p);
}
