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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documentation — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; padding: 2rem 1rem; }
    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .bandeau a { color: #b3c8f0; font-size: .8rem; text-decoration: none; }
    .bandeau a:hover { text-decoration: underline; }
    .bandeau-left { display: flex; align-items: center; gap: 1rem; }
    .bandeau-right { display: flex; align-items: center; gap: 1rem; font-size: .8rem; }
    .container { max-width: 900px; margin: 0 auto; }
    h1 { font-size: 1.8rem; color: #003189; margin-bottom: .5rem; }
    h2 { font-size: 1.3rem; color: #003189; margin-bottom: 1rem; border-bottom: 2px solid #003189; padding-bottom: .5rem; }
    h3 { font-size: 1.1rem; color: #003189; margin-bottom: .75rem; }
    .subtitle { color: #555; margin-bottom: 2rem; font-size: .95rem; }
    .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
    .card h2 { text-transform: uppercase; letter-spacing: .05em; font-size: 1rem; }
    p, li { line-height: 1.7; margin-bottom: .5rem; }
    ul, ol { padding-left: 1.5rem; margin-bottom: 1rem; }
    .toc { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 2rem; }
    .toc h2 { margin-bottom: 1rem; font-size: 1rem; }
    .toc ol { counter-reset: toc; list-style: none; padding-left: 0; }
    .toc li { counter-increment: toc; margin-bottom: .4rem; }
    .toc li::before { content: counter(toc) ". "; color: #003189; font-weight: bold; }
    .toc a { color: #003189; text-decoration: none; }
    .toc a:hover { text-decoration: underline; }
    .step-num { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #003189; color: #fff; border-radius: 50%; font-size: .85rem; font-weight: bold; margin-right: .5rem; flex-shrink: 0; }
    .step-row { display: flex; align-items: flex-start; margin-bottom: 1rem; }
    .step-text { flex: 1; }
    .step-text p { margin-bottom: .25rem; }
    .info-box { background: #e8eaf6; border-left: 4px solid #003189; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
    .info-box p { margin-bottom: .25rem; }
    .warn-box { background: #fff3e0; border-left: 4px solid #b45309; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
    .warn-box p { margin-bottom: .25rem; }
    .success-box { background: #e8f5e9; border-left: 4px solid #27ae60; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
    .success-box p { margin-bottom: .25rem; }
    details { margin-bottom: 1rem; }
    details summary { cursor: pointer; font-weight: bold; color: #003189; padding: .75rem 1rem; background: #f0f0f8; border: 1px solid #ddd; border-radius: 4px; list-style: none; display: flex; align-items: center; gap: .5rem; }
    details summary::before { content: "▸"; font-size: 1rem; transition: transform .2s; display: inline-block; }
    details[open] summary::before { transform: rotate(90deg); }
    details summary:hover { background: #e8eaf6; }
    details .detail-body { padding: 1rem 1.25rem; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; background: #fff; }
    .role-badge { display: inline-block; padding: .2rem .6rem; border-radius: 3px; font-size: .8rem; font-weight: bold; margin-right: .25rem; }
    .role-agent { background: #e3f2fd; color: #1565c0; }
    .role-validator { background: #fff3e0; color: #b45309; }
    .role-admin { background: #fce4ec; color: #c62828; }
    .role-superadmin { background: #f3e5f5; color: #6a1b9a; }
    .schema-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-bottom: 1rem; }
    .schema-table th { background: #003189; color: #fff; padding: .5rem .75rem; text-align: left; font-weight: normal; }
    .schema-table td { padding: .4rem .75rem; border-bottom: 1px solid #eee; }
    .schema-table tr:nth-child(even) { background: #f7f7fb; }
    .file-tree { font-family: "Marianne", Arial, sans-serif; font-size: .9rem; background: #f5f5fe; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1rem; line-height: 1.8; }
    .file-tree .dir { font-weight: bold; color: #003189; }
    .file-tree .file { color: #333; }
    @media (max-width: 600px) {
      .bandeau { flex-direction: column; text-align: center; }
      .container { padding: 0 .5rem; }
    }
  </style>
</head>
<body>
<div class="bandeau">
  <div class="bandeau-left">
    <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
    <span style="opacity:.7;">| Documentation</span>
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
  <h1>Documentation</h1>
  <p class="subtitle">Guide complet de l'application de formulaires dématérialisés — DREETS</p>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- TABLE DES MATIÈRES                                         -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="toc">
    <h2>Table des matières</h2>
    <ol>
      <li><a href="#vue-ensemble">Vue d'ensemble</a></li>
      <li><a href="#guide-agent">Guide de l'agent (formulaire)</a></li>
      <li><a href="#guide-validateur">Guide du validateur</a></li>
      <li><a href="#guide-administrateur">Guide de l'administrateur</a></li>
      <li><a href="#workflow">Fonctionnement du workflow</a></li>
      <li><a href="#faq">FAQ — Questions fréquentes</a></li>
      <li><a href="#technique">Architecture technique (résumé)</a></li>
    </ol>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 1. VUE D'ENSEMBLE                                          -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="vue-ensemble">
    <h2>1. Vue d'ensemble</h2>

    <h3>Qu'est-ce que cette application ?</h3>
    <p>
      Cette application permet de <strong>dématérialiser les formulaires administratifs</strong> de la DREETS
      (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités).
      Elle gère l'ensemble du cycle de vie d'une demande : de la saisie par un agent
      jusqu'à la validation finale, en passant par toutes les étapes intermédiaires.
    </p>

    <h3>Quel problème résout-elle ?</h3>
    <p>
      Avant cette application, les formulaires (arrivée d'un agent, demande de matériel, etc.)
      circulaient par email ou sur papier, sans suivi. Il était difficile de savoir :
    </p>
    <ul>
      <li>Où en était une demande</li>
      <li>Qui devait encore valider</li>
      <li>Combien de temps prenait chaque étape</li>
    </ul>
    <p>
      Avec cette application, chaque demande suit un <strong>workflow automatisé</strong> :
      les bons validateurs sont notifiés par email, peuvent valider en un clic,
      et l'avancement est visible en temps réel depuis le tableau de bord.
    </p>

    <h3>À qui est-elle destinée ?</h3>
    <p>L'application s'adresse à trois profils :</p>
    <ul>
      <li><span class="role-badge role-agent">Agent</span> <strong>L'agent</strong> qui remplit un formulaire (ex : déclaration d'arrivée)</li>
      <li><span class="role-badge role-validator">Validateur</span> <strong>Le validateur</strong> qui reçoit une demande et doit la traiter (valider ou refuser)</li>
      <li><span class="role-badge role-admin">Admin</span> <strong>L'administrateur</strong> qui configure les formulaires, les étapes et les destinataires</li>
    </ul>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 2. GUIDE DE L'AGENT                                        -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-agent">
    <h2>2. Guide de l'agent (formulaire)</h2>

    <h3>Accéder à un formulaire</h3>
    <p>
      Chaque formulaire est identifié par un <strong>slug</strong> (un identifiant court dans l'URL).
      Par exemple, le formulaire d'arrivée d'un agent est accessible à l'adresse :
    </p>
    <div class="info-box">
      <p><code>form.php?f=onboarding</code></p>
      <p><small>Le slug « onboarding » est un exemple — consultez votre administrateur pour connaître les formulaires disponibles.</small></p>
    </div>
    <p>
      Vous devez être connecté au réseau DREETS (authentification Windows) pour accéder aux formulaires.
    </p>

    <h3>Remplir le formulaire</h3>
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
        <p><strong>Envoyer</strong> — Cliquez sur le bouton <em>« Envoyer la déclaration »</em>. Les champs marqués d'une astérisque rouge (*) sont obligatoires.</p>
      </div>
    </div>

    <h3>Après l'envoi</h3>
    <div class="success-box">
      <p><strong>✓ Demande enregistrée</strong> — Le workflow de validation est déclenché automatiquement. Les validateurs concernés reçoivent un email.</p>
    </div>
    <p>
      Vous n'avez rien d'autre à faire. Le système se charge de faire circuler votre demande
      auprès des personnes compétentes, dans l'ordre défini.
    </p>

    <h3>Suivre l'avancement</h3>
    <p>
      Si vous êtes administrateur, vous pouvez suivre l'avancement depuis le
      <strong>tableau de bord</strong> (dashboard.php). Sinon, vous pouvez contacter
      votre administrateur pour obtenir un compte-rendu de l'état de votre demande.
    </p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 3. GUIDE DU VALIDATEUR                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-validateur">
    <h2>3. Guide du validateur</h2>

    <h3>Recevoir une demande de validation</h3>
    <p>
      Lorsqu'une demande nécessite votre intervention, vous recevez un email de
      <strong>workflow@dreets.gouv.fr</strong> avec l'objet :
    </p>
    <div class="info-box">
      <p><code>[Action requise] Nom du formulaire — Nom de l'étape</code></p>
    </div>
    <p>
      Cet email contient un résumé des informations du formulaire et un bouton
      <strong>« Marquer comme effectué »</strong>.
    </p>

    <h3>Cliquer sur le lien</h3>
    <p>
      Le bouton ou le lien dans l'email vous dirige vers la page de validation.
      Vous y trouverez :
    </p>
    <ul>
      <li>Le <strong>libellé de l'étape</strong> concernée (ex : « Informatique », « Ressources Humaines »)</li>
      <li>Les <strong>détails du formulaire</strong> rempli par l'agent</li>
    </ul>

    <h3>Comprendre la demande</h3>
    <p>
      Prenez le temps de lire les informations affichées. Les cases cochées sont marquées ✓.
      Si vous avez des questions sur le contenu, contactez l'agent ayant soumis la demande.
    </p>

    <h3>Valider ou refuser</h3>
    <p>Vous avez deux options :</p>
    <ul>
      <li><strong>✅ Valider</strong> — Confirme que l'étape est traitée. Le workflow passe à l'étape suivante.</li>
      <li><strong>❌ Refuser</strong> — Bloque la demande. Celle-ci est immédiatement clôturée avec le statut « Refusé ».</li>
    </ul>
    <p>
      Dans les deux cas, vous pouvez ajouter un <strong>commentaire</strong> (facultatif)
      pour expliquer votre décision.
    </p>

    <div class="warn-box">
      <p><strong>⚠ Important :</strong> Le lien de validation est à <strong>usage unique</strong>.
      Une fois que vous avez cliqué sur Valider ou Refuser, le lien n'est plus utilisable.
      Si vous voyez le message « Déjà validé », cela signifie que l'action a déjà été effectuée
      (peut-être par vous ou par un collègue partageant la même adresse email).</p>
    </div>

    <h3>Après votre validation</h3>
    <p>
      Si vous avez <strong>validé</strong>, le système envoie automatiquement un email
      au(x) validateur(s) de l'étape suivante (s'il y en a une).
      Vous n'avez rien d'autre à faire.
    </p>
    <p>
      Si vous avez <strong>refusé</strong>, la demande est clôturée et les étapes
      suivantes ne seront pas déclenchées.
    </p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 4. GUIDE DE L'ADMINISTRATEUR                               -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-administrateur">
    <h2>4. Guide de l'administrateur</h2>

    <h3>Demander l'accès administrateur</h3>
    <p>
      Si vous n'êtes pas encore administrateur, accédez à la page
      <strong>admin_access.php</strong>. Vous y trouverez un bouton
      <em>« Demander l'accès admin »</em>.
    </p>
    <p>
      Votre demande est envoyée par email à l'administrateur principal
      qui peut l'approuver ou la refuser.
    </p>

    <h3>Gérer les formulaires, étapes et destinataires</h3>
    <p>
      Depuis la page <strong>admin_forms.php</strong>, vous pouvez :
    </p>
    <ul>
      <li><strong>Créer un formulaire</strong> — Définissez un slug (identifiant URL), un libellé et une description.</li>
      <li><strong>Modifier un formulaire</strong> — Changez le libellé, la description ou désactivez-le.</li>
      <li><strong>Ajouter des étapes</strong> — Chaque étape a un libellé et un numéro d'ordre. L'ordre détermine la séquence du workflow.</li>
      <li><strong>Modifier / supprimer des étapes</strong> — Ajustez le libellé, l'ordre ou supprimez une étape.</li>
      <li><strong>Ajouter des destinataires</strong> — Pour chaque étape, ajoutez les adresses email des validateurs. Plusieurs destinataires sur la même étape recevront tous une notification (validation parallèle).</li>
    </ul>

    <div class="info-box">
      <p><strong>Astuce :</strong> Pour qu'une étape nécessite la validation de <strong>tous</strong> ses destinataires,
      mettez-les dans la même étape. Pour qu'ils valident <strong>séquentiellement</strong>,
      créez des étapes distinctes avec des ordres croissants.</p>
    </div>

    <h3>Comprendre le tableau de bord</h3>
    <p>
      Le <strong>dashboard</strong> (dashboard.php) affiche toutes les soumissions avec :
    </p>
    <ul>
      <li><strong>Statistiques</strong> — Total, en cours, clôturés</li>
      <li><strong>Filtres</strong> — Par statut (tous, en cours, clôturés) et par formulaire</li>
      <li><strong>Badges de workflow</strong> — Chaque étape est représentée par un badge coloré :
        <ul>
          <li style="color:#1a6b3c;">■ Vert = validé</li>
          <li style="color:#b45309;">■ Orange = en attente (étape courante)</li>
          <li style="color:#888;">■ Gris = pas encore démarré</li>
        </ul>
      </li>
      <li><strong>Bouton « détail »</strong> — Affiche l'historique des validations et les données du formulaire</li>
    </ul>

    <h3>Configurer les paramètres SMTP</h3>
    <p>
      La page <strong>admin_settings.php</strong> permet de configurer les paramètres d'envoi d'emails :
    </p>
    <ul>
      <li><strong>Serveur SMTP</strong> — L'adresse du serveur (ex : smtp.social.gouv.fr)</li>
      <li><strong>Port SMTP</strong> — Le port du serveur (ex : 25)</li>
      <li><strong>Expéditeur</strong> — L'adresse email d'expédition (ex : workflow@dreets.gouv.fr)</li>
      <li><strong>Nom de l'expéditeur</strong> — Le nom affiché (ex : Workflow DREETS)</li>
      <li><strong>Délai de relance</strong> — Le nombre d'heures avant l'envoi d'un rappel automatique (ex : 48h)</li>
    </ul>
    <div class="warn-box">
      <p><strong>⚠ Accès restreint :</strong> La page de paramètres est réservée au <strong>super administrateur</strong>.</p>
    </div>

    <h3>Admin vs Super admin</h3>
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
        <tr><td>Approuver / refuser les demandes d'accès</td><td></td><td>✓</td></tr>
        <tr><td>Gérer la liste des administrateurs</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les paramètres SMTP</td><td></td><td>✓</td></tr>
      </tbody>
    </table>
    <p>
      Le super administrateur est défini par son adresse email dans la configuration (fichier config.php).
      Il s'agit généralement du premier administrateur, qui ne peut pas être supprimé.
    </p>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 5. FONCTIONNEMENT DU WORKFLOW                               -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="workflow">
    <h2>5. Fonctionnement du workflow</h2>

    <h3>Étapes séquentielles vs parallèles</h3>
    <p>
      Le workflow est basé sur le concept d'<strong>ordre</strong>. Chaque étape a un numéro d'ordre :
    </p>
    <ul>
      <li><strong>Ordres différents</strong> = étapes <strong>séquentielles</strong>. L'étape d'ordre 2 ne démarre que lorsque l'étape d'ordre 1 est entièrement validée.</li>
      <li><strong>Même ordre</strong> = étapes <strong>parallèles</strong>. Elles démarrent en même temps et toutes doivent être validées avant de passer à l'ordre suivant.</li>
    </ul>

    <div class="info-box">
      <p><strong>Exemple :</strong></p>
      <p>Ordre 1 : « Informatique » → doit être validé en premier</p>
      <p>Ordre 2 : « Ressources Humaines » + « Logistique » → démarrent en parallèle après l'ordre 1</p>
      <p>Ordre 3 : « Direction » → démarre quand l'ordre 2 est entièrement validé</p>
    </div>

    <h3>Le système de tokens</h3>
    <p>
      Lorsqu'une étape est déclenchée, le système génère un <strong>token</strong>
      (un identifiant unique et aléatoire) pour chaque validateur de cette étape.
      Ce token est inclus dans le lien de validation envoyé par email.
    </p>
    <ul>
      <li>Chaque token est <strong>à usage unique</strong> : une fois utilisé, il ne peut plus servir.</li>
      <li>Un token est lié à <strong>un validateur et une étape</strong> spécifiques.</li>
      <li>Tant qu'un token n'est pas validé, l'étape est considérée « en attente ».</li>
    </ul>

    <h3>Le système de relance</h3>
    <p>
      Un script automatique (<strong>remind.php</strong>) est exécuté périodiquement
      (par le Planificateur de tâches Windows, par exemple toutes les 12 heures).
      Il vérifie les tokens non encore validés et envoie un email de relance
      si le délai configuré est dépassé (par défaut : <strong>48 heures</strong>).
    </p>
    <div class="info-box">
      <p>Le délai de relance est calculé depuis la date du dernier envoi (premier envoi ou dernière relance).
      Le champ <em>relance_at</em> est mis à jour après chaque relance pour éviter les envois répétés.</p>
    </div>

    <h3>Que se passe-t-il en cas de refus ?</h3>
    <p>
      Si un validateur clique sur <strong>❌ Refuser</strong> :
    </p>
    <ul>
      <li>La demande est immédiatement <strong>clôturée</strong> avec le statut « Refusé ».</li>
      <li>Les étapes suivantes <strong>ne sont pas déclenchées</strong>.</li>
      <li>Le commentaire du validateur est enregistré dans l'historique.</li>
      <li>Sur le tableau de bord, la soumission apparaît avec le statut <span style="color:#c0392b;font-weight:bold;">❌ Refusé</span>.</li>
    </ul>

    <h3>Expiration des tokens</h3>
    <p>
      Les tokens n'ont pas de date d'expiration automatique. Cependant :
    </p>
    <ul>
      <li>Un token déjà utilisé affiche le message <strong>« Déjà validé »</strong>.</li>
      <li>Si la soumission est clôturée (validée ou refusée), les tokens restants affichent <strong>« Workflow terminé »</strong>.</li>
      <li>Le système de relance continue d'envoyer des rappels tant que le token n'est pas traité et que la soumission est ouverte.</li>
    </ul>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 6. FAQ                                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="faq">
    <h2>6. FAQ — Questions fréquentes</h2>

    <details>
      <summary>J'ai cliqué sur le lien mais il indique « Déjà validé »</summary>
      <div class="detail-body">
        <p>Cela signifie que l'action a déjà été effectuée pour ce token. Plusieurs causes possibles :</p>
        <ul>
          <li>Vous avez déjà cliqué sur le lien précédemment (volontairement ou par accident).</li>
          <li>Un collègue partageant la même adresse email a validé l'étape.</li>
          <li>Vous avez cliqué deux fois sur le bouton lors de la première visite.</li>
        </ul>
        <p>Vérifiez sur le tableau de bord (si vous êtes admin) que l'étape apparaît bien comme validée. En cas de doute, contactez votre administrateur.</p>
      </div>
    </details>

    <details>
      <summary>Je n'ai pas reçu l'email de validation</summary>
      <div class="detail-body">
        <p>Voici les vérifications à effectuer :</p>
        <ol>
          <li><strong>Courrier indésirable</strong> — Vérifiez votre dossier spam ou courrier indésirable.</li>
          <li><strong>Adresse email</strong> — Assurez-vous que votre adresse email est bien celle enregistrée comme destinataire de l'étape. L'administrateur peut vérifier dans la gestion des formulaires.</li>
          <li><strong>Serveur SMTP</strong> — L'administrateur peut vérifier les paramètres SMTP dans admin_settings.php. Un problème de configuration peut empêcher l'envoi.</li>
          <li><strong>Délai</strong> — L'email peut mettre quelques minutes à arriver. Attendez 15 minutes avant de conclure qu'il n'arrivera pas.</li>
          <li><strong>Relance</strong> — Si l'email ne vous parvient pas, le système de relance vous renverra un email après le délai configuré (48h par défaut).</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Comment ajouter un nouveau type de formulaire ?</summary>
      <div class="detail-body">
        <p>Pour créer un nouveau formulaire :</p>
        <ol>
          <li>Accédez à <strong>admin_forms.php</strong> (il faut être administrateur).</li>
          <li>Dans la section « Ajouter un formulaire », renseignez :
            <ul>
              <li><strong>Slug</strong> — Un identifiant court, sans espaces ni accents (ex : <code>demande_conge</code>). Ce slug sera utilisé dans l'URL.</li>
              <li><strong>Libellé</strong> — Le titre affiché du formulaire (ex : « Demande de congé »).</li>
              <li><strong>Description</strong> — Un texte explicatif affiché en haut du formulaire.</li>
            </ul>
          </li>
          <li>Cliquez sur <strong>Ajouter</strong>.</li>
          <li>Ajoutez ensuite les <strong>étapes</strong> et les <strong>destinataires</strong> pour ce formulaire.</li>
        </ol>
        <div class="warn-box">
          <p><strong>⚠ Note :</strong> Le formulaire par défaut (arrivée d'un agent) a des champs codés en dur dans <code>form.php</code>.
          Pour un formulaire avec des champs différents, une adaptation du code sera nécessaire.
          Contactez l'équipe technique si besoin.</p>
        </div>
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
          <p><strong>Attention :</strong> Modifier l'ordre n'affecte que les <em>nouvelles</em> soumissions.
          Les soumissions déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
        </div>
      </div>
    </details>

    <details>
      <summary>Comment configurer l'envoi d'emails ?</summary>
      <div class="detail-body">
        <p>Les paramètres d'envoi d'emails peuvent être configurés de deux manières :</p>
        <ol>
          <li><strong>Via l'interface</strong> — Accédez à <strong>admin_settings.php</strong> (réservé au super administrateur). Vous y trouverez les champs pour le serveur SMTP, le port, l'expéditeur, le nom et le délai de relance.</li>
          <li><strong>Via le fichier de configuration</strong> — Éditez le fichier <code>config.php</code> pour modifier les constantes :
            <ul>
              <li><code>SMTP_HOST</code> — Serveur SMTP (ex : smtp.social.gouv.fr)</li>
              <li><code>SMTP_PORT</code> — Port (ex : 25)</li>
              <li><code>SMTP_FROM</code> — Adresse d'expédition (ex : workflow@dreets.gouv.fr)</li>
              <li><code>SMTP_FROM_NAME</code> — Nom de l'expéditeur (ex : Workflow DREETS)</li>
              <li><code>DELAI_RELANCE_H</code> — Délai de relance en heures (ex : 48)</li>
            </ul>
          </li>
        </ol>
        <p>Si l'interface admin_settings.php est disponible, préférez cette méthode : elle ne nécessite pas d'accès au serveur de fichiers.</p>
      </div>
    </details>

    <details>
      <summary>Je suis validateur mais je ne fais pas partie du réseau DREETS. Puis-je valider ?</summary>
      <div class="detail-body">
        <p><strong>Oui.</strong> La page de validation (validate.php) est accessible sans authentification Windows.
        Le lien contenu dans l'email suffit pour valider ou refuser une étape.
        Vous n'avez pas besoin d'un compte sur le réseau DREETS.</p>
      </div>
    </details>

    <details>
      <summary>Puis-je annuler une validation ?</summary>
      <div class="detail-body">
        <p><strong>Non.</strong> Une fois une action effectuée (validation ou refus), elle est irréversible.
        Le token est marqué comme utilisé et ne peut plus être consommé.
        En cas d'erreur, contactez l'administrateur qui pourra examiner la situation depuis le tableau de bord.</p>
      </div>
    </details>
  </div>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 7. ARCHITECTURE TECHNIQUE                                   -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="technique">
    <h2>7. Architecture technique (résumé)</h2>
    <p style="margin-bottom:1rem;color:#555;">
      Cette section est destinée au personnel IT. Elle fournit un aperçu rapide de l'architecture,
      sans entrer dans les détails du code.
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
          &nbsp;&nbsp;<span class="file">validate.php</span> — Page de validation/refus (accessible par token)<br>
          &nbsp;&nbsp;<span class="file">dashboard.php</span> — Tableau de bord de supervision<br>
          &nbsp;&nbsp;<span class="file">admin_access.php</span> — Gestion des accès administrateur<br>
          &nbsp;&nbsp;<span class="file">admin_forms.php</span> — Gestion des formulaires, étapes, destinataires<br>
          &nbsp;&nbsp;<span class="file">admin_settings.php</span> — Configuration SMTP (super admin)<br>
          &nbsp;&nbsp;<span class="file">remind.php</span> — Script CLI de relance automatique<br>
          &nbsp;&nbsp;<span class="file">docs.php</span> — Cette page de documentation<br>
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
            <tr><td>id</td><td>INTEGER PK</td><td>Identifiant</td></tr>
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
            <tr><td>id</td><td>INTEGER PK</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>INTEGER FK</td><td>Formulaire parent</td></tr>
            <tr><td>label</td><td>TEXT</td><td>Libellé de l'étape</td></tr>
            <tr><td>ordre</td><td>INTEGER</td><td>Numéro d'ordre (détermine la séquence)</td></tr>
            <tr><td>actif</td><td>INTEGER</td><td>1 = actif</td></tr>
          </tbody>
        </table>

        <h3>Table <code>step_recipients</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>INTEGER PK</td><td>Identifiant</td></tr>
            <tr><td>step_id</td><td>INTEGER FK</td><td>Étape parent</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
          </tbody>
        </table>

        <h3>Table <code>submissions</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>INTEGER PK</td><td>Identifiant</td></tr>
            <tr><td>form_id</td><td>INTEGER FK</td><td>Formulaire utilisé</td></tr>
            <tr><td>data</td><td>TEXT (JSON)</td><td>Données du formulaire + historique des validations</td></tr>
            <tr><td>submitted_by</td><td>TEXT</td><td>Identifiant de l'agent (AUTH_USER)</td></tr>
            <tr><td>submitted_at</td><td>DATETIME</td><td>Date de soumission</td></tr>
            <tr><td>closed_at</td><td>DATETIME</td><td>Date de clôture (NULL si en cours)</td></tr>
            <tr><td>status</td><td>TEXT</td><td>Statut : en_cours, valide, refuse</td></tr>
          </tbody>
        </table>

        <h3>Table <code>tokens</code></h3>
        <table class="schema-table">
          <thead><tr><th>Colonne</th><th>Type</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>id</td><td>INTEGER PK</td><td>Identifiant</td></tr>
            <tr><td>submission_id</td><td>INTEGER FK</td><td>Soumission liée</td></tr>
            <tr><td>step_id</td><td>INTEGER FK</td><td>Étape liée</td></tr>
            <tr><td>email</td><td>TEXT</td><td>Email du validateur</td></tr>
            <tr><td>token</td><td>TEXT UNIQUE</td><td>Jeton unique (64 hex)</td></tr>
            <tr><td>sent_at</td><td>DATETIME</td><td>Date d'envoi de l'email</td></tr>
            <tr><td>done_at</td><td>DATETIME</td><td>Date de validation (NULL = en attente)</td></tr>
            <tr><td>relance_at</td><td>DATETIME</td><td>Date de dernière relance</td></tr>
            <tr><td>expires_at</td><td>DATETIME</td><td>Date d'expiration du token</td></tr>
          </tbody>
        </table>

        <h3>Tables <code>admins</code> et <code>admin_requests</code></h3>
        <table class="schema-table">
          <thead><tr><th>Table</th><th>Colonnes clés</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>admins</td><td>email (UNIQUE), added_at</td><td>Liste des administrateurs</td></tr>
            <tr><td>admin_requests</td><td>email, status, token</td><td>Demandes d'accès en attente</td></tr>
            <tr><td>settings</td><td>key (PK), value, updated_at, updated_by</td><td>Paramètres configurables (SMTP, délais...)</td></tr>
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
            <tr><td>Langage</td><td>PHP 7.4+ (procédural, sans framework)</td></tr>
            <tr><td>Base de données</td><td>SQLite (fichier db/workflow.db, mode WAL)</td></tr>
            <tr><td>Envoi d'emails</td><td>PHPMailer via SMTP (pas d'auth SMTP)</td></tr>
            <tr><td>Relance automatique</td><td>remind.php — exécuté par le Planificateur de tâches Windows</td></tr>
            <tr><td>Motif de sécurité</td><td>Tokens à usage unique (random_bytes 32 octets = 64 hex)</td></tr>
            <tr><td>Frontend</td><td>HTML/CSS embarqué, pas de JavaScript framework</td></tr>
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
          <li>Quand toutes les étapes sont validées : <code>closed_at</code> est renseigné → la soumission est clôturée.</li>
          <li>En parallèle, <code>remind.php</code> tourne toutes les 12h et envoie des relances aux validateurs en attente depuis plus de 48h.</li>
        </ol>
      </div>
    </details>
  </div>

</div>
</body>
</html>
