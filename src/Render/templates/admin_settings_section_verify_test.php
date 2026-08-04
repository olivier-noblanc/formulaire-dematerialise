<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SECTION 2 : Test de vérification email                    -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="card" id="section-email-test">
    <h2><span class="icon">🧪</span> Test de vérification email</h2>
    <p class="caption-2">
        Testez la vérification d'une adresse email avec la configuration actuelle.
        Cela permet de vérifier que le LDAP ou la probe SMTP fonctionne correctement
        avant d'activer la vérification en production.
    </p>
    <form method="POST">
        <?= \App\Core\App::security()->csrfField() ?>
        <input type="hidden" name="action" value="test_verify_email">
        <div class="field">
            <label>Adresse email à tester</label>
            <div class="flex-gap5-3">
                <input type="email" name="verify_test_email" value="<?= \App\Core\App::html()->escape($_POST['verify_test_email'] ?? '') ?>" placeholder="agent@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'exemple.invalid')) ?>" class="u-max-3">
                <button type="submit" class="btn btn-test">Vérifier cette adresse</button>
            </div>
        </div>
    </form>

    <?php if ($verify_result !== null): ?>
        <?php $vr = $verify_result; ?>
        <div class="verify-result <?= $vr['verify']['ok'] ? 'ok' : 'fail' ?>">
            <strong><?= $vr['verify']['ok'] ? '✔ Adresse vérifiée' : '✘ Adresse NON vérifiée' ?></strong>
            <div class="detail">Mode : <code><?= \App\Core\App::html()->escape($vr['mode']) ?></code> — <?= \App\Core\App::html()->escape($vr['verify']['detail']) ?></div>

            <?php if (isset($vr['format_valid'])): ?>
                <div class="detail">Format email : <?= $vr['format_valid'] ? '✔ Valide' : '✘ Invalide' ?></div>
            <?php endif; ?>

            <?php if (isset($vr['ldap'])): ?>
                <div class="detail u-fon-mar">Résultat LDAP :</div>
                <div class="detail">✔/✘ : <?= $vr['ldap']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= \App\Core\App::html()->escape($vr['ldap']['detail']) ?></div>
            <?php endif; ?>

            <?php if (isset($vr['smtp'])): ?>
                <div class="detail u-fon-mar">Résultat SMTP :</div>
                <div class="detail">✔/✘ : <?= $vr['smtp']['ok'] ? 'OK' : 'ÉCHEC' ?> — <?= \App\Core\App::html()->escape($vr['smtp']['detail']) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
