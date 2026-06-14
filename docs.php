<?php
require_once __DIR__ . '/helpers.php';

// Determine auth status — this page is accessible to everyone
$is_logged_in = false;
$is_admin     = false;
$user_email   = '';

try {
    $user_email = get_auth_user();
    $is_logged_in = !empty($user_email);
    $is_admin = is_admin_user();
} catch (RuntimeException $e) {
    // AUTH_USER missing — unauthenticated context (e.g. token link)
    $is_logged_in = false;
}

// Récupérer les mentions légales pour la section RGPD
$legal_mentions = '';
try {
    $legal_mentions = get_setting('legal_mentions', '');
} catch (Exception $e) {
    $legal_mentions = '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aide et documentation — Formulaires dématérialisés DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    body { padding: 2rem 1rem; }
    .container { max-width: 900px; padding: 0; }
    h1 { font-size: 1.8rem; margin-bottom: .5rem; }
    h2 { font-size: 1.3rem; }
    .card h2 { text-transform: uppercase; letter-spacing: .05em; font-size: 1rem; }

    /* Page-specific */
    .bandeau-left { display: flex; align-items: center; gap: 1rem; }
    .bandeau-right { display: flex; align-items: center; gap: 1rem; font-size: .8rem; }
    p, li { line-height: 1.7; margin-bottom: .5rem; }
    ul, ol { padding-left: 1.5rem; margin-bottom: 1rem; }
    .toc { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 2rem; }
    .toc h2 { margin-bottom: 1rem; font-size: 1rem; }
    .toc ol { counter-reset: toc; list-style: none; padding-left: 0; }
    .toc li { counter-increment: toc; margin-bottom: .4rem; }
    .toc li::before { content: counter(toc) ". "; color: #003189; font-weight: bold; }
    .toc a { color: #003189; text-decoration: none; }
    .toc a:hover { text-decoration: underline; }

    /* Step numbers */
    .step-num { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: #003189; color: #fff; border-radius: 50%; font-size: .9rem; font-weight: bold; margin-right: .5rem; flex-shrink: 0; }
    .step-row { display: flex; align-items: flex-start; margin-bottom: 1rem; }
    .step-text { flex: 1; }
    .step-text p { margin-bottom: .25rem; }

    /* Details / accordion */
    details { margin-bottom: 1rem; }
    details summary { cursor: pointer; font-weight: bold; color: #003189; padding: .75rem 1rem; background: #f0f0f8; border: 1px solid #ddd; border-radius: 4px; list-style: none; display: flex; align-items: center; gap: .5rem; }
    details summary::before { content: "▸"; font-size: 1rem; transition: transform .2s; display: inline-block; }
    details[open] summary::before { transform: rotate(90deg); }
    details summary:hover { background: #e8eaf6; }
    details .detail-body { padding: 1rem 1.25rem; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; background: #fff; }

    /* Role badges */
    .role-badge { display: inline-block; padding: .2rem .6rem; border-radius: 3px; font-size: .8rem; font-weight: bold; margin-right: .25rem; }
    .role-agent { background: #e3f2fd; color: #1565c0; }
    .role-validator { background: #fff3e0; color: #b45309; }
    .role-admin { background: #fce4ec; color: #c62828; }
    .role-superadmin { background: #f3e5f5; color: #6a1b9a; }

    /* Tables */
    .schema-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-bottom: 1rem; }
    .schema-table th { background: #003189; color: #fff; padding: .5rem .75rem; text-align: left; font-weight: normal; }
    .schema-table td { padding: .4rem .75rem; border-bottom: 1px solid #eee; }
    .schema-table tr:nth-child(even) { background: #f7f7fb; }
    .file-tree { font-family: "Marianne", Arial, sans-serif; font-size: .9rem; background: #f5f5fe; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1rem; line-height: 1.8; }
    .file-tree .dir { font-weight: bold; color: #003189; }
    .file-tree .file { color: #333; }

    /* ═══ Quick Start Visual Guide ═══ */
    .quickstart { display: flex; gap: 1rem; margin: 1.5rem 0; flex-wrap: wrap; justify-content: center; }
    .quickstart-step { flex: 1; min-width: 200px; max-width: 280px; background: #fff; border: 2px solid #e0e0f0; border-radius: 8px; padding: 1.5rem 1.25rem; text-align: center; position: relative; transition: transform .2s, box-shadow .2s; }
    .quickstart-step:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,49,137,.12); }
    .quickstart-step .qs-icon { font-size: 2.5rem; margin-bottom: .5rem; display: block; }
    .quickstart-step .qs-num { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; background: #003189; color: #fff; border-radius: 50%; font-size: 1.1rem; font-weight: bold; margin-bottom: .5rem; }
    .quickstart-step h3 { font-size: 1rem; margin: .5rem 0 .25rem; color: #003189; }
    .quickstart-step p { font-size: .88rem; color: #555; margin: 0; }
    .quickstart-arrow { display: flex; align-items: center; font-size: 1.5rem; color: #003189; font-weight: bold; }

    /* ═══ Tip / Astuce box ═══ */
    .tip-box { background: #fffde7; border-left: 4px solid #f9a825; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
    .tip-box p { margin-bottom: .25rem; }
    .tip-box::before { content: "💡 Astuce"; display: block; font-weight: bold; color: #f57f17; margin-bottom: .25rem; font-size: .9rem; }

    /* ═══ Feature grid ═══ */
    .feature-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; margin: 1rem 0; }
    .feature-item { background: #f8f8ff; border: 1px solid #e0e0f0; border-radius: 6px; padding: 1rem 1.25rem; }
    .feature-item strong { color: #003189; display: block; margin-bottom: .25rem; }
    .feature-item p { font-size: .88rem; color: #555; margin: 0; }

    /* ═══ Permission table ═══ */
    .perm-table { width: 100%; border-collapse: collapse; font-size: .88rem; margin-bottom: 1rem; }
    .perm-table th { background: #003189; color: #fff; padding: .6rem .75rem; text-align: center; font-weight: normal; }
    .perm-table th:first-child { text-align: left; }
    .perm-table td { padding: .5rem .75rem; border-bottom: 1px solid #eee; text-align: center; }
    .perm-table td:first-child { text-align: left; }
    .perm-table tr:nth-child(even) { background: #f7f7fb; }
    .perm-yes { color: #1a6b3c; font-weight: bold; }
    .perm-no { color: #bbb; }

    /* ═══ RGPD box ═══ */
    .rgpd-box { background: #f0f4ff; border: 1px solid #c5cae9; border-radius: 6px; padding: 1.25rem 1.5rem; margin: 1rem 0; }
    .rgpd-box h3 { margin-top: 0; color: #003189; }

    /* ═══ Back-to-top button ═══ */
    .back-to-top { position: fixed; bottom: 2rem; right: 2rem; width: 48px; height: 48px; background: #003189; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 1.5rem; font-weight: bold; box-shadow: 0 2px 8px rgba(0,49,137,.3); z-index: 1000; transition: background .2s; }
    .back-to-top:hover { background: #00205a; }
    .back-to-top:visited { color: #fff; }

    /* ═══ Version badge ═══ */
    .version-badge { display: inline-block; background: #e8eaf6; color: #003189; font-size: .72rem; font-weight: bold; padding: .15rem .55rem; border-radius: 10px; vertical-align: middle; margin-left: .5rem; border: 1px solid #c5cae9; letter-spacing: .02em; }

    /* ═══ Screenshot & Mockup styles ═══ */
    .screenshot { max-width: 100%; border: 1px solid #ddd; border-radius: 6px; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
    .screenshot-caption { font-size: .8rem; color: #888; text-align: center; margin-top: .25rem; margin-bottom: 1.5rem; }
    .mockup { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 1.25rem; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,.06); }

    /* ═══ Workflow mockup ═══ */
    .wf-mockup { display: flex; align-items: center; gap: .5rem; margin: 1rem 0; flex-wrap: wrap; justify-content: center; }
    .wf-step { display: flex; flex-direction: column; align-items: center; gap: .35rem; min-width: 120px; }
    .wf-box { padding: .6rem 1rem; border-radius: 6px; font-size: .85rem; font-weight: bold; text-align: center; min-width: 100px; }
    .wf-box.done { border: 2px solid #1a6b3c; background: #e8f5e9; color: #1a6b3c; }
    .wf-box.current { border: 2px dashed #b45309; background: #fff8e1; color: #b45309; }
    .wf-box.pending { border: 2px solid #bbb; background: #f5f5f5; color: #888; }
    .wf-label { font-size: .75rem; color: #666; }
    .wf-arrow { font-size: 1.3rem; color: #003189; font-weight: bold; flex-shrink: 0; }

    /* ═══ Email mockup ═══ */
    .email-mockup { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin: 1rem 0; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.06); max-width: 520px; }
    .email-header { background: #003189; color: #fff; padding: .75rem 1rem; font-weight: bold; font-size: .9rem; }
    .email-body { padding: 1rem; }
    .email-body table { width: 100%; border-collapse: collapse; font-size: .82rem; margin-bottom: .75rem; }
    .email-body table td { padding: .3rem .5rem; border-bottom: 1px solid #eee; }
    .email-body table td:first-child { font-weight: bold; color: #555; width: 35%; }
    .email-btn { display: inline-block; background: #003189; color: #fff; padding: .5rem 1.25rem; border-radius: 4px; font-weight: bold; font-size: .85rem; text-decoration: none; text-align: center; }
    .email-footer { padding: .5rem 1rem; font-size: .72rem; color: #999; border-top: 1px solid #eee; background: #fafafa; }

    /* ═══ Progress bar mockup ═══ */
    .progress-mockup { margin: 1rem 0; }
    .progress-bar-track { width: 100%; height: 24px; background: #e0e0e0; border-radius: 12px; overflow: hidden; position: relative; }
    .progress-bar-fill { height: 100%; border-radius: 12px; background: linear-gradient(90deg, #1a6b3c 0%, #1a6b3c 40%, #f9a825 40%, #f9a825 50%); width: 50%; }
    .progress-text { font-size: .82rem; color: #555; margin-top: .4rem; font-weight: bold; }
    .progress-steps { display: flex; gap: .75rem; margin-top: .5rem; flex-wrap: wrap; }
    .progress-step-indicator { display: flex; align-items: center; gap: .3rem; font-size: .78rem; }
    .progress-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .progress-dot.green { background: #1a6b3c; }
    .progress-dot.amber { background: #f9a825; }
    .progress-dot.gray { background: #ccc; }

    /* ═══ Status badge mockup ═══ */
    .status-badge { display: inline-block; padding: .25rem .7rem; border-radius: 12px; font-size: .82rem; font-weight: bold; margin-right: .5rem; }
    .status-validated { background: #e8f5e9; color: #1a6b3c; border: 1px solid #a5d6a7; }
    .status-pending { background: #fff8e1; color: #b45309; border: 1px solid #ffe082; }
    .status-refused { background: #fce4ec; color: #c0392b; border: 1px solid #ef9a9a; }

    @media (max-width: 600px) {
      .bandeau { flex-direction: column; text-align: center; }
      .container { padding: 0 .5rem; }
      .quickstart { flex-direction: column; align-items: center; }
      .quickstart-arrow { display: none; }
      .feature-grid { grid-template-columns: 1fr; }
      .wf-mockup { flex-direction: column; }
      .wf-arrow { transform: rotate(90deg); }
      .email-mockup { max-width: 100%; }
    }
  </style>
</head>
<body id="top">
<div class="bandeau">
  <div class="bandeau-left">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span style="opacity:.7;">| Aide</span>
  </div>
  <div class="bandeau-right">
    <?php if ($is_logged_in): ?>
      <span>Connecté : <strong><?= h($user_email) ?></strong></span>
      <?php if ($is_admin): ?>
        <a href="admin_access.php">⚙ Back office</a>
        <a href="admin_settings.php">⚙ Paramètres</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="container">
  <h1>Aide et documentation</h1>
  <p class="subtitle">Guide complet de l'application de formulaires dématérialisés — DREETS <span class="version-badge">v<?= defined('APP_VERSION') ? APP_VERSION : '4.4.0' ?></span></p>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- GUIDE DE DÉMARRAGE RAPIDE                                  -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="demarrage-rapide">
    <h2>🚀 Guide de démarrage rapide</h2>
    <p>Bienvenue ! Voici comment fonctionne l'application en 3 étapes simples :</p>

    <div class="quickstart">
      <div class="quickstart-step">
        <span class="qs-num">1</span>
        <span class="qs-icon">📝</span>
        <h3>Je remplis le formulaire</h3>
        <p>Je me connecte, je choisis mon formulaire, je remplis les champs et j'envoie.</p>
      </div>
      <div class="quickstart-arrow">→</div>
      <div class="quickstart-step">
        <span class="qs-num">2</span>
        <span class="qs-icon">📧</span>
        <h3>Les validateurs reçoivent un email</h3>
        <p>Le système envoie automatiquement un email à chaque personne qui doit valider ma demande.</p>
      </div>
      <div class="quickstart-arrow">→</div>
      <div class="quickstart-step">
        <span class="qs-num">3</span>
        <span class="qs-icon">📊</span>
        <h3>Je suis l'avancement en temps réel</h3>
        <p>Depuis « Mes demandes », je vois qui a validé et où en est ma demande.</p>
      </div>
    </div>

    <!-- ── Workflow visual mockup ── -->
    <div class="mockup">
      <p style="font-weight:bold; color:#003189; margin:0 0 .75rem; font-size:.9rem;">Circuit de validation — Vue d'ensemble</p>
      <div class="wf-mockup">
        <div class="wf-step">
          <div class="wf-box done">✓ Étape 1</div>
          <div class="wf-label">Chef IT</div>
        </div>
        <div class="wf-arrow">→</div>
        <div class="wf-step">
          <div class="wf-box current">⏳ Étape 2</div>
          <div class="wf-label">RH</div>
        </div>
        <div class="wf-arrow">→</div>
        <div class="wf-step">
          <div class="wf-box pending">○ Étape 3</div>
          <div class="wf-label">Direction</div>
        </div>
      </div>
      <p style="font-size:.78rem; color:#888; margin:.5rem 0 0;">Exemple : les étapes s'enchaînent automatiquement après chaque validation</p>
    </div>

    <div class="tip-box">
      <p>Pas de panique : le système s'occupe de tout. Vous n'avez qu'à remplir le formulaire, les emails partent automatiquement et les relances aussi !</p>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- TABLE DES MATIÈRES                                         -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="toc">
    <h2>Table des matières</h2>
    <ol>
      <li><a href="#demarrage-rapide">Guide de démarrage rapide</a></li>
      <li><a href="#guide-agent">Guide de l'agent — Soumettre une demande</a></li>
      <li><a href="#guide-validateur">Guide du validateur — Valider une demande</a></li>
      <li><a href="#guide-administrateur">Guide de l'administrateur — Configurer et superviser</a></li>
      <li><a href="#fonctionnalites">Fonctionnalités de l'application</a></li>
      <li><a href="#roles-permissions">Rôles et permissions</a></li>
      <li><a href="#faq">FAQ — Questions fréquentes</a></li>
      <li><a href="#rgpd-legal">RGPD et mentions légales</a></li>
      <li><a href="#technique">Architecture technique (pour l'équipe IT)</a></li>
    </ol>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 2. GUIDE DE L'AGENT                                        -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-agent">
    <h2>2. Guide de l'agent — Soumettre une demande</h2>

    <p>En tant qu'agent, vous pouvez <strong>remplir un formulaire</strong>, <strong>suivre l'avancement</strong> de vos demandes et <strong>annuler</strong> une demande en cours.</p>

    <!-- ── Accéder à un formulaire ── -->
    <h3>📝 Accéder à un formulaire</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Connectez-vous</strong> — Vous devez être sur le réseau DREETS (l'authentification Windows se fait automatiquement quand vous ouvrez la page).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Ouvrez le formulaire</strong> — Chaque formulaire a une adresse spécifique. Par exemple, le formulaire d'arrivée d'un agent se trouve à :</p>
      </div>
    </div>

    <div class="info-box">
      <p><code>form.php?f=onboarding</code></p>
      <p><small>Le nom « onboarding » est un exemple. Demandez à votre administrateur pour connaître les formulaires disponibles.</small></p>
    </div>

    <div class="tip-box">
      <p>Comment trouver les formulaires ? Regardez dans le menu de navigation ou demandez le lien à votre administrateur. Chaque service a ses propres formulaires.</p>
    </div>

    <img src="docs/screenshots/01_index_agent.png" alt="Page d'accueil de l'agent — liste des formulaires disponibles" class="screenshot">
    <p class="screenshot-caption">Page d'accueil vue par un agent — les formulaires disponibles s'affichent directement</p>

    <!-- ── Remplir le formulaire ── -->
    <h3>✍️ Remplir le formulaire</h3>

    <p>Le formulaire est découpé en plusieurs sections. Prenez votre temps pour les remplir :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Identité</strong> — Renseignez votre nom, prénom, date de naissance, corps/grade, service d'affectation, date de prise de poste, type d'arrivée et quotité.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Informatique (IT)</strong> — Indiquez le type de poste (fixe ou portable), les options nécessaires (double écran, accès RPVN, téléphone pro) et les applicatifs métier requis.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Ressources Humaines</strong> — Cochez les actions RH nécessaires : dossier administratif, mutuelle, visite médicale, habilitation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Logistique</strong> — Précisez le bâtiment/bureau et les besoins : badge d'accès, véhicule de service, EPI.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">5</span>
      <div class="step-text">
        <p><strong>Envoyer</strong> — Quand tout est rempli, cliquez sur le bouton <em>« Envoyer la déclaration »</em>.</p>
      </div>
    </div>

    <div class="warn-box">
      <p><strong>⚠ Champs obligatoires</strong> — Les champs marqués d'une astérisque rouge (*) doivent obligatoirement être remplis. Si vous oubliez un champ obligatoire, le formulaire vous le signalera.</p>
    </div>

    <div class="tip-box">
      <p>Prenez le temps de vérifier vos informations avant d'envoyer. Une fois envoyé, vous ne pouvez plus modifier les données du formulaire.</p>
    </div>

    <img src="docs/screenshots/03_form_onboarding.png" alt="Formulaire d'onboarding — sections à remplir par l'agent" class="screenshot">
    <p class="screenshot-caption">Exemple de formulaire d'arrivée d'un agent (onboarding) — sections Identité, IT, RH, Logistique</p>

    <img src="docs/screenshots/04_form_outboarding.png" alt="Formulaire d'outboarding — restitution du matériel et formalités de fin de contrat" class="screenshot">
    <p class="screenshot-caption">Formulaire de départ d'un agent (outboarding) — restitution du matériel et formalités de fin de contrat</p>

    <!-- ── Après l'envoi ── -->
    <h3>✅ Que se passe-t-il après l'envoi ?</h3>

    <div class="success-box">
      <p><strong>✓ Demande enregistrée !</strong> — Votre demande est bien prise en compte. Le système se charge de tout.</p>
    </div>

    <p>Voici ce qui se passe automatiquement, sans que vous n'ayez rien à faire :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Le circuit de validation démarre</strong> — Les validateurs de la première étape reçoivent un email les informant de votre demande.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Les validations s'enchaînent</strong> — Dès qu'un validateur valide, le validateur suivant est automatiquement prévenu par email.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Les relances automatiques</strong> — Si un validateur tarde à répondre, le système lui envoie un rappel automatique (par défaut après 48 heures).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Demande clôturée</strong> — Quand tous les validateurs ont validé, la demande est automatiquement clôturée avec le statut « Validé ».</p>
      </div>
    </div>

    <!-- ── Suivre l'avancement ── -->
    <h3>📊 Suivre l'avancement de mes demandes</h3>

    <p>Pour voir où en est votre demande, rendez-vous sur la page <strong>« Mes demandes »</strong> (<code>my_submissions.php</code>).</p>

    <!-- ── Progress bar mockup ── -->
    <div class="mockup">
      <p style="font-weight:bold; color:#003189; margin:0 0 .75rem; font-size:.9rem;">Barre de progression d'une demande</p>
      <div class="progress-mockup">
        <div class="progress-bar-track">
          <div class="progress-bar-fill"></div>
        </div>
        <p class="progress-text">2/4 étapes validées</p>
        <div class="progress-steps">
          <span class="progress-step-indicator"><span class="progress-dot green"></span> IT ✓</span>
          <span class="progress-step-indicator"><span class="progress-dot amber"></span> RH ⏳</span>
          <span class="progress-step-indicator"><span class="progress-dot gray"></span> Logistique</span>
          <span class="progress-step-indicator"><span class="progress-dot gray"></span> Direction</span>
        </div>
      </div>
    </div>

    <!-- ── Status badge mockup ── -->
    <div class="mockup">
      <p style="font-weight:bold; color:#003189; margin:0 0 .75rem; font-size:.9rem;">Badges de statut</p>
      <p style="margin:0;">
        <span class="status-badge status-validated">🟢 Validée</span>
        <span class="status-badge status-pending">🟠 En cours</span>
        <span class="status-badge status-refused">🔴 Refusée</span>
      </p>
    </div>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Accédez à « Mes demandes »</strong> — Cliquez sur le lien dans le menu de navigation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Consultez le statut</strong> — Chaque demande affiche son statut avec des badges colorés :</p>
        <ul>
          <li style="color:#1a6b3c;"><strong>■ Vert</strong> = validé (étape terminée)</li>
          <li style="color:#b45309;"><strong>■ Orange</strong> = en attente (étape en cours)</li>
          <li style="color:#888;"><strong>■ Gris</strong> = pas encore démarré</li>
        </ul>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Filtrez les demandes</strong> — Vous pouvez filtrer par statut (en cours, validé, refusé) et chercher par mot-clé.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Voir le détail</strong> — Cliquez sur le bouton « Détail » pour voir l'historique complet des validations et les données du formulaire.</p>
      </div>
    </div>

    <div class="tip-box">
      <p>Vous n'avez pas besoin de relancer les validateurs vous-même. Le système envoie automatiquement des relances si un validateur ne répond pas dans le délai configuré.</p>
    </div>

    <img src="docs/screenshots/05_my_submissions.png" alt="Page Mes demandes — liste des soumissions de l'agent avec statuts" class="screenshot">
    <p class="screenshot-caption">Page « Mes demandes » — chaque soumission affiche son statut et son avancement</p>

    <!-- ── Annuler une demande ── -->
    <h3>❌ Annuler une demande</h3>

    <p>Vous pouvez annuler une demande tant qu'elle est <strong>en cours</strong> :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Allez sur « Mes demandes »</strong> — Retrouvez votre demande dans la liste.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Cliquez sur « Annuler »</strong> — Un bouton d'annulation est disponible pour les demandes en cours.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Confirmez l'annulation</strong> — Après confirmation, la demande est clôturée et les validateurs restants ne recevront plus de relances.</p>
      </div>
    </div>

    <div class="warn-box">
      <p><strong>⚠ Attention :</strong> L'annulation est irréversible. Vous ne pourrez pas rouvrir la demande. Si vous voulez soumettre à nouveau, il faudra remplir un nouveau formulaire.</p>
    </div>

    <!-- ── Droits RGPD ── -->
    <h3>🔒 Mes droits (RGPD)</h3>

    <p>Conformément au Règlement Général sur la Protection des Données, vous disposez de droits sur vos données :</p>
    <ul>
      <li><strong>Droit d'accès</strong> — Vous pouvez consulter toutes les données vous concernant depuis « Mes demandes ».</li>
      <li><strong>Droit de rectification</strong> — Contactez votre administrateur pour corriger des données erronées.</li>
      <li><strong>Droit d'effacement</strong> — Vous pouvez demander la suppression de vos données en contactant l'administrateur ou le CIL DREETS.</li>
      <li><strong>Durée de conservation</strong> — Vos données sont conservées pendant une durée limitée (par défaut 24 mois après la clôture de la demande), puis automatiquement supprimées.</li>
    </ul>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 3. GUIDE DU VALIDATEUR                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-validateur">
    <h2>3. Guide du validateur — Valider une demande</h2>

    <p>En tant que validateur, vous recevez des demandes à traiter. Voici comment ça fonctionne, étape par étape.</p>

    <!-- ── Recevoir un email ── -->
    <h3>📧 Je reçois un email de validation</h3>

    <p>Quand une demande nécessite votre intervention, vous recevez un email de <strong>workflow@dreets.gouv.fr</strong> avec l'objet :</p>

    <div class="info-box">
      <p><code>[Action requise] Nom du formulaire — Nom de l'étape</code></p>
    </div>

    <p>Cet email contient :</p>
    <ul>
      <li>Un <strong>résumé des informations</strong> du formulaire rempli par l'agent</li>
      <li>Un <strong>bouton ou lien</strong> pour accéder à la page de validation</li>
    </ul>

    <!-- ── Email notification mockup ── -->
    <div class="email-mockup">
      <div class="email-header">Onboarding — Action requise</div>
      <div class="email-body">
        <p style="margin:0 0 .75rem; font-size:.85rem;">Bonjour,</p>
        <p style="margin:0 0 .75rem; font-size:.85rem;">Une nouvelle demande nécessite votre validation pour l'étape <strong>Informatique</strong>.</p>
        <table>
          <tr><td>Agent</td><td>Dupont Marie</td></tr>
          <tr><td>Service</td><td>Service Emploi</td></tr>
          <tr><td>Date de prise de poste</td><td>15/03/2025</td></tr>
          <tr><td>Type de poste</td><td>Portable + double écran</td></tr>
        </table>
        <span class="email-btn">✓ Marquer comme effectué</span>
      </div>
      <div class="email-footer">🔒 Lien à usage unique — Ce lien ne fonctionnera plus après validation ou refus.</div>
    </div>

    <div class="tip-box">
      <p>Vous n'avez pas besoin d'être sur le réseau DREETS pour valider. Le lien dans l'email fonctionne depuis n'importe quel poste, sans connexion particulière.</p>
    </div>

    <!-- ── Cliquer sur le lien ── -->
    <h3>🔗 Je clique sur le lien de validation</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Cliquez sur le bouton ou le lien</strong> dans l'email. Vous accédez à la page de validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Lisez les informations</strong> — Vous verrez le libellé de l'étape concernée (ex : « Informatique », « Ressources Humaines ») et les détails du formulaire rempli par l'agent.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Prenez le temps de vérifier</strong> — Les cases cochées sont marquées ✓. Si vous avez des questions, contactez l'agent qui a soumis la demande.</p>
      </div>
    </div>

    <!-- ── Valider ou refuser ── -->
    <h3>✅ Valider ou ❌ Refuser</h3>

    <p>Vous avez deux options :</p>

    <div class="step-row">
      <span class="step-num">A</span>
      <div class="step-text">
        <p><strong>✅ Valider</strong> — Confirme que l'étape est traitée. Le système passe automatiquement à l'étape suivante et envoie un email au validateur suivant.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">B</span>
      <div class="step-text">
        <p><strong>❌ Refuser</strong> — Bloque la demande. Elle est immédiatement clôturée avec le statut « Refusé ». Les étapes suivantes ne seront pas déclenchées.</p>
      </div>
    </div>

    <p>Dans les deux cas, vous pouvez ajouter un <strong>commentaire</strong> (facultatif mais recommandé) pour expliquer votre décision.</p>

    <div class="warn-box">
      <p><strong>⚠ Important :</strong> Le lien de validation est à <strong>usage unique</strong>. Une fois que vous avez cliqué sur Valider ou Refuser, le lien ne fonctionne plus. Si vous voyez « Déjà validé », cela signifie que l'action a déjà été effectuée (par vous ou par un collègue partageant la même adresse email).</p>
    </div>

    <img src="docs/screenshots/15_validate.png" alt="Page de validation — boutons Valider et Refuser" class="screenshot">
    <p class="screenshot-caption">Page de validation — le validateur peut valider ou refuser l'étape avec un commentaire</p>

    <img src="docs/screenshots/16_submission_view.png" alt="Vue détaillée d'une soumission — progression du workflow, délégation et historique" class="screenshot">
    <p class="screenshot-caption">Vue détaillée d'une soumission — progression du workflow, options de délégation et historique des validations</p>

    <!-- ── Après la validation ── -->
    <h3>➡️ Que se passe-t-il après ?</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Si vous avez validé</strong> — Le système envoie automatiquement un email au(x) validateur(s) de l'étape suivante. Vous n'avez rien d'autre à faire.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Si vous avez refusé</strong> — La demande est clôturée et les étapes suivantes ne seront pas déclenchées. L'agent en est informé.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Dernière étape validée</strong> — Quand toutes les étapes sont validées, la demande est clôturée automatiquement avec le statut « Validé ».</p>
      </div>
    </div>

    <!-- ── Déléguer ── -->
    <h3>🔄 Déléguer ma validation</h3>

    <p>Si vous n'êtes pas la bonne personne pour valider, vous pouvez <strong>déléguer</strong> la validation à un collègue :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Allez sur « Mes validations »</strong> (<code>my_validations.php</code>) — Cette page liste toutes les demandes qui attendent votre validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Trouvez la demande</strong> à déléguer et cliquez sur le bouton <strong>« Déléguer »</strong>.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Entrez l'adresse email</strong> de la personne à qui vous déléguez la validation.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Ajoutez un motif</strong> (facultatif) — Expliquez pourquoi vous déléguez.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">5</span>
      <div class="step-text">
        <p><strong>Validez la délégation</strong> — Votre lien de validation est annulé et un nouveau lien est envoyé au délégataire. Il peut alors valider à votre place.</p>
      </div>
    </div>

    <div class="tip-box">
      <p>La délégation est tracée dans l'historique. L'administrateur peut voir qui a délégué à qui et pourquoi.</p>
    </div>

    <!-- ── Suivi des validations ── -->
    <h3>📋 Suivre mes validations</h3>

    <p>La page <strong>« Mes validations »</strong> (<code>my_validations.php</code>) vous permet de :</p>
    <ul>
      <li>Voir les <strong>demandes en attente</strong> de votre validation</li>
      <li>Consulter l'<strong>historique</strong> de vos validations passées</li>
      <li><strong>Déléguer</strong> une validation à un collègue</li>
      <li>Accéder directement au <strong>lien de validation</strong></li>
    </ul>

    <img src="docs/screenshots/06_my_validations.png" alt="Page Mes validations — demandes en attente et historique" class="screenshot">
    <p class="screenshot-caption">Page « Mes validations » — vue des demandes en attente et de l'historique de validation</p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 4. GUIDE DE L'ADMINISTRATEUR                               -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-administrateur">
    <h2>4. Guide de l'administrateur — Configurer et superviser</h2>

    <p>En tant qu'administrateur, vous configurez les formulaires, supervisez les demandes et gérez la conformité RGPD.</p>

    <!-- ── Accès admin ── -->
    <h3>🔑 Obtenir l'accès administrateur</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Accédez à la page</strong> <code>admin_access.php</code> et cliquez sur <em>« Demander l'accès admin »</em>.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Votre demande est envoyée</strong> par email au super administrateur, qui peut l'approuver ou la refuser.</p>
      </div>
    </div>

    <img src="docs/screenshots/09_admin_access.png" alt="Gestion des accès administrateur — demande et approbation" class="screenshot">
    <p class="screenshot-caption">Gestion des accès administrateur — workflow de demande et d'approbation par le super administrateur</p>

    <img src="docs/screenshots/02_index_admin.png" alt="Page d'accueil administrateur — accès au back office" class="screenshot">
    <p class="screenshot-caption">Page d'accueil vue par un administrateur — accès direct au back office et aux outils de gestion</p>

    <!-- ── Tableau de bord ── -->
    <h3>📊 Tableau de bord (Dashboard)</h3>

    <p>Le <strong>dashboard</strong> (<code>dashboard.php</code>) est votre centre de commande. Il affiche :</p>

    <ul>
      <li><strong>Statistiques</strong> — Nombre total de demandes, en cours, clôturées</li>
      <li><strong>Filtres</strong> — Par statut (tous, en cours, clôturés) et par formulaire</li>
      <li><strong>Badges de workflow</strong> — Chaque étape est représentée par un badge coloré :
        <ul>
          <li style="color:#1a6b3c;">■ <strong>Vert</strong> = validé</li>
          <li style="color:#b45309;">■ <strong>Orange</strong> = en attente (étape courante)</li>
          <li style="color:#888;">■ <strong>Gris</strong> = pas encore démarré</li>
        </ul>
      </li>
      <li><strong>Bouton « détail »</strong> — Affiche l'historique des validations et les données du formulaire</li>
      <li><strong>Export CSV</strong> — Téléchargez les données au format tableur</li>
      <li><strong>Relance manuelle</strong> — Relancez individuellement un validateur en attente</li>
      <li><strong>Annulation</strong> — Annulez une demande en cours</li>
    </ul>

    <div class="tip-box">
      <p>Depuis le dashboard, vous pouvez aussi accéder aux pages de détail de chaque soumission pour voir l'historique complet, les pièces jointes et les commentaires des validateurs.</p>
    </div>

    <img src="docs/screenshots/07_dashboard.png" alt="Tableau de bord administrateur — statistiques et liste des demandes" class="screenshot">
    <p class="screenshot-caption">Tableau de bord — vue d'ensemble des demandes avec filtres, badges de workflow et actions rapides</p>

    <!-- ── Gestion des formulaires ── -->
    <h3>📝 Gestion des formulaires</h3>

    <p>Depuis la page <strong>admin_forms.php</strong>, vous pouvez :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Créer un formulaire</strong> — Donnez un libellé (nom affiché) et une description. L'identifiant technique est généré automatiquement à partir du libellé.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Modifier un formulaire</strong> — Changez le libellé, la description ou désactivez-le.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Configurer les champs</strong> — Ajoutez, modifiez ou réorganisez les champs du formulaire (texte, liste déroulante, case à cocher, etc.).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Prévisualiser</strong> — Visualisez le formulaire tel que le verront les agents avant de le publier.</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Astuce :</strong> Pour qu'une étape nécessite la validation de <strong>tous</strong> ses destinataires, mettez-les dans la même étape. Pour qu'ils valident <strong>séquentiellement</strong>, créez des étapes distinctes avec des ordres croissants.</p>
    </div>

    <!-- ── Types de champs ── -->
    <h4>📋 Référence des types de champs</h4>
    <p>Les champs suivants sont disponibles lors de la configuration d'un formulaire :</p>
    <table class="schema-table">
      <thead><tr><th>Type</th><th>Code</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td>📝 Texte court</td><td><code>text</code></td><td>Champ texte simple sur une ligne (nom, prénom, numéro…)</td></tr>
        <tr><td>📅 Date</td><td><code>date</code></td><td>Sélecteur de date (jj/mm/aaaa) — date de naissance, prise de poste…</td></tr>
        <tr><td>📋 Liste déroulante</td><td><code>select</code></td><td>Choix unique parmi une liste prédéfinie (corps/grade, type de poste…)</td></tr>
        <tr><td>☑️ Case à cocher</td><td><code>checkbox</code></td><td>Choix multiples à cocher (options IT, actions RH…)</td></tr>
        <tr><td>📄 Zone de texte</td><td><code>textarea</code></td><td>Champ texte multiligne pour les commentaires ou descriptions longues</td></tr>
        <tr><td>📎 Fichier / Pièce jointe</td><td><code>file</code></td><td>Téléversement de fichier (stockage sécurisé en BDD, accès par lien sécurisé)</td></tr>
      </tbody>
    </table>

    <img src="docs/screenshots/10_admin_forms.png" alt="Page d'administration des formulaires — gestion des formulaires et champs" class="screenshot">
    <p class="screenshot-caption">Administration des formulaires — créer, modifier et configurer les champs et le circuit de validation</p>

    <img src="docs/screenshots/17_form_preview.png" alt="Prévisualisation du formulaire — vue telle que les agents la verront" class="screenshot">
    <p class="screenshot-caption">Prévisualisation du formulaire — l'administrateur peut visualiser le formulaire tel que le verront les agents avant publication</p>

    <!-- ── Gestion des étapes et destinataires ── -->
    <h3>🔄 Gestion des étapes et destinataires</h3>

    <p>Pour chaque formulaire, vous définissez le <strong>circuit de validation</strong> :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Ajoutez des étapes</strong> — Chaque étape a un libellé (ex : « Informatique », « RH », « Direction ») et un numéro d'ordre.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Ordre = séquence</strong> — L'ordre détermine la séquence du workflow :
          <ul>
            <li><strong>Ordres différents</strong> = étapes <strong>séquentielles</strong> (l'étape 2 ne démarre qu'après la validation de l'étape 1)</li>
            <li><strong>Même ordre</strong> = étapes <strong>parallèles</strong> (elles démarrent en même temps)</li>
          </ul>
        </p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Ajoutez des destinataires</strong> — Pour chaque étape, ajoutez les adresses email des validateurs. Plusieurs destinataires sur la même étape recevront tous une notification (validation parallèle).</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Exemple de circuit :</strong><br>
      Ordre 1 : « Informatique » → doit être validé en premier<br>
      Ordre 2 : « Ressources Humaines » + « Logistique » → démarrent en parallèle après l'ordre 1<br>
      Ordre 3 : « Direction » → démarre quand l'ordre 2 est entièrement validé</p>
    </div>

    <div class="tip-box">
      <p>Modifier l'ordre des étapes n'affecte que les <em>nouvelles</em> soumissions. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
    </div>

    <!-- ── Alertes de deadline ── -->
    <h3>⏰ Configuration des alertes</h3>

    <p>La page <strong>admin_alerts.php</strong> permet de configurer des <strong>alertes automatiques</strong> quand une demande approche d'une date limite :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Créez une règle d'alerte</strong> — Choisissez le formulaire concerné, le nombre de jours avant la deadline, et qui doit être notifié (admin ou email personnalisé).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Le type de condition</strong> — Par exemple, « étapes incomplètes » déclenche l'alerte si des étapes ne sont pas encore validées.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Activez ou désactivez</strong> chaque règle indépendamment.</p>
      </div>
    </div>

    <img src="docs/screenshots/11_admin_alerts.png" alt="Page de configuration des alertes — règles de deadline" class="screenshot">
    <p class="screenshot-caption">Configuration des alertes — définir des règles de notification avant les dates limites</p>

    <!-- ── Monitoring ── -->
    <h3>🔍 Monitoring et supervision</h3>

    <p>La page <strong>monitoring.php</strong> vous donne une vue d'ensemble de l'état du système :</p>
    <ul>
      <li><strong>Temps moyen de traitement</strong> — Combien de temps prennent les demandes en moyenne</li>
      <li><strong>Taux de validation</strong> — Pourcentage de demandes validées / refusées / en cours</li>
      <li><strong>Tokens bloqués</strong> — Les validations en attente depuis trop longtemps</li>
      <li><strong>Tokens expirés</strong> — Les liens de validation qui ont dépassé leur date d'expiration</li>
      <li><strong>Alertes actives</strong> — Les demandes proches de leur deadline</li>
      <li><strong>Activité récente</strong> — Les dernières actions (validations, refus, créations)</li>
    </ul>

    <div class="tip-box">
      <p>Consultez régulièrement la page de monitoring pour identifier les validateurs qui tardent à répondre et les relancer si nécessaire.</p>
    </div>

    <img src="docs/screenshots/08_monitoring.png" alt="Page de monitoring — état du système et alertes" class="screenshot">
    <p class="screenshot-caption">Monitoring — temps moyen, taux de validation, tokens bloqués et activité récente</p>

    <!-- ── Statistiques ── -->
    <h3>📈 Statistiques et reporting</h3>

    <p>La page <strong>stats.php</strong> fournit des statistiques détaillées :</p>
    <ul>
      <li><strong>Statistiques globales</strong> — Total, en cours, validés, refusés</li>
      <li><strong>Statistiques par période</strong> — Vue par semaine, mois ou année</li>
      <li><strong>Statistiques par formulaire</strong> — Nombre de demandes et temps moyen de traitement pour chaque formulaire</li>
      <li><strong>Statistiques par validateur</strong> — Nombre de validations, temps de réponse moyen</li>
      <li><strong>Graphique de répartition</strong> — Visualisation des statuts sous forme de graphique</li>
    </ul>

    <!-- ── RGPD ── -->
    <h3>🔒 Conformité RGPD</h3>

    <p>La page <strong>rgpd.php</strong> vous permet de gérer la conformité au Règlement Général sur la Protection des Données :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Mentions légales</strong> — Modifiez le texte affiché aux utilisateurs en bas des formulaires. Ce texte doit informer les utilisateurs du traitement de leurs données.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Durée de conservation</strong> — Configurez le nombre de mois de conservation des données après clôture (par défaut : 24 mois).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Exporter les données d'un utilisateur</strong> — Saisissez une adresse email et téléchargez toutes les données associées au format JSON (droit d'accès RGPD).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Supprimer les données d'un utilisateur</strong> — Anonymisez les données d'une personne (droit d'effacement RGPD). Les données sont rendues anonymes, pas supprimées, pour préserver l'historique statistique.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">5</span>
      <div class="step-text">
        <p><strong>Purge automatique</strong> — Les demandes clôturées depuis plus longtemps que la durée de conservation sont automatiquement supprimées par la purge.</p>
      </div>
    </div>

    <!-- ── Webhooks ── -->
    <h3>🔗 Webhooks pour l'intégration SI</h3>

    <p>Les webhooks permettent de <strong>connecter l'application à votre système d'information</strong>. Quand un événement se produit (validation, refus, annulation, workflow terminé), le système envoie automatiquement une notification à l'adresse configurée.</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Configurez l'URL du webhook</strong> — Dans les paramètres (<code>admin_settings.php</code>), renseignez l'URL qui recevra les notifications.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Choisissez les événements</strong> — Sélectionnez quels événements déclencheront l'envoi : workflow terminé, validation effectuée, demande annulée, etc.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Les notifications sont envoyées automatiquement</strong> — Chaque notification contient le type d'événement, l'horodatage et les données associées au format JSON.</p>
      </div>
    </div>

    <div class="tip-box">
      <p>Les webhooks sont optionnels. Si vous n'avez pas de système d'information à connecter, vous pouvez ignorer cette fonctionnalité.</p>
    </div>

    <!-- ── Health check ── -->
    <h3>💚 Health check (vérification de santé)</h3>

    <p>La page <strong>health.php</strong> vérifie automatiquement l'état de santé de l'application :</p>
    <ul>
      <li><strong>Base de données</strong> — SQLite est-elle accessible ?</li>
      <li><strong>Version PHP</strong> — Est-elle compatible ?</li>
      <li><strong>Répertoire de données</strong> — Est-il accessible en écriture ?</li>
      <li><strong>Schéma de base</strong> — Toutes les tables sont-elles présentes ?</li>
      <li><strong>Configuration SMTP</strong> — L'envoi d'emails est-il configuré ?</li>
    </ul>
    <p>Cette page retourne un statut HTTP 200 si tout va bien, ou 503 si un problème est détecté. Elle peut être utilisée par les outils de supervision externes.</p>

    <!-- ── Sauvegarde et restauration ── -->
    <h3>💾 Sauvegarde et restauration</h3>

    <p>La page <strong>backup.php</strong> permet de sauvegarder et restaurer la base de données :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Télécharger une sauvegarde</strong> — Crée une copie complète de la base de données au format .db que vous pouvez enregistrer sur votre poste.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Restaurer une sauvegarde</strong> — Importez un fichier .db précédemment sauvegardé pour remettre le système dans l'état correspondant.</p>
      </div>
    </div>

    <div class="warn-box">
      <p><strong>⚠ Attention :</strong> La restauration remplace toutes les données actuelles. Effectuez toujours une sauvegarde avant de restaurer. La restauration est irréversible.</p>
    </div>

    <div class="tip-box">
      <p>Prenez l'habitude de télécharger une sauvegarde régulièrement (par exemple chaque semaine). En cas de problème, vous pourrez toujours revenir à une version antérieure.</p>
    </div>

    <!-- ── Paramètres SMTP ── -->
    <h3>⚙️ Configuration des paramètres</h3>

    <p>La page <strong>admin_settings.php</strong> (réservée au super administrateur) permet de configurer :</p>
    <ul>
      <li><strong>Serveur SMTP</strong> — L'adresse du serveur d'envoi d'emails (ex : smtp.social.gouv.fr)</li>
      <li><strong>Port SMTP</strong> — Le port du serveur (ex : 25)</li>
      <li><strong>Expéditeur</strong> — L'adresse email d'expédition (ex : workflow@dreets.gouv.fr)</li>
      <li><strong>Nom de l'expéditeur</strong> — Le nom affiché (ex : Workflow DREETS)</li>
      <li><strong>Délai de relance</strong> — Le nombre d'heures avant l'envoi d'un rappel automatique (ex : 48h)</li>
      <li><strong>URL du webhook</strong> — L'adresse pour les notifications automatiques</li>
      <li><strong>Événements webhook</strong> — Les événements à notifier</li>
    </ul>
    <div class="warn-box">
      <p><strong>⚠ Accès restreint :</strong> La page de paramètres est réservée au <strong>super administrateur</strong>.</p>
    </div>

    <img src="docs/screenshots/12_admin_settings.png" alt="Page des paramètres — configuration SMTP et relances" class="screenshot">
    <p class="screenshot-caption">Paramètres — configuration SMTP, délai de relance et webhooks (réservé au super admin)</p>

    <img src="docs/screenshots/13_docs.png" alt="Page de documentation et d'aide en ligne" class="screenshot">
    <p class="screenshot-caption">Page d'aide et documentation — guide complet accessible à tous les utilisateurs</p>

    <img src="docs/screenshots/14_changelog.png" alt="Journal des modifications — historique des versions" class="screenshot">
    <p class="screenshot-caption">Journal des modifications — historique des évolutions et corrections par version</p>

    <!-- ── Admin vs Super admin ── -->
    <h3>👑 Admin vs Super admin</h3>
    <table class="schema-table">
      <thead>
        <tr>
          <th>Fonctionnalité</th>
          <th><span class="role-badge role-admin">Admin</span></th>
          <th><span class="role-badge role-superadmin">Super admin</span></th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Voir le tableau de bord</td><td>✓</td><td>✓</td></tr>
        <tr><td>Gérer les formulaires et étapes</td><td>✓</td><td>✓</td></tr>
        <tr><td>Consulter les statistiques</td><td>✓</td><td>✓</td></tr>
        <tr><td>Accéder au monitoring</td><td>✓</td><td>✓</td></tr>
        <tr><td>Configurer les alertes</td><td>✓</td><td>✓</td></tr>
        <tr><td>Sauvegarder et restaurer</td><td>✓</td><td>✓</td></tr>
        <tr><td>Gérer la conformité RGPD</td><td>✓</td><td>✓</td></tr>
        <tr><td>Approuver / refuser les demandes d'accès</td><td></td><td>✓</td></tr>
        <tr><td>Gérer la liste des administrateurs</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les paramètres SMTP</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les webhooks</td><td></td><td>✓</td></tr>
      </tbody>
    </table>
    <p>
      Le super administrateur est défini par son adresse email dans la configuration. Il s'agit généralement du premier administrateur, qui ne peut pas être supprimé.
    </p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 5. FONCTIONNALITÉS                                         -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="fonctionnalites">
    <h2>5. Fonctionnalités de l'application</h2>

    <p>Voici la liste complète des fonctionnalités de l'application :</p>

    <div class="feature-grid">
      <div class="feature-item">
        <strong>📝 Formulaires dynamiques configurables</strong>
        <p>Créez et configurez vos formulaires sans coder. Champs texte, listes déroulantes, cases à cocher, pièces jointes.</p>
      </div>
      <div class="feature-item">
        <strong>🔄 Circuit de validation séquentiel et parallèle</strong>
        <p>Définissez l'ordre de validation : étape par étape ou plusieurs en même temps, selon les besoins de chaque formulaire.</p>
      </div>
      <div class="feature-item">
        <strong>🔐 Tokens cryptographiques à usage unique</strong>
        <p>Chaque lien de validation est unique et sécurisé. Une fois utilisé, il ne peut plus servir, garantissant l'intégrité du processus.</p>
      </div>
      <div class="feature-item">
        <strong>📎 Pièces jointes sécurisées</strong>
        <p>Les fichiers joints sont stockés de manière sécurisée en base de données. Seules les personnes autorisées peuvent les télécharger.</p>
      </div>
      <div class="feature-item">
        <strong>🔔 Relances automatiques et manuelles</strong>
        <p>Le système relance automatiquement les validateurs en attente. L'administrateur peut aussi relancer manuellement.</p>
      </div>
      <div class="feature-item">
        <strong>🔄 Délégation de validation</strong>
        <p>Un validateur peut transférer sa validation à un collègue. La délégation est tracée dans l'historique.</p>
      </div>
      <div class="feature-item">
        <strong>⏰ Alertes de deadline configurables</strong>
        <p>Recevez une alerte quand une demande approche de sa date limite. Configurable par formulaire et par destinataire.</p>
      </div>
      <div class="feature-item">
        <strong>📈 Statistiques et tableaux de bord</strong>
        <p>Suivez les performances : temps de traitement, taux de validation, répartition par période et par formulaire.</p>
      </div>
      <div class="feature-item">
        <strong>📋 Journal d'audit complet</strong>
        <p>Chaque action est enregistrée : qui a fait quoi et quand. Traçabilité totale pour la conformité et le contrôle.</p>
      </div>
      <div class="feature-item">
        <strong>🔒 Conformité RGPD</strong>
        <p>Export, suppression et purge automatique des données. Durée de conservation configurable. Droit d'accès et d'effacement garantis.</p>
      </div>
      <div class="feature-item">
        <strong>🔗 Webhooks pour intégration SI</strong>
        <p>Connectez l'application à votre système d'information. Notifications automatiques lors des événements clés.</p>
      </div>
      <div class="feature-item">
        <strong>💚 Health check pour monitoring</strong>
        <p>Vérifiez automatiquement l'état de l'application : base de données, configuration email, version PHP.</p>
      </div>
      <div class="feature-item">
        <strong>💾 Sauvegarde et restauration</strong>
        <p>Téléchargez une sauvegarde complète et restaurez-la en cas de besoin. Sécurisez vos données simplement.</p>
      </div>
      <div class="feature-item">
        <strong>🎨 Design Marianne / RGAA accessible</strong>
        <p>Interface conforme au système de design de l'État et aux normes d'accessibilité RGAA. Utilisable par tous.</p>
      </div>
      <div class="feature-item">
        <strong>🛡️ Zéro JavaScript (sécurité maximale)</strong>
        <p>L'application fonctionne entièrement sans JavaScript côté client. Sécurité renforcée, compatible avec les navigateurs les plus restrictifs.</p>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 6. RÔLES ET PERMISSIONS                                    -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="roles-permissions">
    <h2>6. Rôles et permissions</h2>

    <p>L'application distingue quatre profils. Voici ce que chacun peut faire :</p>

    <h3><span class="role-badge role-agent">Agent</span> L'agent</h3>
    <p>L'agent est la personne qui remplit et soumet un formulaire (par exemple, un agent qui déclare son arrivée).</p>
    <ul>
      <li>✅ Remplir et soumettre un formulaire</li>
      <li>✅ Suivre l'avancement de <strong>ses propres</strong> demandes (page « Mes demandes »)</li>
      <li>✅ Annuler une de ses demandes en cours</li>
      <li>✅ Consulter les détails de ses demandes</li>
      <li>❌ Ne peut pas voir les demandes des autres agents</li>
      <li>❌ Ne peut pas configurer les formulaires ni les étapes</li>
    </ul>

    <h3><span class="role-badge role-validator">Validateur</span> Le validateur</h3>
    <p>Le validateur reçoit les demandes à traiter. Il n'a pas besoin d'être sur le réseau DREETS.</p>
    <ul>
      <li>✅ Valider ou refuser une demande (via le lien email)</li>
      <li>✅ Consulter les détails d'une demande à valider</li>
      <li>✅ Déléguer sa validation à un autre validateur</li>
      <li>✅ Suivre ses validations en attente et passées (page « Mes validations »)</li>
      <li>✅ Ajouter un commentaire lors de la validation ou du refus</li>
      <li>❌ Ne peut pas modifier une demande déjà soumise</li>
      <li>❌ Ne peut pas accéder au tableau de bord administrateur</li>
    </ul>

    <h3><span class="role-badge role-admin">Admin</span> L'administrateur</h3>
    <p>L'administrateur configure et supervise l'application. Il a accès à toutes les fonctions de gestion.</p>
    <ul>
      <li>✅ Tout ce que peut faire un agent + un validateur</li>
      <li>✅ Voir le tableau de bord (toutes les demandes)</li>
      <li>✅ Créer, modifier et désactiver des formulaires</li>
      <li>✅ Configurer les étapes et les destinataires</li>
      <li>✅ Configurer les alertes de deadline</li>
      <li>✅ Consulter les statistiques et le monitoring</li>
      <li>✅ Gérer la conformité RGPD (export, suppression)</li>
      <li>✅ Sauvegarder et restaurer la base de données</li>
      <li>✅ Relancer manuellement un validateur</li>
      <li>✅ Annuler n'importe quelle demande en cours</li>
      <li>❌ Ne peut pas gérer les administrateurs</li>
      <li>❌ Ne peut pas modifier les paramètres SMTP et webhooks</li>
    </ul>

    <h3><span class="role-badge role-superadmin">Super admin</span> Le super administrateur</h3>
    <p>Le super administrateur a tous les droits. Il y en a généralement un seul dans l'organisation.</p>
    <ul>
      <li>✅ Tout ce que peut faire un administrateur</li>
      <li>✅ Approuver ou refuser les demandes d'accès administrateur</li>
      <li>✅ Gérer la liste des administrateurs (ajouter, supprimer)</li>
      <li>✅ Configurer les paramètres SMTP (serveur, port, expéditeur)</li>
      <li>✅ Configurer les webhooks (URL, événements)</li>
    </ul>

    <h3>Résumé des permissions</h3>
    <table class="perm-table">
      <thead>
        <tr>
          <th>Action</th>
          <th><span class="role-badge role-agent">Agent</span></th>
          <th><span class="role-badge role-validator">Validateur</span></th>
          <th><span class="role-badge role-admin">Admin</span></th>
          <th><span class="role-badge role-superadmin">Super admin</span></th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Soumettre un formulaire</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Suivre ses demandes</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Annuler sa demande</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Valider / refuser</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Déléguer une validation</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Tableau de bord (toutes les demandes)</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Créer / modifier des formulaires</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Configurer les alertes</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Statistiques / monitoring</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Conformité RGPD</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Sauvegarde / restauration</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Gérer les administrateurs</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
        <tr><td>Paramètres SMTP / webhooks</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
      </tbody>
    </table>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 7. FAQ                                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="faq">
    <h2>7. FAQ — Questions fréquentes</h2>

    <details>
      <summary>Je n'ai pas reçu l'email de validation</summary>
      <div class="detail-body">
        <p>Pas de panique ! Voici les vérifications à effectuer dans l'ordre :</p>
        <ol>
          <li><strong>Courrier indésirable (spam)</strong> — Regardez dans votre dossier spam ou courrier indésirable. C'est la cause la plus fréquente.</li>
          <li><strong>Adresse email</strong> — Vérifiez que votre adresse email est bien celle enregistrée comme destinataire de l'étape. Demandez à l'administrateur de vérifier.</li>
          <li><strong>Délai</strong> — L'email peut mettre quelques minutes à arriver. Attendez 15 minutes avant de conclure qu'il n'arrivera pas.</li>
          <li><strong>Relance automatique</strong> — Si l'email ne vous parvient pas, le système vous renverra un email après le délai configuré (48h par défaut).</li>
          <li><strong>Configuration SMTP</strong> — Si le problème persiste, l'administrateur peut vérifier les paramètres SMTP dans admin_settings.php.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Comment annuler ma demande ?</summary>
      <div class="detail-body">
        <p>Pour annuler une demande en cours :</p>
        <ol>
          <li>Rendez-vous sur la page <strong>« Mes demandes »</strong>.</li>
          <li>Trouvez la demande que vous souhaitez annuler.</li>
          <li>Cliquez sur le bouton <strong>« Annuler »</strong>.</li>
          <li>Confirmez l'annulation.</li>
        </ol>
        <p><strong>Attention :</strong> L'annulation est irréversible. La demande sera clôturée et les validateurs ne recevront plus de relances. Pour soumettre à nouveau, il faudra remplir un nouveau formulaire.</p>
      </div>
    </details>

    <details>
      <summary>Puis-je déléguer ma validation ?</summary>
      <div class="detail-body">
        <p><strong>Oui !</strong> Si vous n'êtes pas la bonne personne pour valider, vous pouvez déléguer :</p>
        <ol>
          <li>Accédez à <strong>« Mes validations »</strong>.</li>
          <li>Trouvez la demande à déléguer et cliquez sur <strong>« Déléguer »</strong>.</li>
          <li>Entrez l'adresse email du collègue à qui vous déléguez.</li>
          <li>Ajoutez un motif (facultatif) et validez.</li>
        </ol>
        <p>Le système annule votre lien de validation et envoie un nouveau lien au délégataire. La délégation est enregistrée dans l'historique.</p>
      </div>
    </details>

    <details>
      <summary>Combien de temps est conservée ma demande ?</summary>
      <div class="detail-body">
        <p>Les données sont conservées pendant la <strong>durée configurée par l'administrateur</strong> (par défaut : <strong>24 mois</strong> après la clôture de la demande).</p>
        <p>Après ce délai, les données sont automatiquement supprimées par la <strong>purge automatique RGPD</strong>. Cette purge s'exécute périodiquement pour garantir la conformité au RGPD.</p>
        <p>Vous pouvez demander la suppression anticipée de vos données en contactant l'administrateur ou le CIL DREETS.</p>
      </div>
    </details>

    <details>
      <summary>Comment ajouter un nouveau formulaire ?</summary>
      <div class="detail-body">
        <p>Pour créer un nouveau formulaire (réservé aux administrateurs) :</p>
        <ol>
          <li>Accédez à <strong>admin_forms.php</strong>.</li>
          <li>Dans la section « Ajouter un formulaire », renseignez :
            <ul>
              <li><strong>Libellé</strong> — Le titre affiché (ex : « Demande de congé »). L'identifiant technique (slug) est généré automatiquement à partir du libellé.</li>
              <li><strong>Description</strong> — Un texte explicatif affiché en haut du formulaire.</li>
            </ul>
          </li>
          <li>Cliquez sur <strong>Ajouter</strong>.</li>
          <li>Ajoutez les <strong>champs du formulaire</strong> (texte, liste, case à cocher…).</li>
          <li>Ajoutez les <strong>étapes de validation</strong> et les <strong>destinataires</strong> pour chaque étape.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Comment configurer les alertes ?</summary>
      <div class="detail-body">
        <p>Les alertes permettent d'être prévenu quand une demande approche de sa date limite :</p>
        <ol>
          <li>Accédez à <strong>admin_alerts.php</strong> (réservé aux administrateurs).</li>
          <li>Cliquez sur <strong>« Ajouter une règle »</strong>.</li>
          <li>Choisissez le <strong>formulaire</strong> concerné.</li>
          <li>Indiquez le <strong>nombre de jours avant la deadline</strong> pour déclencher l'alerte.</li>
          <li>Choisissez le <strong>type de condition</strong> (ex : étapes incomplètes).</li>
          <li>Indiquez <strong>qui doit être notifié</strong> (admin ou adresse email personnalisée).</li>
          <li>Donnez un <strong>libellé</strong> à la règle et validez.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Que faire si un validateur ne répond pas ?</summary>
      <div class="detail-body">
        <p>Plusieurs solutions s'offrent à vous :</p>
        <ol>
          <li><strong>Attendre la relance automatique</strong> — Le système envoie un rappel automatique après le délai configuré (48h par défaut).</li>
          <li><strong>Relance manuelle</strong> — L'administrateur peut relancer le validateur directement depuis le tableau de bord.</li>
          <li><strong>Délégation</strong> — Le validateur peut déléguer sa validation à un collègue plus disponible.</li>
          <li><strong>Consulter le monitoring</strong> — L'administrateur peut identifier les validateurs en retard depuis la page monitoring.php.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Puis-je modifier une demande déjà soumise ?</summary>
      <div class="detail-body">
        <p><strong>Non.</strong> Une fois le formulaire envoyé, les données ne peuvent plus être modifiées. C'est une garantie d'intégrité : les validateurs voient exactement ce qui a été soumis.</p>
        <p>Si vous avez fait une erreur :</p>
        <ul>
          <li><strong>Annulez</strong> la demande en cours depuis « Mes demandes ».</li>
          <li><strong>Soumettez</strong> un nouveau formulaire avec les bonnes informations.</li>
        </ul>
        <p>Pour les petites corrections (ex : numéro de bureau), contactez l'administrateur qui peut vous guider.</p>
      </div>
    </details>

    <details>
      <summary>Comment exporter les données ?</summary>
      <div class="detail-body">
        <p>Plusieurs options d'export sont disponibles :</p>
        <ul>
          <li><strong>Export CSV</strong> — Depuis le tableau de bord, vous pouvez télécharger les données au format tableur (CSV) pour les ouvrir dans Excel.</li>
          <li><strong>Export RGPD</strong> — Depuis la page rgpd.php, les administrateurs peuvent exporter toutes les données d'un utilisateur au format JSON (droit d'accès RGPD).</li>
          <li><strong>Sauvegarde complète</strong> — Depuis backup.php, vous pouvez télécharger une copie complète de la base de données.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Qu'est-ce qu'un webhook ?</summary>
      <div class="detail-body">
        <p>Un <strong>webhook</strong> est un système de notification automatique. Quand un événement se produit dans l'application (validation, refus, annulation, workflow terminé), le système envoie automatiquement un message à une adresse internet que vous configurez.</p>
        <p>Cela permet de <strong>connecter l'application à d'autres systèmes</strong> de votre organisation, par exemple pour :</p>
        <ul>
          <li>Mettre à jour automatiquement un autre outil de suivi</li>
          <li>Déclencher une action dans votre système d'information</li>
          <li>Recevoir les notifications dans un canal de messagerie (Teams, etc.)</li>
        </ul>
        <p><strong>En résumé :</strong> Si vous ne savez pas ce qu'est un webhook, vous n'en avez probablement pas besoin. C'est une fonctionnalité optionnelle pour les équipes techniques.</p>
      </div>
    </details>

    <details>
      <summary>Comment accéder aux statistiques ?</summary>
      <div class="detail-body">
        <p>Les statistiques sont accessibles aux administrateurs depuis la page <strong>stats.php</strong>. Vous y trouverez :</p>
        <ul>
          <li>Le nombre total de demandes, en cours, validées et refusées</li>
          <li>Les statistiques par <strong>période</strong> (semaine, mois, année)</li>
          <li>Les statistiques par <strong>formulaire</strong> (nombre de demandes, temps moyen)</li>
          <li>Les statistiques par <strong>validateur</strong> (nombre de validations, temps de réponse)</li>
          <li>Un <strong>graphique</strong> de répartition des statuts</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Le système est-il conforme au RGPD ?</summary>
      <div class="detail-body">
        <p><strong>Oui.</strong> L'application a été conçue pour être conforme au Règlement Général sur la Protection des Données :</p>
        <ul>
          <li><strong>Droit d'accès</strong> — Chaque agent peut consulter ses données depuis « Mes demandes ». L'administrateur peut exporter les données d'un utilisateur.</li>
          <li><strong>Droit de rectification</strong> — Contactez l'administrateur pour corriger des données erronées.</li>
          <li><strong>Droit d'effacement</strong> — L'administrateur peut anonymiser les données d'un utilisateur depuis rgpd.php.</li>
          <li><strong>Purge automatique</strong> — Les données sont automatiquement supprimées après la durée de conservation configurée (24 mois par défaut).</li>
          <li><strong>Mentions légales</strong> — Un texte d'information est affiché aux utilisateurs en bas de chaque formulaire.</li>
          <li><strong>Journal d'audit</strong> — Toutes les actions sont tracées.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Qui a accès à mes données ?</summary>
      <div class="detail-body">
        <p>Seules les personnes directement concernées par le processus de validation peuvent voir vos données :</p>
        <ul>
          <li><strong>Vous-même</strong> — Vous voyez vos propres demandes depuis « Mes demandes ».</li>
          <li><strong>Les validateurs</strong> — Les personnes qui doivent valider votre demande voient les informations nécessaires pour la traiter.</li>
          <li><strong>Les administrateurs</strong> — Ils peuvent voir toutes les demandes pour les superviser et les gérer.</li>
        </ul>
        <p>Les validateurs externes (hors réseau DREETS) ne voient que les données liées aux étapes qu'ils doivent valider.</p>
      </div>
    </details>

    <details>
      <summary>Comment fonctionne la purge automatique ?</summary>
      <div class="detail-body">
        <p>La purge automatique est un mécanisme qui supprime les données anciennes pour respecter le RGPD :</p>
        <ol>
          <li>La purge supprime les demandes <strong>clôturées</strong> (validées ou refusées) depuis plus longtemps que la <strong>durée de conservation</strong> configurée (24 mois par défaut).</li>
          <li>Elle supprime également les pièces jointes, les tokens et les logs d'alerte associés.</li>
          <li>Elle s'exécute <strong>automatiquement</strong> de façon périodique (via le Planificateur de tâches Windows).</li>
          <li>Chaque purge est enregistrée dans le <strong>journal d'audit</strong>.</li>
        </ol>
        <p>Vous pouvez modifier la durée de conservation dans la page RGPD (<code>rgpd.php</code>).</p>
      </div>
    </details>

    <details>
      <summary>Que faire en cas de problème technique ?</summary>
      <div class="detail-body">
        <p>En cas de problème, voici les premiers réflexes :</p>
        <ol>
          <li><strong>Consultez le health check</strong> — La page <code>health.php</code> vérifie automatiquement l'état de l'application (base de données, configuration email, etc.).</li>
          <li><strong>Vérifiez votre connexion</strong> — Assurez-vous d'être bien sur le réseau DREETS pour les pages qui nécessitent une authentification.</li>
          <li><strong>Essayez un autre navigateur</strong> — Certains problèmes peuvent être liés au navigateur.</li>
          <li><strong>Contactez votre administrateur</strong> — Il a accès au monitoring, aux logs et aux paramètres pour diagnostiquer le problème.</li>
          <li><strong>Consultez le journal d'audit</strong> — L'administrateur peut vérifier les actions récentes pour comprendre ce qui s'est passé.</li>
        </ol>
        <p>En cas d'urgence, l'administrateur peut toujours annuler une demande bloquée et en recréer une nouvelle.</p>
      </div>
    </details>

    <details>
      <summary>J'ai cliqué sur le lien mais il indique « Déjà validé »</summary>
      <div class="detail-body">
        <p>Cela signifie que l'action a déjà été effectuée pour ce lien. Plusieurs causes possibles :</p>
        <ul>
          <li>Vous avez déjà cliqué sur le lien précédemment (volontairement ou par accident).</li>
          <li>Un collègue partageant la même adresse email a validé l'étape.</li>
          <li>Vous avez cliqué deux fois sur le bouton lors de la première visite.</li>
          <li>La validation a été déléguée à quelqu'un d'autre.</li>
        </ul>
        <p>Vérifiez sur le tableau de bord (si vous êtes admin) que l'étape apparaît bien comme validée. En cas de doute, contactez votre administrateur.</p>
      </div>
    </details>

    <details>
      <summary>Je suis validateur mais je ne fais pas partie du réseau DREETS. Puis-je valider ?</summary>
      <div class="detail-body">
        <p><strong>Oui.</strong> La page de validation (<code>validate.php</code>) est accessible sans authentification Windows. Le lien contenu dans l'email suffit pour valider ou refuser une étape. Vous n'avez pas besoin d'un compte sur le réseau DREETS.</p>
      </div>
    </details>

    <details>
      <summary>Puis-je annuler une validation déjà effectuée ?</summary>
      <div class="detail-body">
        <p><strong>Non.</strong> Une fois une action effectuée (validation ou refus), elle est irréversible. Le lien est marqué comme utilisé et ne peut plus servir.</p>
        <p>En cas d'erreur de validation :</p>
        <ul>
          <li>Contactez l'administrateur qui pourra examiner la situation depuis le tableau de bord.</li>
          <li>L'administrateur peut annuler la demande complète et vous demander de la soumettre à nouveau.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Comment changer l'ordre de validation ?</summary>
      <div class="detail-body">
        <p>L'ordre de validation est déterminé par le <strong>numéro d'ordre</strong> de chaque étape :</p>
        <ol>
          <li>Accédez à <strong>admin_forms.php</strong>.</li>
          <li>Sélectionnez le formulaire concerné.</li>
          <li>Pour chaque étape, modifiez le champ <strong>Ordre</strong>.</li>
          <li>Un numéro plus petit = validation plus tôt dans le processus.</li>
          <li>Deux étapes avec le même numéro seront traitées <strong>en parallèle</strong>.</li>
        </ol>
        <div class="info-box">
          <p><strong>Attention :</strong> Modifier l'ordre n'affecte que les <em>nouvelles</em> soumissions. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
        </div>
      </div>
    </details>

    <details>
      <summary>🖥️ Prérequis de déploiement et installation (pour l'équipe IT)</summary>
      <div class="detail-body">
        <p>Cette section est destinée au personnel technique chargé de déployer ou maintenir l'application.</p>
        <h4>Prérequis système</h4>
        <ul>
          <li><strong>Serveur web</strong> — IIS 7+ sur Windows Server (authentification Windows intégrée activée)</li>
          <li><strong>PHP 8+</strong> — Obligatoire pour le support UUID v4 (fonctions <code>random_bytes()</code>, <code>bin2hex()</code>). PHP 7.x n'est plus compatible.</li>
          <li><strong>Extension PHP SQLite3</strong> — Activée par défaut, vérifiez avec <code>php -m | grep sqlite3</code></li>
          <li><strong>Extension PHP OpenSSL</strong> — Requise pour la génération sécurisée des tokens</li>
          <li><strong>Extension PHP mbstring</strong> — Recommandée pour le bon fonctionnement de PHPMailer</li>
          <li><strong>Accès en écriture</strong> — Le répertoire <code>db/</code> doit être accessible en écriture par le compte du pool IIS (IIS_IUSRS)</li>
        </ul>
        <h4>Installation</h4>
        <ol>
          <li>Déployez les fichiers dans le répertoire web du serveur IIS (ex : <code>C:\inetpub\wwwroot\formulaire-dematerialise\</code>)</li>
          <li>Configurez l'authentification Windows dans IIS (Anonymous = Disabled, Windows Authentication = Enabled)</li>
          <li>Vérifiez les permissions du répertoire <code>db/</code> (accès en écriture pour IIS_IUSRS)</li>
          <li>Renommez <code>config.example.php</code> en <code>config.php</code> et adaptez les constantes (SMTP, admin, URL de base)</li>
          <li>La base de données SQLite est créée automatiquement au premier accès — aucune opération manuelle nécessaire</li>
          <li>Accédez à <code>health.php</code> pour vérifier que tout fonctionne correctement</li>
        </ol>
        <h4>Tâches planifiées</h4>
        <ul>
          <li><strong>Relance automatique</strong> — Planifiez <code>php remind.php</code> toutes les 12h via le Planificateur de tâches Windows</li>
          <li><strong>Vérification des alertes</strong> — Planifiez <code>php alert_check.php</code> toutes les 6h</li>
          <li><strong>Purge RGPD</strong> — Planifiez <code>php rgpd_purge.php</code> une fois par jour (suppression des données expirées)</li>
        </ul>
        <h4>Sauvegardes</h4>
        <p>Le fichier <code>db/workflow.db</code> contient toutes les données. Sauvegardez-le régulièrement. La page <code>backup.php</code> permet aussi de télécharger une copie depuis l'interface.</p>
        <div class="tip-box">
          <p>Après le déploiement, accédez à <code>health.php</code> pour vérifier que tous les prérequis sont satisfaits (PHP 8+, SQLite accessible, répertoire inscriptible, SMTP configuré).</p>
        </div>
      </div>
    </details>

    <details>
      <summary>Comment configurer l'envoi d'emails ?</summary>
      <div class="detail-body">
        <p>Les paramètres d'envoi d'emails peuvent être configurés de deux manières :</p>
        <ol>
          <li><strong>Via l'interface</strong> — Accédez à <strong>admin_settings.php</strong> (réservé au super administrateur). Vous y trouverez les champs pour le serveur SMTP, le port, l'expéditeur, le nom et le délai de relance.</li>
          <li><strong>Via le fichier de configuration</strong> — Éditez le fichier <code>config.php</code> pour modifier les constantes (SMTP_HOST, SMTP_PORT, etc.).</li>
        </ol>
        <p>Si l'interface admin_settings.php est disponible, préférez cette méthode : elle ne nécessite pas d'accès au serveur de fichiers.</p>
      </div>
    </details>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 8. RGPD ET MENTIONS LÉGALES                                -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="rgpd-legal">
    <h2>8. RGPD et mentions légales</h2>

    <div class="rgpd-box">
      <h3>📜 Mentions légales</h3>
      <?php if (!empty($legal_mentions)): ?>
        <p><?= nl2br(h($legal_mentions)) ?></p>
      <?php else: ?>
        <p>Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d'un droit d'accès, de rectification et d'effacement de vos données. Contact : CIL DREETS. Durée de conservation : 24 mois après clôture.</p>
      <?php endif; ?>
    </div>

    <h3>🔒 Protection des données</h3>

    <p>L'application est conçue pour respecter le Règlement Général sur la Protection des Données (RGPD). Voici les mesures en place :</p>

    <h4>Mesures techniques</h4>
    <ul>
      <li><strong>Liens de validation sécurisés</strong> — Chaque lien de validation contient un token cryptographique unique (64 caractères) à usage unique, impossible à deviner.</li>
      <li><strong>Zéro JavaScript</strong> — L'application ne nécessite aucun JavaScript côté client, réduisant les risques de sécurité liés aux scripts malveillants.</li>
      <li><strong>Protection CSRF</strong> — Tous les formulaires sont protégés contre les attaques de type « Cross-Site Request Forgery ».</li>
      <li><strong>Limitation des requêtes</strong> — Un système de rate limiting empêche les usages abusifs de l'application.</li>
      <li><strong>Authentification Windows</strong> — L'accès aux pages sensibles est protégé par l'authentification intégrée Windows.</li>
      <li><strong>Pièces jointes sécurisées</strong> — Les fichiers sont stockés en base de données et accessibles uniquement via des liens sécurisés.</li>
    </ul>

    <h4>Droits des personnes</h4>
    <ul>
      <li><strong>Droit d'accès</strong> (article 15 RGPD) — Chaque utilisateur peut consulter ses données.</li>
      <li><strong>Droit de rectification</strong> (article 16 RGPD) — Contactez l'administrateur pour corriger des données.</li>
      <li><strong>Droit à l'effacement</strong> (article 17 RGPD) — L'administrateur peut anonymiser les données d'un utilisateur.</li>
      <li><strong>Droit à la limitation du traitement</strong> (article 18 RGPD) — En annulant une demande, le traitement est stoppé.</li>
    </ul>

    <h4>Durée de conservation</h4>
    <p>
      Les données sont conservées pendant la durée configurée par l'administrateur (par défaut : <strong>24 mois après la clôture</strong> de la demande).
      Au-delà de ce délai, les données sont automatiquement supprimées par la purge automatique.
      L'administrateur peut modifier cette durée dans la page RGPD.
    </p>

    <h4>Responsable de traitement</h4>
    <p>
      Le responsable de traitement est la DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités).
      Pour toute question relative à la protection de vos données, contactez le <strong>CIL DREETS</strong> (Correspondant Informatique et Libertés).
    </p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 9. ARCHITECTURE TECHNIQUE                                   -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="technique">
    <h2>9. Architecture technique (pour l'équipe IT)</h2>
    <p style="margin-bottom:1rem;color:#555;">
      Cette section est destinée au personnel technique. Elle fournit un aperçu de l'architecture.
    </p>

    <details>
      <summary>Structure des fichiers</summary>
      <div class="detail-body">
        <div class="file-tree">
          <span class="dir">📁 formulaire-dematerialise/</span><br>
          &nbsp;&nbsp;<span class="file">config.php</span> — Constantes de configuration (BDD, SMTP, admin)<br>
          &nbsp;&nbsp;<span class="file">helpers.php</span> — Fonctions utilitaires, moteur de workflow, envoi d'emails<br>
          &nbsp;&nbsp;<span class="file">index.php</span> — Redirection vers dashboard ou admin_access<br>
          &nbsp;&nbsp;<span class="file">form.php</span> — Formulaire agent (affichage + soumission)<br>
          &nbsp;&nbsp;<span class="file">form_preview.php</span> — Prévisualisation du formulaire<br>
          &nbsp;&nbsp;<span class="file">validate.php</span> — Page de validation/refus (accessible par token)<br>
          &nbsp;&nbsp;<span class="file">dashboard.php</span> — Tableau de bord de supervision<br>
          &nbsp;&nbsp;<span class="file">my_submissions.php</span> — Mes demandes (agent)<br>
          &nbsp;&nbsp;<span class="file">my_validations.php</span> — Mes validations (validateur)<br>
          &nbsp;&nbsp;<span class="file">submission_view.php</span> — Vue détaillée d'une soumission<br>
          &nbsp;&nbsp;<span class="file">admin_access.php</span> — Gestion des accès administrateur<br>
          &nbsp;&nbsp;<span class="file">admin_forms.php</span> — Gestion des formulaires, étapes, destinataires<br>
          &nbsp;&nbsp;<span class="file">admin_settings.php</span> — Configuration SMTP et webhooks (super admin)<br>
          &nbsp;&nbsp;<span class="file">admin_alerts.php</span> — Configuration des alertes de deadline<br>
          &nbsp;&nbsp;<span class="file">stats.php</span> — Statistiques et tableaux de bord<br>
          &nbsp;&nbsp;<span class="file">monitoring.php</span> — Tableau de bord de monitoring<br>
          &nbsp;&nbsp;<span class="file">health.php</span> — Point de contrôle de santé<br>
          &nbsp;&nbsp;<span class="file">rgpd.php</span> — Conformité RGPD (export, suppression, purge)<br>
          &nbsp;&nbsp;<span class="file">backup.php</span> — Sauvegarde et restauration<br>
          &nbsp;&nbsp;<span class="file">remind.php</span> — Script CLI de relance automatique<br>
          &nbsp;&nbsp;<span class="file">alert_check.php</span> — Script CLI de vérification des alertes<br>
          &nbsp;&nbsp;<span class="file">download.php</span> — Téléchargement sécurisé des pièces jointes<br>
          &nbsp;&nbsp;<span class="file">confirm_action.php</span> — Confirmation d'actions sensibles<br>
          &nbsp;&nbsp;<span class="file">docs.php</span> — Cette page de documentation<br>
          &nbsp;&nbsp;<span class="file">changelog.php</span> — Journal des modifications<br>
          &nbsp;&nbsp;<span class="dir">📁 PHPMailer/</span> — Librairie d'envoi d'emails<br>
          &nbsp;&nbsp;<span class="dir">📁 db/</span> — Base de données SQLite (workflow.db)
        </div>
      </div>
    </details>

    <details>
      <summary>Schéma de la base de données (simplifié)</summary>
      <div class="detail-body">
        <h3 style="margin-top:0;">Table <code>forms</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>slug</td><td>TEXT UNIQUE</td><td>Identifiant URL (ex : onboarding)</td></tr>
            <tr><td>label</td><td>TEXT</td><td>Libellé du formulaire</td></tr>
            <tr><td>description</td><td>TEXT</td><td>Description affichée</td></tr>
            <tr><td>actif</td><td>INTEGER</td><td>1 = actif, 0 = désactivé</td></tr>
          </tbody>
        </table>

        <h3>Table <code>steps</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>TEXT FK (UUID v4)</td><td>Formulaire parent</td></tr>
            <tr><td>label</td><td>TEXT</td><td>Libellé de l'étape</td></tr>
            <tr><td>ordre</td><td>INTEGER</td><td>Numéro d'ordre (détermine la séquence)</td></tr>
            <tr><td>actif</td><td>INTEGER</td><td>1 = actif</td></tr>
          </tbody>
        </table>

        <h3>Table <code>step_recipients</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>step_id</td><td>TEXT FK (UUID v4)</td><td>Étape parent</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
          </tbody>
        </table>

        <h3>Table <code>submissions</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>TEXT FK (UUID v4)</td><td>Formulaire utilisé</td></tr>
            <tr><td>data</td><td>TEXT (JSON)</td><td>Données du formulaire + historique des validations</td></tr>
            <tr><td>submitted_by</td><td>TEXT</td><td>Identifiant de l'agent (AUTH_USER)</td></tr>
            <tr><td>submitted_at</td><td>DATETIME</td><td>Date de soumission</td></tr>
            <tr><td>closed_at</td><td>DATETIME</td><td>Date de clôture (NULL si en cours)</td></tr>
            <tr><td>rgpd_consent</td><td>INTEGER</td><td>1 = consentement RGPD recueilli</td></tr>
            <tr><td>status</td><td>TEXT</td><td>Statut : en_cours, valide, refuse</td></tr>
          </tbody>
        </table>

        <h3>Table <code>tokens</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>TEXT PK (UUID v4)</td><td>Identifiant</td></tr>
            <tr><td>submission_id</td><td>TEXT FK (UUID v4)</td><td>Soumission liée</td></tr>
            <tr><td>step_id</td><td>TEXT FK (UUID v4)</td><td>Étape liée</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
            <tr><td>token</td><td>TEXT UNIQUE</td><td>Jeton unique (64 hex)</td></tr>
            <tr><td>sent_at</td><td>DATETIME</td><td>Date d'envoi de l'email</td></tr>
            <tr><td>done_at</td><td>DATETIME</td><td>Date de validation (NULL = en attente)</td></tr>
            <tr><td>relance_at</td><td>DATETIME</td><td>Date de dernière relance</td></tr>
            <tr><td>expires_at</td><td>DATETIME</td><td>Date d'expiration du token</td></tr>
          </tbody>
        </table>

        <h3>Tables complémentaires</h3>
        <table class="schema-table">
          <thead><tr><th>Table</th><th>Colonnes clés</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>admins</td><td>email (UNIQUE), added_at</td><td>Liste des administrateurs</td></tr>
            <tr><td>admin_requests</td><td>email, status, token</td><td>Demandes d'accès en attente</td></tr>
            <tr><td>settings</td><td>key (PK), value, updated_at, updated_by</td><td>Paramètres configurables (SMTP, délais, webhooks…)</td></tr>
            <tr><td>audit_log</td><td>action, target, detail, actor, created_at</td><td>Journal d'audit complet</td></tr>
            <tr><td>alert_rules</td><td>form_id, days_before, condition_type, notify_who</td><td>Règles d'alerte de deadline</td></tr>
            <tr><td>alert_log</td><td>rule_id, submission_id, sent_at</td><td>Historique des alertes envoyées</td></tr>
            <tr><td>delegations</td><td>id TEXT PK (UUID v4), token_id TEXT FK (UUID v4), from_email, to_email, reason, delegated_at</td><td>Historique des délégations</td></tr>
            <tr><td>form_fields</td><td>id TEXT PK (UUID v4), form_id TEXT FK (UUID v4), label, type, options, hint, required, ordre</td><td>Champs dynamiques des formulaires</td></tr>
            <tr><td>attachments</td><td>id TEXT PK (UUID v4), submission_id TEXT FK (UUID v4), filename, mime_type, data (BLOB)</td><td>Pièces jointes sécurisées</td></tr>
            <tr><td>form_owners</td><td>id TEXT PK (UUID v4), form_id TEXT FK (UUID v4), email</td><td>Propriétaires de formulaires (droits de gestion déléguée)</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details>
      <summary>Mécanisme d'authentification</summary>
      <div class="detail-body">
        <p>L'application s'appuie sur <strong>l'authentification Windows (IIS)</strong> :</p>
        <ul>
          <li>Le serveur web IIS fournit la variable <code>$_SERVER['AUTH_USER']</code> contenant le compte Windows de l'utilisateur (ex : <code>DREETS\prenom.nom</code>).</li>
          <li>La fonction <code>get_auth_user()</code> transforme ce compte en adresse email (ex : <code>prenom.nom@dreets.gouv.fr</code>).</li>
          <li>Les pages <code>form.php</code>, <code>dashboard.php</code>, <code>admin_forms.php</code> et <code>admin_access.php</code> nécessitent cette authentification.</li>
          <li>La page <code>validate.php</code> est accessible <strong>sans authentification</strong> (les validateurs externes n'ont pas forcément de compte DREETS).</li>
        </ul>
        <p>Le contrôle des droits administrateur se fait par vérification de la présence de l'email dans la table <code>admins</code>.</p>
      </div>
    </details>

    <details>
      <summary>Pile technique</summary>
      <div class="detail-body">
        <table class="schema-table">
          <thead><tr><th>Composant</th><th>Technologie</th></tr></thead>
          <tbody>
            <tr><td>Serveur web</td><td>IIS (Windows Server) avec authentification Windows</td></tr>
            <tr><td>Langage</td><td>PHP 8+ (procédural, sans framework)</td></tr>
            <tr><td>Base de données</td><td>SQLite (fichier db/workflow.db, mode WAL)</td></tr>
            <tr><td>Envoi d'emails</td><td>PHPMailer via SMTP (pas d'auth SMTP)</td></tr>
            <tr><td>Relance automatique</td><td>remind.php — exécuté par le Planificateur de tâches Windows</td></tr>
            <tr><td>Vérification des alertes</td><td>alert_check.php — exécuté par le Planificateur de tâches Windows</td></tr>
            <tr><td>Motif de sécurité</td><td>Tokens à usage unique (random_bytes 32 octets = 64 hex)</td></tr>
            <tr><td>Frontend</td><td>HTML/CSS embarqué, zéro JavaScript framework</td></tr>
            <tr><td>Design</td><td>Marianne (DSFR), conforme RGAA</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details>
      <summary>Flux de données typique</summary>
      <div class="detail-body">
        <ol>
          <li><strong>Agent</strong> accède à <code>form.php?f=onboarding</code> et remplit le formulaire.</li>
          <li>Les données sont enregistrées dans <code>submissions</code> (champ <code>data</code> en JSON).</li>
          <li><code>advance_workflow()</code> est appelée : elle crée les tokens pour l'étape d'ordre 1 et envoie les emails.</li>
          <li><strong>Validateur</strong> clique sur le lien dans l'email → arrive sur <code>validate.php?token=…</code></li>
          <li>Il valide ou refuse. <code>validate_token()</code> met à jour <code>done_at</code> et rappelle <code>advance_workflow()</code>.</li>
          <li>Si validé : les tokens de l'étape suivante sont créés et les emails envoyés.</li>
          <li>Si refusé : le statut de la soumission passe à <code>refuse</code> et <code>closed_at</code> est renseigné.</li>
          <li>Quand toutes les étapes sont validées : <code>closed_at</code> est renseigné → la soumission est clôturée. Un webhook est envoyé si configuré.</li>
          <li>En parallèle, <code>remind.php</code> tourne toutes les 12h et envoie des relances aux validateurs en attente depuis plus de 48h.</li>
          <li>En parallèle, <code>alert_check.php</code> vérifie les deadlines et envoie les alertes configurées.</li>
        </ol>
      </div>
    </details>
  </div>

</div>

<a href="#top" class="back-to-top" title="Retour en haut de page">↑</a>

<?= render_footer() ?>
</body>
</html>
