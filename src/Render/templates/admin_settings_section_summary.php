<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 5 : Résumé de sécurité email                      -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card mt-15-2" id="section-email-summary">
    <h2><span class="icon">📋</span> Résumé de sécurité email</h2>
    <table class="progress-fill-3">
        <tr class="u-bor-2">
            <td class="u-fon-pad">Mode Dry-Run</td>
            <td class="p-5"><?= $mail_dry_run === '1' ? '<span class="u-col-fon-6">Activé</span> — Aucun email réel' : '<span class="u-col-fon-4">Désactivé</span> — Envois réels actifs' ?></td>
        </tr>
        <tr class="u-bor-2">
            <td class="u-fon-pad">Vérification destinataires</td>
            <td class="p-5">
                <?php if ($email_verify_mode === 'none'): ?>
                    <span class="text-f44336">Désactivée</span>
                <?php elseif ($email_verify_mode === 'ldap'): ?>
                    <span class="text-4caf50">LDAP / Active Directory</span>
                    <?php if ($ldap_host !== '' && $ldap_host !== '0'): ?> (<?= \App\Core\App::html()->escape($ldap_host) ?>)<?php endif; ?>
                <?php elseif ($email_verify_mode === 'smtp'): ?>
                    <span class="text-info">SMTP (probe RCPT TO)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr class="u-bor-2">
            <td class="u-fon-pad">Extension LDAP PHP</td>
            <td class="p-5"><?= $ldap_ext_available ? '<span class="text-4caf50">Disponible</span>' : '<span class="text-f44336">Non disponible</span>' ?></td>
        </tr>
        <tr class="u-bor-2">
            <td class="u-fon-pad">PHPMailer</td>
            <td class="p-5">
                <?php
                /** @phpstan-ignore-next-line */
                if (method_exists(\PHPMailer\PHPMailer\PHPMailer::class, 'getSMTPInstance')): ?>
                    <span class="text-4caf50">Vraie bibliothèque</span>
                <?php else: ?>
                    <span class="text-ff9800">Stub (aucun envoi réel possible)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td class="u-fon-pad">Blocage CLI</td>
            <td class="p-5"><span class="text-4caf50">Actif</span> — Les scripts CLI ne peuvent pas envoyer d'emails sans <code>CLI_MAIL_ALLOWED</code></td>
        </tr>
    </table>

    <?php
    $security_score = 0;
    $security_items = [];
    if ($mail_dry_run === '1') {
        $security_score++;
        $security_items[] = 'Dry-Run activé';
    }
    if ($email_verify_mode !== 'none') {
        $security_score++;
        $security_items[] = 'Vérification destinataires';
    }
    /** @phpstan-ignore-next-line */
    if (!method_exists(\PHPMailer\PHPMailer\PHPMailer::class, 'getSMTPInstance')) {
        $security_score++;
        $security_items[] = 'PHPMailer en mode stub';
    }
    $security_score++;
    $security_items[] = 'Blocage CLI';
    ?>
    <div class="<?= $security_score >= 3 ? 'score-ok' : 'score-warn' ?>">
        <strong>Niveau de sécurité : <?= $security_score ?>/4</strong>
        <div class="hint-muted-3">
            <?= implode(' · ', array_map(fn(string $i) => '✔ ' . $i, $security_items)) ?>
        </div>
        <?php if ($security_score < 3): ?>
            <div class="hint-warning">
                ⚠ Activez la vérification des destinataires et/ou le mode Dry-Run pour renforcer la sécurité.
            </div>
        <?php endif; ?>
    </div>
</div>
