<?php
declare(strict_types=1);

namespace App\Docs;

class DocumentationService
{
    public function renderStart(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- ITER1-A — Section « Pour commencer » (4 cartes pour M. Robert) -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <section class="start-section" aria-label="Pour commencer">
    <h2><span aria-hidden="true">🇫🇷</span> Pour commencer</h2>
    <p class="start-intro"><?= \App\Core\App::html()->escape(t_jargon('Choisissez l\'action que vous voulez faire. Chaque carte vous explique les étapes en français courant.')) ?></p>

    <div class="start-cards">
      <!-- Carte 1 : Comment faire une demande ? -->
      <div class="start-card">
        <span class="start-card-icon" aria-hidden="true">📝</span>
        <h3>Comment faire une demande ?</h3>
        <p>Vous êtes agent et vous voulez remplir un formulaire.</p>
        <ol class="start-card-steps">
          <li><strong>Choisissez</strong> votre formulaire sur la page des formulaires.</li>
          <li><strong>Remplissez</strong> les champs, puis cliquez sur « Envoyer ».</li>
          <li><strong>Suivez</strong> l'avancement dans « Mes demandes ».</li>
        </ol>
        <a class="start-card-link" href="index.php"><span aria-hidden="true">🏠</span> Aller aux formulaires</a>
      </div>

      <!-- Carte 2 : Comment valider une demande ? -->
      <div class="start-card">
        <span class="start-card-icon" aria-hidden="true">✅</span>
        <h3>Comment valider une demande ?</h3>
        <p>Vous avez reçu un email vous demandant de valider une demande.</p>
        <ol class="start-card-steps">
          <li><strong>Cliquez</strong> sur le bouton dans l'email reçu.</li>
          <li><strong>Lisez</strong> les informations du formulaire.</li>
          <li><strong>Validez</strong> ou <strong>refusez</strong> avec un commentaire.</li>
        </ol>
        <a class="start-card-link" href="#guide-validateur"><span aria-hidden="true">📖</span> Voir le guide complet</a>
      </div>

      <!-- Carte 3 : Où voir mes demandes ? -->
      <div class="start-card">
        <span class="start-card-icon" aria-hidden="true">📊</span>
        <h3>Où voir mes demandes ?</h3>
        <p>Vous voulez savoir où en est une demande que vous avez faite.</p>
        <ol class="start-card-steps">
          <li>Allez sur la page <strong>« Mes demandes »</strong>.</li>
          <li>Repérez votre demande dans la liste.</li>
          <li>Cliquez sur <strong>« Détail »</strong> pour voir l'avancement.</li>
        </ol>
        <a class="start-card-link" href="index.php?p=my_submissions"><span aria-hidden="true">📋</span> Ouvrir « Mes demandes »</a>
      </div>

      <!-- Carte 4 : Besoin d'aide ? -->
      <div class="start-card start-card-help">
        <span class="start-card-icon" aria-hidden="true">🆘</span>
        <h3>Besoin d'aide ?</h3>
        <p>Une question, un blocage, une information manquante ?</p>
        <ol class="start-card-steps">
          <li>Consultez la <strong>FAQ</strong> ci-dessous (questions fréquentes).</li>
          <li>Contactez votre <strong>administrateur</strong> DREETS.</li>
          <li>Pour les données personnelles : <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>.</li>
        </ol>
        <a class="start-card-link" href="#faq"><span aria-hidden="true">❓</span> Voir la FAQ</a>
      </div>
    </div>
  </section>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderToc(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- ITER1-A — Sommaire (TOC) Marianne avec ancres vers sections -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <nav class="toc-marianne" aria-label="Sommaire">
    <h2><span aria-hidden="true">📚</span> Sommaire</h2>
    <ol>
      <li><a href="#demarrage-rapide">Guide de démarrage rapide</a></li>
      <li><a href="#guide-agent">Guide de l'agent — Soumettre une demande</a></li>
      <li><a href="#guide-validateur">Guide du validateur — Valider une demande</a></li>
      <li><a href="#guide-administrateur">Guide de l'administrateur</a></li>
      <li><a href="#fonctionnalites">Fonctionnalités de l'application</a></li>
      <li><a href="#roles-permissions">Rôles et permissions</a></li>
      <li><a href="#faq">FAQ — Questions fréquentes</a></li>
      <li><a href="#rgpd-legal">RGPD et mentions légales</a></li>
      <li><a href="#technique">Architecture technique (équipe IT)</a></li>
    </ol>
  </nav>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderQuickstart(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- GUIDE DE DÉMARRAGE RAPIDE                                  -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="demarrage-rapide">
    <h2><span aria-hidden="true">🚀</span> Guide de démarrage rapide</h2>
    <p>Bienvenue ! Voici comment fonctionne l'application en 3 étapes simples :</p>

    <div class="quickstart">
      <div class="quickstart-step">
        <span class="qs-num">1</span>
        <span class="qs-icon" aria-hidden="true">📝</span>
        <h3>Je remplis le formulaire</h3>
        <p>Je me connecte, je choisis mon formulaire, je remplis les champs et j'envoie.</p>
      </div>
      <div class="quickstart-arrow">→</div>
      <div class="quickstart-step">
        <span class="qs-num">2</span>
        <span class="qs-icon" aria-hidden="true">📧</span>
        <h3>Les validateurs reçoivent un email</h3>
        <p>Le système envoie automatiquement un email à chaque personne qui doit valider ma demande.</p>
      </div>
      <div class="quickstart-arrow">→</div>
      <div class="quickstart-step">
        <span class="qs-num">3</span>
        <span class="qs-icon" aria-hidden="true">📊</span>
        <h3>Je suis l'avancement en temps réel</h3>
        <p>Depuis « Mes demandes », je vois qui a validé et où en est ma demande.</p>
      </div>
    </div>

    <!-- ── Workflow visual mockup ── -->
    <div class="mockup">
      <p class="u-c-primary-fs-md-fw-bold-mb-075-0a644b">Circuit de validation — Vue d'ensemble</p>
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
      <p class="u-c-muted-fs-lg-m-custom-751a63">Exemple : les étapes s'enchaînent automatiquement après chaque validation</p>
    </div>

    <div class="tip-box">
      <p>Pas de panique : le système s'occupe de tout. Vous n'avez qu'à remplir le formulaire, les emails partent automatiquement et les relances aussi !</p>
    </div>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderAgent(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 2. GUIDE DE L'AGENT                                        -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-agent">
    <h2>2. Guide de l'agent — Soumettre une demande</h2>

    <p>En tant qu'agent, vous pouvez <strong>remplir un formulaire</strong>, <strong>suivre l'avancement</strong> de vos demandes et <strong>annuler</strong> une demande en cours.</p>

    <!-- ── Accéder à un formulaire ── -->
    <h3><span aria-hidden="true">📝</span> Accéder à un formulaire</h3>

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
      <p><code>index.php?p=form&f=onboarding</code></p>
      <p><small>Le nom « onboarding » est un exemple. Demandez à votre administrateur pour connaître les formulaires disponibles.</small></p>
    </div>

    <div class="tip-box">
      <p>Comment trouver les formulaires ? Regardez dans le menu de navigation ou demandez le lien à votre administrateur. Chaque service a ses propres formulaires.</p>
    </div>

    <img src="index.php?p=screenshot&f=01_index_agent.png" alt="Page des formulaires de l'agent — liste des formulaires disponibles" class="screenshot">
    <p class="screenshot-caption">Page des formulaires vue par un agent — les formulaires disponibles s'affichent directement</p>

    <!-- ── Remplir le formulaire ── -->
    <h3><span aria-hidden="true">✍️</span> Remplir le formulaire</h3>

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
        <p><strong>Envoyer</strong> — Quand tout est rempli, cliquez sur le bouton <em>« Envoyer la déclaration »</em> pour soumettre votre <strong>demande</strong>.</p>
      </div>
    </div>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Champs obligatoires</strong> — Les champs marqués d'une astérisque rouge (*) doivent obligatoirement être remplis. Si vous oubliez un champ obligatoire, le formulaire vous le signalera.</p>
    </div>

    <div class="tip-box">
      <p>Prenez le temps de vérifier vos informations avant d'envoyer. Une fois envoyé, vous ne pouvez plus modifier les données du formulaire.</p>
    </div>

    <img src="index.php?p=screenshot&f=03_form_onboarding.png" alt="Formulaire d'onboarding — sections à remplir par l'agent" class="screenshot">
    <p class="screenshot-caption">Exemple de formulaire d'arrivée d'un agent (onboarding) — sections Identité, IT, RH, Logistique</p>

    <img src="index.php?p=screenshot&f=04_form_acces_si.png" alt="Formulaire d'accès SI — demande d'accès aux systèmes d'information" class="screenshot">
    <p class="screenshot-caption">Formulaire de demande d'accès aux systèmes d'information — création, modification ou suppression de comptes</p>

    <!-- ── Après l'envoi ── -->
    <h3><span aria-hidden="true">✅</span> Que se passe-t-il après l'envoi ?</h3>

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
    <h3><span aria-hidden="true">📊</span> Suivre l'avancement de mes demandes</h3>

    <p>Pour voir où en est votre demande, rendez-vous sur la page <strong>« Mes demandes »</strong> (<code>index.php?p=my_submissions</code>).</p>

    <!-- ── Progress bar mockup ── -->
    <div class="mockup">
      <p class="u-c-primary-fs-md-fw-bold-mb-075-0a644b">Barre de progression d'une demande</p>
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
      <p class="u-c-primary-fs-md-fw-bold-mb-075-0a644b">Badges de statut</p>
      <p class="u-m-0-1386d5">
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
          <li class="u-c-success-8becb1"><strong>■ Vert</strong> = validé (étape terminée)</li>
          <li class="u-c-warning-0c118e"><strong>■ Orange</strong> = en attente (étape en cours)</li>
          <li class="u-c-muted-89d000"><strong>■ Gris</strong> = pas encore démarré</li>
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

    <img src="index.php?p=screenshot&f=05_my_submissions.png" alt="Page Mes demandes — liste des demandes de l'agent avec statuts" class="screenshot">
    <p class="screenshot-caption">Page « Mes demandes » — chaque demande affiche son statut et son avancement</p>

    <!-- ── Annuler une demande ── -->
    <h3><span aria-hidden="true">❌</span> Annuler une demande</h3>

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
      <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> L'annulation est irréversible. Vous ne pourrez pas rouvrir la demande. Si vous voulez soumettre à nouveau, il faudra remplir un nouveau formulaire.</p>
    </div>

    <!-- ── Droits RGPD ── -->
    <h3><span aria-hidden="true">🔒</span> Mes droits (RGPD)</h3>

    <p>Conformément au Règlement Général sur la Protection des Données, vous disposez de droits sur vos données :</p>
    <ul>
      <li><strong>Droit d'accès</strong> — Vous pouvez consulter toutes les données vous concernant depuis « Mes demandes ».</li>
      <li><strong>Droit de rectification</strong> — Contactez votre administrateur pour corriger des données erronées.</li>
      <li><strong>Droit d'effacement</strong> — Vous pouvez demander la suppression de vos données en contactant l'administrateur ou le <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>.</li>
      <li><strong>Durée de conservation</strong> — Vos données sont conservées pendant une durée limitée (par défaut 24 mois après la clôture de la demande), puis automatiquement supprimées.</li>
    </ul>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderValidateur(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 3. GUIDE DU VALIDATEUR                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-validateur">
    <h2>3. Guide du validateur — Valider une demande</h2>

    <p>En tant que validateur, vous recevez des demandes à traiter. Voici comment ça fonctionne, étape par étape.</p>

    <!-- ── Recevoir un email ── -->
    <h3><span aria-hidden="true">📧</span> Je reçois un email de validation</h3>

    <p>Quand une demande nécessite votre intervention, vous recevez un email de <strong><?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('smtp_from', 'workflow@dreets.gouv.fr')) ?></strong> avec l'objet :</p>

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
      <div class="email-header">CircuitDémat — Action requise</div>
      <div class="email-body">
        <p class="u-fs-base-mb-075-2af86d">Bonjour,</p>
        <p class="u-fs-base-mb-075-2af86d">Une nouvelle demande nécessite votre validation pour l'étape <strong>Informatique</strong>.</p>
        <table>
          <tr><td>Agent</td><td>Dupont Marie</td></tr>
          <tr><td>Service</td><td>Service Emploi</td></tr>
          <tr><td>Date de prise de poste</td><td>15/03/2025</td></tr>
          <tr><td>Type de poste</td><td>Portable + double écran</td></tr>
        </table>
        <span class="email-btn">✓ Marquer comme effectué</span>
      </div>
      <div class="email-footer"><span aria-hidden="true">🔒</span> Lien à usage unique — Ce lien ne fonctionnera plus après validation ou refus.</div>
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
    <h3><span aria-hidden="true">✅</span> Valider ou <span aria-hidden="true">❌</span> Refuser</h3>

    <p>Vous avez deux options :</p>

    <div class="step-row">
      <span class="step-num">A</span>
      <div class="step-text">
        <p><strong><span aria-hidden="true">✅</span> Valider</strong> — Confirme que l'étape est traitée. Le système passe automatiquement à l'étape suivante et envoie un email au validateur suivant.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">B</span>
      <div class="step-text">
        <p><strong><span aria-hidden="true">❌</span> Refuser</strong> — Bloque la demande. Elle est immédiatement clôturée avec le statut « Refusé ». Les étapes suivantes ne seront pas déclenchées.</p>
      </div>
    </div>

    <p>Dans les deux cas, vous pouvez ajouter un <strong>commentaire</strong> (facultatif mais recommandé) pour expliquer votre décision.</p>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Important :</strong> Le lien de validation est à <strong>usage unique</strong>. Une fois que vous avez cliqué sur Valider ou Refuser, le lien ne fonctionne plus. Si vous voyez « Déjà validé », cela signifie que l'action a déjà été effectuée (par vous ou par un collègue partageant la même adresse email).</p>
    </div>

    <img src="index.php?p=screenshot&f=16_validate.png" alt="Page de validation — boutons Valider et Refuser" class="screenshot">
    <p class="screenshot-caption">Page de validation — le validateur peut valider ou refuser l'étape avec un commentaire</p>

    <img src="index.php?p=screenshot&f=17_submission_view.png" alt="Vue détaillée d'une demande — progression du circuit de validation, délégation et historique" class="screenshot">
    <p class="screenshot-caption">Vue détaillée d'une demande — progression du circuit de validation, options de délégation et historique des validations</p>

    <!-- ── Après la validation ── -->
    <h3><span aria-hidden="true">➡️</span> Que se passe-t-il après ?</h3>

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
    <h3><span aria-hidden="true">🔄</span> Déléguer ma validation</h3>

    <p>Si vous n'êtes pas la bonne personne pour valider, vous pouvez <strong>déléguer</strong> la validation à un collègue :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Allez sur « Mes validations »</strong> (<code>index.php?p=my_validations</code>) — Cette page liste toutes les demandes qui attendent votre validation.</p>
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
    <h3><span aria-hidden="true">📋</span> Suivre mes validations</h3>

    <p>La page <strong>« Mes validations »</strong> (<code>index.php?p=my_validations</code>) vous permet de :</p>
    <ul>
      <li>Voir les <strong>demandes en attente</strong> de votre validation</li>
      <li>Consulter l'<strong>historique</strong> de vos validations passées</li>
      <li><strong>Déléguer</strong> une validation à un collègue</li>
      <li>Accéder directement au <strong>lien de validation</strong></li>
    </ul>

    <img src="index.php?p=screenshot&f=06_my_validations.png" alt="Page Mes validations — demandes en attente et historique" class="screenshot">
    <p class="screenshot-caption">Page « Mes validations » — vue des demandes en attente et de l'historique de validation</p>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderAdmin(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 4. GUIDE DE L'ADMINISTRATEUR                               -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-administrateur">
    <h2>4. Guide de l'administrateur — Configurer et superviser</h2>

    <p>En tant qu'administrateur, vous configurez les formulaires, supervisez les demandes et gérez la conformité RGPD.</p>

    <!-- ── Accès admin ── -->
    <h3><span aria-hidden="true">🔑</span> Obtenir l'accès administrateur</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Accédez à la page</strong> <code>index.php?p=admin_access</code> et cliquez sur <em>« Demander l'accès admin »</em>.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Votre demande est envoyée</strong> par email au super administrateur, qui peut l'approuver ou la refuser.</p>
      </div>
    </div>

    <img src="index.php?p=screenshot&f=09_admin_access.png" alt="Gestion des accès administrateur — demande et approbation" class="screenshot">
    <p class="screenshot-caption">Gestion des accès administrateur — processus de demande et d'approbation par le super administrateur</p>

    <img src="index.php?p=screenshot&f=02_index_admin.png" alt="Page d'accueil administrateur — accès au back office" class="screenshot">
    <p class="screenshot-caption">Page d'accueil vue par un administrateur — accès direct au back office et aux outils de gestion</p>

    <!-- ── Tableau de bord ── -->
    <h3><span aria-hidden="true">📊</span> Tableau de bord</h3>

    <p>Le <strong>tableau de bord</strong> (<code>index.php?p=dashboard</code>) est votre centre de commande. Il affiche :</p>

    <ul>
      <li><strong>Statistiques</strong> — Nombre total de demandes, en cours, clôturées</li>
      <li><strong>Filtres</strong> — Par statut (tous, en cours, clôturés) et par formulaire</li>
      <li><strong>Badges du circuit de validation</strong> — Chaque étape est représentée par un badge coloré :
        <ul>
          <li class="u-c-success-8becb1">■ <strong>Vert</strong> = validé</li>
          <li class="u-c-warning-0c118e">■ <strong>Orange</strong> = en attente (étape courante)</li>
          <li class="u-c-muted-89d000">■ <strong>Gris</strong> = pas encore démarré</li>
        </ul>
      </li>
      <li><strong>Bouton « détail »</strong> — Affiche l'historique des validations et les données du formulaire</li>
      <li><strong>Export CSV</strong> — Téléchargez les données au format tableur</li>
      <li><strong>Relance manuelle</strong> — Relancez individuellement un validateur en attente</li>
      <li><strong>Annulation</strong> — Annulez une demande en cours</li>
    </ul>

    <div class="tip-box">
      <p>Depuis le tableau de bord, vous pouvez aussi accéder aux pages de détail de chaque demande pour voir l'historique complet, les pièces jointes et les commentaires des validateurs.</p>
    </div>

    <img src="index.php?p=screenshot&f=07_dashboard.png" alt="Tableau de bord administrateur — statistiques et liste des demandes" class="screenshot">
    <p class="screenshot-caption">Tableau de bord — vue d'ensemble des demandes avec filtres, badges du circuit de validation et actions rapides</p>

    <!-- ── Gestion des formulaires ── -->
    <h3><span aria-hidden="true">📝</span> Gestion des formulaires</h3>

    <p>Depuis la page <strong>index.php?p=admin_forms</strong>, vous pouvez :</p>

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
    <h4><span aria-hidden="true">📋</span> Référence des types de champs</h4>
    <p>Les champs suivants sont disponibles lors de la configuration d'un formulaire :</p>
    <table class="schema-table">
      <thead><tr><th>Type</th><th>Code</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><span aria-hidden="true">📝</span> Texte court</td><td><code>text</code></td><td>Champ texte simple sur une ligne (nom, prénom, numéro…)</td></tr>
        <tr><td><span aria-hidden="true">📅</span> Date</td><td><code>date</code></td><td>Sélecteur de date (jj/mm/aaaa) — date de naissance, prise de poste…</td></tr>
        <tr><td><span aria-hidden="true">📋</span> Liste déroulante</td><td><code>select</code></td><td>Choix unique parmi une liste prédéfinie (corps/grade, type de poste…)</td></tr>
        <tr><td><span aria-hidden="true">☑️</span> Case à cocher</td><td><code>checkbox</code></td><td>Choix multiples à cocher (options IT, actions RH…)</td></tr>
        <tr><td><span aria-hidden="true">📄</span> Zone de texte</td><td><code>textarea</code></td><td>Champ texte multiligne pour les commentaires ou descriptions longues</td></tr>
        <tr><td><span aria-hidden="true">📎</span> Fichier / Pièce jointe</td><td><code>file</code></td><td>Téléversement de fichier (stockage sécurisé en BDD, accès par lien sécurisé)</td></tr>
      </tbody>
    </table>

    <img src="index.php?p=screenshot&f=10_admin_forms.png" alt="Page d'administration des formulaires — gestion des formulaires et champs" class="screenshot">
    <p class="screenshot-caption">Administration des formulaires — créer, modifier et configurer les champs et le circuit de validation</p>

    <img src="index.php?p=screenshot&f=18_form_preview.png" alt="Prévisualisation du formulaire — vue telle que les agents la verront" class="screenshot">
    <p class="screenshot-caption">Prévisualisation du formulaire — l'administrateur peut visualiser le formulaire tel que le verront les agents avant publication</p>

    <!-- ── Gestion des étapes et destinataires ── -->
    <h3><span aria-hidden="true">🔄</span> Gestion des étapes et destinataires</h3>

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
        <p><strong>Ordre = séquence</strong> — L'ordre détermine la séquence du circuit de validation :
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
      <p>Modifier l'ordre des étapes n'affecte que les <em>nouvelles</em> demandes. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
    </div>

    <!-- ── Alertes de deadline ── -->
    <h3><span aria-hidden="true">⏰</span> Configuration des alertes</h3>

    <p>La page <strong>index.php?p=admin_alerts</strong> permet de configurer des <strong>alertes automatiques</strong> quand une demande approche d'une date limite :</p>

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

    <img src="index.php?p=screenshot&f=11_admin_alerts.png" alt="Page de configuration des alertes — règles de deadline" class="screenshot">
    <p class="screenshot-caption">Configuration des alertes — définir des règles de notification avant les dates limites</p>

    <!-- ── Surveillance ── -->
    <h3><span aria-hidden="true">🔍</span> Surveillance et supervision</h3>

    <p>La page <strong>index.php?p=monitoring</strong> vous donne une vue d'ensemble de l'état du système :</p>
    <ul>
      <li><strong>Temps moyen de traitement</strong> — Combien de temps prennent les demandes en moyenne</li>
      <li><strong>Taux de validation</strong> — Pourcentage de demandes validées / refusées / en cours</li>
      <li><strong>Tokens bloqués</strong> — Les validations en attente depuis trop longtemps</li>
      <li><strong>Tokens expirés</strong> — Les liens de validation qui ont dépassé leur date d'expiration</li>
      <li><strong>Alertes actives</strong> — Les demandes proches de leur deadline</li>
      <li><strong>Activité récente</strong> — Les dernières actions (validations, refus, créations)</li>
    </ul>

    <div class="tip-box">
      <p>Consultez régulièrement la page de surveillance pour identifier les validateurs qui tardent à répondre et les relancer si nécessaire.</p>
    </div>

    <img src="index.php?p=screenshot&f=08_monitoring.png" alt="Page de surveillance — état du système et alertes" class="screenshot">
    <p class="screenshot-caption">Surveillance — temps moyen, taux de validation, jetons bloqués et activité récente</p>

    <!-- ── Mode persona ── -->
    <h3><span aria-hidden="true">🎭</span> Mode persona — visualiser l'interface comme un agent</h3>

    <p>Le <strong>mode persona</strong> permet à un administrateur de <strong>visualiser l'interface exactement comme la verrait un agent utilisateur</strong>. C'est un outil précieux pour vérifier qu'un formulaire, un circuit de validation ou une page fonctionne correctement du point de vue métier, sans avoir à demander à un agent de tester ou à utiliser ses identifiants.</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Ouvrez le menu utilisateur</strong> — Dans la barre latérale (en bas à gauche), cliquez sur votre <em>carte utilisateur</em> (votre nom et avatar). Un menu déroulant apparaît.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Cliquez sur « 👤 Vue agent »</strong> — Le système génère alors un <strong>jeton de persona</strong> aléatoire (stocké en base dans la table <code>persona_tokens</code>) et vous redirige avec le paramètre <code>?persona_token=XXX</code> ajouté à l'URL.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Naviguez comme un agent</strong> — Toutes les pages affichent désormais les données telles qu'un agent les verrait : la section d'administration de la barre latérale est masquée et les demandes, formulaires et notifications sont filtrés selon la vue agent.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Quittez le mode persona</strong> — Cliquez sur le bouton <strong>« ✕ Quitter »</strong> de la bannière jaune pour revenir à votre session administrateur. Le jeton est révoqué et la barre latérale d'administration réapparaît.</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Ce qui change en mode persona :</strong></p>
      <ul>
        <li>La section <em>Administration</em> de la barre latérale est <strong>masquée</strong> (la fonction <code>is_admin_effective()</code> renvoie <code>false</code>).</li>
        <li>Les <strong>données affichées sont filtrées</strong> comme si vous étiez l'agent sélectionné (demandes, formulaires, historique).</li>
        <li>Une <strong>bannière jaune « 🎭 Mode persona »</strong> apparaît en haut de chaque page avec un bouton <strong>« ✕ Quitter »</strong>.</li>
        <li>Le jeton est <strong>propagé automatiquement</strong> dans toutes les URLs via la fonction <code>persona_rewrite_urls()</code> — vous n'avez rien à gérer manuellement.</li>
      </ul>
    </div>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">🔒</span> Sécurité :</strong> Le mode persona ne fait que <strong>rétrograder</strong> les privilèges (admin → agent). Il ne permet jamais à un agent de <strong>monter</strong> en privilèges vers administrateur. Ainsi, même si un jeton fuite, l'attaquant ne ferait que visualiser l'interface en tant qu'utilisateur simple. Le jeton <strong>expire automatiquement après 8 heures</strong> et devient également inactif si l'administrateur qui l'a créé perd ses droits entre-temps.</p>
    </div>

    <div class="tip-box">
      <p>Le mode persona est idéal pour : vérifier qu'un formulaire nouvellement créé s'affiche correctement, contrôler ce qu'un agent voit dans son tableau de bord, ou reproduire un signalement d'utilisateur sans avoir besoin de ses identifiants.</p>
    </div>

    <!-- ── Statistiques ── -->
    <h3><span aria-hidden="true">📈</span> Statistiques et reporting</h3>

    <p>La page <strong>index.php?p=stats</strong> fournit des statistiques détaillées :</p>
    <ul>
      <li><strong>Statistiques globales</strong> — Total, en cours, validés, refusés</li>
      <li><strong>Statistiques par période</strong> — Vue par semaine, mois ou année</li>
      <li><strong>Statistiques par formulaire</strong> — Nombre de demandes et temps moyen de traitement pour chaque formulaire</li>
      <li><strong>Statistiques par validateur</strong> — Nombre de validations, temps de réponse moyen</li>
      <li><strong>Graphique de répartition</strong> — Visualisation des statuts sous forme de graphique</li>
    </ul>

    <!-- ── RGPD ── -->
    <h3><span aria-hidden="true">🔒</span> Conformité RGPD</h3>

    <p>La page <strong>index.php?p=rgpd</strong> vous permet de gérer la conformité au Règlement Général sur la Protection des Données :</p>

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

    <!-- ── Health check ── -->
    <h3><span aria-hidden="true">💚</span> Health check (vérification de santé)</h3>

    <p>La page <strong>index.php?p=health</strong> vérifie automatiquement l'état de santé de l'application :</p>
    <ul>
      <li><strong>Base de données</strong> — SQLite est-elle accessible ?</li>
      <li><strong>Version PHP</strong> — Est-elle compatible ?</li>
      <li><strong>Répertoire de données</strong> — Est-il accessible en écriture ?</li>
      <li><strong>Schéma de base</strong> — Toutes les tables sont-elles présentes ?</li>
      <li><strong>Configuration SMTP</strong> — L'envoi d'emails est-il configuré ?</li>
    </ul>
    <p>Cette page retourne un statut HTTP 200 si tout va bien, ou 503 si un problème est détecté. Elle peut être utilisée par les outils de supervision externes.</p>

    <!-- ── Sauvegarde et restauration ── -->
    <h3><span aria-hidden="true">💾</span> Sauvegarde et restauration</h3>

    <p>La page <strong>index.php?p=backup</strong> permet de sauvegarder et restaurer la base de données :</p>

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
      <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> La restauration remplace toutes les données actuelles. Effectuez toujours une sauvegarde avant de restaurer. La restauration est irréversible.</p>
    </div>

    <div class="tip-box">
      <p>Prenez l'habitude de télécharger une sauvegarde régulièrement (par exemple chaque semaine). En cas de problème, vous pourrez toujours revenir à une version antérieure.</p>
    </div>

    <!-- ── Paramètres SMTP ── -->
    <h3><span aria-hidden="true">⚙️</span> Configuration des paramètres</h3>

    <p>La page <strong>index.php?p=admin_settings</strong> (réservée au super administrateur) permet de configurer :</p>
    <ul>
      <li><strong>Serveur SMTP</strong> — L'adresse du serveur d'envoi d'emails (ex : smtp.social.gouv.fr)</li>
      <li><strong>Port SMTP</strong> — Le port du serveur (ex : 25)</li>
      <li><strong>Expéditeur</strong> — L'adresse email d'expédition (ex : <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('smtp_from', 'workflow@dreets.gouv.fr')) ?>)</li>
      <li><strong>Nom de l'expéditeur</strong> — Le nom affiché (ex : CircuitDémat)</li>
      <li><strong>Délai de relance</strong> — Le nombre d'heures avant l'envoi d'un rappel automatique (ex : 48h)</li>
    </ul>
    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Accès restreint :</strong> La page de paramètres est réservée au <strong>super administrateur</strong>.</p>
    </div>

    <img src="index.php?p=screenshot&f=12_admin_settings.png" alt="Page des paramètres — configuration SMTP et relances" class="screenshot">
    <p class="screenshot-caption">Paramètres — configuration SMTP et délai de relance (réservé au super admin)</p>

    <img src="index.php?p=screenshot&f=13_docs.png" alt="Page de documentation et d'aide en ligne" class="screenshot">
    <p class="screenshot-caption">Page d'aide et documentation — guide complet accessible à tous les utilisateurs</p>

    <img src="index.php?p=screenshot&f=14_changelog.png" alt="Journal des modifications — historique des versions" class="screenshot">
    <p class="screenshot-caption">Journal des modifications — historique des évolutions et corrections par version</p>

    <!-- ── Admin vs Super admin ── -->
    <h3><span aria-hidden="true">👑</span> Admin vs Super admin</h3>
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
        <tr><td>Accéder à la surveillance</td><td>✓</td><td>✓</td></tr>
        <tr><td>Configurer les alertes</td><td>✓</td><td>✓</td></tr>
        <tr><td>Sauvegarder et restaurer</td><td>✓</td><td>✓</td></tr>
        <tr><td>Gérer la conformité RGPD</td><td>✓</td><td>✓</td></tr>
        <tr><td>Approuver / refuser les demandes d'accès</td><td></td><td>✓</td></tr>
        <tr><td>Gérer la liste des administrateurs</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les paramètres SMTP</td><td></td><td>✓</td></tr>
      </tbody>
    </table>
    <p>
      Le super administrateur est défini par son adresse email dans la configuration. Il s'agit généralement du premier administrateur, qui ne peut pas être supprimé.
    </p>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderFeatures(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 5. FONCTIONNALITÉS                                         -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="fonctionnalites">
    <h2>5. Fonctionnalités de l'application</h2>

    <p>Voici la liste complète des fonctionnalités de l'application :</p>

    <div class="feature-grid">
      <div class="feature-item">
        <strong><span aria-hidden="true">📝</span> Formulaires dynamiques configurables</strong>
        <p>Créez et configurez vos formulaires sans coder. Champs texte, listes déroulantes, cases à cocher, pièces jointes.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔄</span> Circuit de validation séquentiel et parallèle</strong>
        <p>Définissez l'ordre de validation : étape par étape ou plusieurs en même temps, selon les besoins de chaque formulaire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔐</span> Tokens cryptographiques à usage unique</strong>
        <p>Chaque lien de validation est unique et sécurisé. Une fois utilisé, il ne peut plus servir, garantissant l'intégrité du processus.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📎</span> Pièces jointes sécurisées</strong>
        <p>Les fichiers joints sont stockés de manière sécurisée en base de données. Seules les personnes autorisées peuvent les télécharger.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔔</span> Relances automatiques et manuelles</strong>
        <p>Le système relance automatiquement les validateurs en attente. L'administrateur peut aussi relancer manuellement.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔄</span> Délégation de validation</strong>
        <p>Un validateur peut transférer sa validation à un collègue. La délégation est tracée dans l'historique.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">⏰</span> Alertes de deadline configurables</strong>
        <p>Recevez une alerte quand une demande approche de sa date limite. Configurable par formulaire et par destinataire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📈</span> Statistiques et tableaux de bord</strong>
        <p>Suivez les performances : temps de traitement, taux de validation, répartition par période et par formulaire.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">📋</span> Journal d'audit complet</strong>
        <p>Chaque action est enregistrée : qui a fait quoi et quand. Traçabilité totale pour la conformité et le contrôle.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🔒</span> Conformité RGPD</strong>
        <p>Export, suppression et purge automatique des données. Durée de conservation configurable. Droit d'accès et d'effacement garantis.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">💚</span> Contrôle de santé pour la surveillance</strong>
        <p>Vérifiez automatiquement l'état de l'application : base de données, configuration email, version PHP.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">💾</span> Sauvegarde et restauration</strong>
        <p>Téléchargez une sauvegarde complète et restaurez-la en cas de besoin. Sécurisez vos données simplement.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🎨</span> Design Marianne / RGAA accessible</strong>
        <p>Interface conforme au système de design de l'État et aux normes d'accessibilité RGAA. Utilisable par tous.</p>
      </div>
      <div class="feature-item">
        <strong><span aria-hidden="true">🛡️</span> Zéro JavaScript (sécurité maximale)</strong>
        <p>L'application fonctionne entièrement sans JavaScript côté client. Sécurité renforcée, compatible avec les navigateurs les plus restrictifs.</p>
      </div>
    </div>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderRoles(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 6. RÔLES ET PERMISSIONS                                    -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="roles-permissions">
    <h2>6. Rôles et permissions</h2>

    <p>L'application distingue quatre profils. Voici ce que chacun peut faire :</p>

    <h3><span class="role-badge role-agent">Agent</span> L'agent</h3>
    <p>L'agent est la personne qui remplit et soumet un formulaire (par exemple, un agent qui déclare son arrivée).</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Remplir un formulaire et soumettre une demande</li>
      <li><span aria-hidden="true">✅</span> Suivre l'avancement de <strong>ses propres</strong> demandes (page « Mes demandes »)</li>
      <li><span aria-hidden="true">✅</span> Annuler une de ses demandes en cours</li>
      <li><span aria-hidden="true">✅</span> Consulter les détails de ses demandes</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas voir les demandes des autres agents</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas configurer les formulaires ni les étapes</li>
    </ul>

    <h3><span class="role-badge role-validator">Validateur</span> Le validateur</h3>
    <p>Le validateur reçoit les demandes à traiter. Il n'a pas besoin d'être sur le réseau DREETS.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Valider ou refuser une demande (via le lien email)</li>
      <li><span aria-hidden="true">✅</span> Consulter les détails d'une demande à valider</li>
      <li><span aria-hidden="true">✅</span> Déléguer sa validation à un autre validateur</li>
      <li><span aria-hidden="true">✅</span> Suivre ses validations en attente et passées (page « Mes validations »)</li>
      <li><span aria-hidden="true">✅</span> Ajouter un commentaire lors de la validation ou du refus</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas modifier une demande déjà envoyée</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas accéder au tableau de bord administrateur</li>
    </ul>

    <h3><span class="role-badge role-admin">Admin</span> L'administrateur</h3>
    <p>L'administrateur configure et supervise l'application. Il a accès à toutes les fonctions de gestion.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Tout ce que peut faire un agent + un validateur</li>
      <li><span aria-hidden="true">✅</span> Voir le tableau de bord (toutes les demandes)</li>
      <li><span aria-hidden="true">✅</span> Créer, modifier et désactiver des formulaires</li>
      <li><span aria-hidden="true">✅</span> Configurer les étapes et les destinataires</li>
      <li><span aria-hidden="true">✅</span> Configurer les alertes de deadline</li>
      <li><span aria-hidden="true">✅</span> Consulter les statistiques et la surveillance</li>
      <li><span aria-hidden="true">✅</span> Gérer la conformité RGPD (export, suppression)</li>
      <li><span aria-hidden="true">✅</span> Sauvegarder et restaurer la base de données</li>
      <li><span aria-hidden="true">✅</span> Relancer manuellement un validateur</li>
      <li><span aria-hidden="true">✅</span> Annuler n'importe quelle demande en cours</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas gérer les administrateurs</li>
      <li><span aria-hidden="true">❌</span> Ne peut pas modifier les paramètres SMTP</li>
    </ul>

    <h3><span class="role-badge role-superadmin">Super admin</span> Le super administrateur</h3>
    <p>Le super administrateur a tous les droits. Il y en a généralement un seul dans l'organisation.</p>
    <ul>
      <li><span aria-hidden="true">✅</span> Tout ce que peut faire un administrateur</li>
      <li><span aria-hidden="true">✅</span> Approuver ou refuser les demandes d'accès administrateur</li>
      <li><span aria-hidden="true">✅</span> Gérer la liste des administrateurs (ajouter, supprimer)</li>
      <li><span aria-hidden="true">✅</span> Configurer les paramètres SMTP (serveur, port, expéditeur)</li>
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
        <tr><td>Statistiques / surveillance</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Conformité RGPD</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Sauvegarde / restauration</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td><td class="perm-yes">✓</td></tr>
        <tr><td>Gérer les administrateurs</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
        <tr><td>Paramètres SMTP</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-no">—</td><td class="perm-yes">✓</td></tr>
      </tbody>
    </table>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderFaq(): string
    {
        ob_start();
        ?>
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
          <li><strong>Configuration SMTP</strong> — Si le problème persiste, l'administrateur peut vérifier les paramètres SMTP dans index.php?p=admin_settings.</li>
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
        <p>Vous pouvez demander la suppression anticipée de vos données en contactant l'administrateur ou le <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>.</p>
      </div>
    </details>

    <details>
      <summary>Comment ajouter un nouveau formulaire ?</summary>
      <div class="detail-body">
        <p>Pour créer un nouveau formulaire (réservé aux administrateurs) :</p>
        <ol>
          <li>Accédez à <strong>index.php?p=admin_forms</strong>.</li>
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
          <li>Accédez à <strong>index.php?p=admin_alerts</strong> (réservé aux administrateurs).</li>
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
          <li><strong>Consulter la surveillance</strong> — L'administrateur peut identifier les validateurs en retard depuis la page index.php?p=monitoring.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Puis-je modifier une demande déjà envoyée ?</summary>
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
          <li><strong>Export RGPD</strong> — Depuis la page index.php?p=rgpd, les administrateurs peuvent exporter toutes les données d'un utilisateur au format JSON (droit d'accès RGPD).</li>
          <li><strong>Sauvegarde complète</strong> — Depuis index.php?p=backup, vous pouvez télécharger une copie complète de la base de données.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Comment accéder aux statistiques ?</summary>
      <div class="detail-body">
        <p>Les statistiques sont accessibles aux administrateurs depuis la page <strong>index.php?p=stats</strong>. Vous y trouverez :</p>
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
          <li><strong>Droit d'effacement</strong> — L'administrateur peut anonymiser les données d'un utilisateur depuis index.php?p=rgpd.</li>
          <li><strong>Purge automatique</strong> — Les données sont automatiquement supprimées après la durée de conservation configurée (24 mois par défaut).</li>
          <li><strong>Mentions légales</strong> — Un texte d'information est affiché aux utilisateurs en bas de chaque formulaire.</li>
          <li><strong>Journal d'audit</strong> — Toutes les actions sont tracées.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>L'application est-elle conforme au RGAA ?</summary>
      <div class="detail-body">
        <p>CircuitDémat est <strong>partiellement conforme</strong> au RGAA 4.1 (Référentiel Général d'Amélioration de l'Accessibilité).</p>
        <ul>
          <li><strong>Déclaration d'accessibilité</strong> — La déclaration d'accessibilité est disponible dans <code>docs/declaration-rgaa.md</code>.</li>
          <li><strong>10 critères conformes</strong> — Navigation clavier, contraste des couleurs, structure des titres, formulaires étiquetés, textes alternatifs, etc.</li>
          <li><strong>3 non-conformités connues</strong> — Diagrammes SVG (non restituables par les lecteurs d'écran), tri des tableaux (pas d'alternative clavier), notifications toast (disparition trop rapide pour la lecture).</li>
          <li><strong>Droit de recours</strong> — En cas de difficulté d'accès, contactez le responsable accessibilité (voir la déclaration) ou le Défenseur des droits (<code>https://formulaire.defenseurdesdroits.fr/</code>).</li>
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
      <summary>Pourquoi les adresses email sont-elles affichées sans le domaine ?</summary>
      <div class="detail-body">
        <p>Pour réduire la surcharge visuelle (tous les agents sont dans le même domaine <code>@dreets.gouv.fr</code>), le système masque automatiquement le domaine des emails affichés :</p>
        <ul>
          <li><strong>Masquage du domaine</strong> — Quand l'utilisateur connecté est dans le même domaine que l'email affiché, le domaine est masqué (ex : <code>jean.dupont@dreets.gouv.fr</code> → <code>jean.dupont@</code>).</li>
          <li><strong>« Vous »</strong> — Si l'email affiché correspond à l'utilisateur connecté, le texte <strong>« Vous »</strong> s'affiche à la place de l'email.</li>
          <li><strong>Domaine différent</strong> — Si l'email appartient à un domaine différent (validateur externe), l'email complet est affiché car cette information est alors utile.</li>
          <li><strong>Email complet accessible</strong> — L'email complet reste accessible via le tooltip (survol de la souris) sur l'élément affiché.</li>
        </ul>
        <p>Ce comportement est géré par la méthode <code>displayUser()</code> de <code>HtmlService</code>.</p>
      </div>
    </details>

    <details>
      <summary>Comment fonctionne la purge automatique ?</summary>
      <div class="detail-body">
        <p>La purge automatique est un mécanisme qui supprime les données anciennes pour respecter le RGPD :</p>
        <ol>
          <li>La purge supprime les demandes <strong>clôturées</strong> (validées ou refusées) depuis plus longtemps que la <strong>durée de conservation</strong> configurée (24 mois par défaut).</li>
          <li>Elle supprime également les pièces jointes, les tokens et les logs d'alerte associés.</li>
          <li>Elle s'exécute <strong>automatiquement</strong> de façon périodique (via le <strong>lazy cron</strong> intégré — voir la section « Prérequis de déploiement » ci-dessous).</li>
          <li>Chaque purge est enregistrée dans le <strong>journal d'audit</strong>.</li>
        </ol>
        <p>Vous pouvez modifier la durée de conservation dans la page RGPD (<code>index.php?p=rgpd</code>).</p>
      </div>
    </details>

    <details>
      <summary>Que faire en cas de problème technique ?</summary>
      <div class="detail-body">
        <p>En cas de problème, voici les premiers réflexes :</p>
        <ol>
          <li><strong>Consultez le health check</strong> — La page <code>index.php?p=health</code> vérifie automatiquement l'état de l'application (base de données, configuration email, etc.).</li>
          <li><strong>Vérifiez votre connexion</strong> — Assurez-vous d'être bien sur le réseau DREETS pour les pages qui nécessitent une authentification.</li>
          <li><strong>Essayez un autre navigateur</strong> — Certains problèmes peuvent être liés au navigateur.</li>
          <li><strong>Contactez votre administrateur</strong> — Il a accès à la surveillance, aux logs et aux paramètres pour diagnostiquer le problème.</li>
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
        <p><strong>Oui.</strong> La page de validation (<code>index.php?p=validate</code>) est accessible sans authentification Windows. Le lien contenu dans l'email suffit pour valider ou refuser une étape. Vous n'avez pas besoin d'un compte sur le réseau DREETS.</p>
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
          <li>Accédez à <strong>index.php?p=admin_forms</strong>.</li>
          <li>Sélectionnez le formulaire concerné.</li>
          <li>Pour chaque étape, modifiez le champ <strong>Ordre</strong>.</li>
          <li>Un numéro plus petit = validation plus tôt dans le processus.</li>
          <li>Deux étapes avec le même numéro seront traitées <strong>en parallèle</strong>.</li>
        </ol>
        <div class="info-box">
          <p><strong>Attention :</strong> Modifier l'ordre n'affecte que les <em>nouvelles</em> demandes. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
        </div>
      </div>
    </details>

    <details>
      <summary><span aria-hidden="true">🖥️</span> Prérequis de déploiement et installation (pour l'équipe IT)</summary>
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
          <li>Déployez les fichiers dans le répertoire web du serveur IIS (ex : <code>C:\inetpub\wwwroot\workflow\</code>)</li>
          <li>Configurez l'authentification Windows dans IIS (Anonymous = Disabled, Windows Authentication = Enabled)</li>
          <li>Vérifiez les permissions du répertoire <code>db/</code> (accès en écriture pour IIS_IUSRS)</li>
          <li>Accédez à <code>install.php</code> dans votre navigateur pour générer le fichier <code>config.php</code> (SMTP, admin, URL de base) à partir d'un template — l'assistant vous guide étape par étape</li>
          <li>La base de données SQLite est créée automatiquement au premier accès — aucune opération manuelle nécessaire</li>
          <li>Accédez à <code>index.php?p=health</code> pour vérifier que tout fonctionne correctement</li>
        </ol>
        <h4>Tâches planifiées (lazy cron intégré depuis v4.2.0)</h4>
        <ul>
          <li><strong>Relance automatique</strong> — <code>remind.php</code> est exécuté automatiquement par le <strong>lazy cron</strong> intégré (une fois par heure, au premier accès PDO). Aucune tâche planifiée Windows à configurer.</li>
          <li><strong>Vérification des alertes J-N</strong> — <code>alert_check.php</code> est exécuté automatiquement par le lazy cron (une fois par jour, au premier accès PDO).</li>
          <li><strong>Purge RGPD</strong> — Déclenchée <strong>manuellement</strong> par un administrateur depuis la page <code>index.php?p=rgpd</code> (bouton « Purge automatique ») — supprime les demandes clôturées au-delà de la durée de conservation configurée (24 mois par défaut).</li>
        </ul>
        <div class="info-box">
          <p>Le mécanisme de <strong>lazy cron</strong> (introduit en v4.2.0) exécute les tâches planifiées au premier accès à la base de données, sans dépendre d'un Planificateur de tâches Windows externe. La table <code>lazy_cron</code> trace la dernière exécution de chaque tâche et un verrou atomique prévient les exécutions concurrentes.</p>
        </div>
        <h4>Sauvegardes</h4>
        <p>Le fichier <code>db/workflow.db</code> contient toutes les données. Sauvegardez-le régulièrement. La page <code>index.php?p=backup</code> permet aussi de télécharger une copie depuis l'interface.</p>
        <div class="tip-box">
          <p>Après le déploiement, accédez à <code>index.php?p=health</code> pour vérifier que tous les prérequis sont satisfaits (PHP 8+, SQLite accessible, répertoire inscriptible, SMTP configuré).</p>
        </div>
      </div>
    </details>

    <details>
      <summary>Comment configurer l'envoi d'emails ?</summary>
      <div class="detail-body">
        <p>Les paramètres d'envoi d'emails peuvent être configurés de deux manières :</p>
        <ol>
          <li><strong>Via l'interface</strong> — Accédez à <strong>index.php?p=admin_settings</strong> (réservé au super administrateur). Vous y trouverez les champs pour le serveur SMTP, le port, l'expéditeur, le nom et le délai de relance.</li>
          <li><strong>Via le fichier de configuration</strong> — Éditez le fichier <code>config.php</code> pour modifier les valeurs dans <code>SETTINGS_DEFAULTS</code> (smtp_host, smtp_port, etc.). Ces valeurs servent de fallback si le setting n'existe pas en base de données.</li>
        </ol>
        <p>Si l'interface index.php?p=admin_settings est disponible, préférez cette méthode : elle ne nécessite pas d'accès au serveur de fichiers.</p>
      </div>
    </details>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderRgpd(): string
    {
        $legal_mentions = '';
        try {
            $legal_mentions = \App\Core\App::settings()->get('legal_mentions', '');
        } catch (\Exception $e) {
            $legal_mentions = '';
            error_log('DocumentationService::renderRgpd legal_mentions error: ' . $e->getMessage());
        }

        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 8. RGPD ET MENTIONS LÉGALES                                -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="rgpd-legal">
    <h2>8. RGPD et mentions légales</h2>

    <div class="rgpd-box">
      <h3><span aria-hidden="true">📜</span> Mentions légales</h3>
      <?php if ($legal_mentions !== '' && $legal_mentions !== '0'): ?>
        <p><?= nl2br(\App\Core\App::html()->escape($legal_mentions)) ?></p>
      <?php else: ?>
        <p>Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d'un droit d'accès, de rectification et d'effacement de vos données. Contact : <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>. Durée de conservation : <?= (int) \App\Core\App::settings()->get('retention_months', '24') ?> mois après clôture.</p>
      <?php endif; ?>
    </div>

    <h3><span aria-hidden="true">🔒</span> Protection des données</h3>

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
    <p>
      <strong><span aria-hidden="true">♻️</span> Purge automatisée</strong> — Depuis la v10.0.9, la purge RGPD est <strong>automatisée</strong> : elle s'exécute sans intervention manuelle via le mécanisme de <strong>lazy cron</strong> intégré (une fois par jour, au premier accès à la base de données). Aucune tâche planifiée Windows n'est requise. Chaque exécution est tracée dans le journal d'audit (déclencheur <code>rgpd_purge</code>), garantissant la conformité à l'article 5.1.e du RGPD (limitation de la conservation).
    </p>

    <h4>Responsable de traitement</h4>
    <p>
      Le responsable de traitement est la DREETS (Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités).
      Pour toute question relative à la protection de vos données, contactez le <strong><?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?></strong> (Correspondant Informatique et Libertés).
    </p>

    <h4>Accessibilité (RGAA)</h4>
    <p>
      CircuitDémat s'efforce d'être conforme au <strong>Référentiel Général d'Amélioration de l'Accessibilité (RGAA 4.1)</strong>.
      La <strong>déclaration d'accessibilité</strong> détaille le niveau de conformité, les non-conformités connues et les voies de recours ; elle est disponible dans le fichier <code>docs/declaration-rgaa.md</code> livré avec l'application.
    </p>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }

    public function renderTechnique(): string
    {
        ob_start();
        ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 9. ARCHITECTURE TECHNIQUE                                   -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="technique">
    <h2>9. Architecture technique (pour l'équipe IT)</h2>
    <p class="u-c-muted-mb-1-af23e6">
      Cette section est destinée au personnel technique. Elle fournit un aperçu de l'architecture.
    </p>

    <details>
      <summary>Structure des fichiers</summary>
      <div class="detail-body">
        <div class="file-tree">
          <span class="dir"><span aria-hidden="true">📁</span> workflow/</span><br>
          &nbsp;&nbsp;<span class="file">config.php</span> — Constantes de configuration (BDD, SMTP, admin, SETTINGS_DEFAULTS)<br>
          &nbsp;&nbsp;<span class="file">helpers.php</span> — Fonctions utilitaires, moteur du circuit de validation, envoi d'emails, cache, sécurité<br>
          &nbsp;&nbsp;<span class="file">style.php</span> — CSS commun (design Marianne / RGAA, inclus via require_once)<br>
          &nbsp;&nbsp;<span class="file">install.php</span> — Assistant de génération de config.php<br>
          &nbsp;&nbsp;<span class="file">index.php</span> — Redirection vers le tableau de bord ou admin_access<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form</span> — Formulaire agent (affichage + envoi de demande)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form_preview</span> — Prévisualisation du formulaire<br>
          &nbsp;&nbsp;<span class="file">index.php?p=validate</span> — Page de validation/refus (accessible par token)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=dashboard</span> — Tableau de bord de supervision<br>
          &nbsp;&nbsp;<span class="file">index.php?p=my_submissions</span> — Mes demandes (agent)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=my_validations</span> — Mes validations (validateur)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=submission_view</span> — Vue détaillée d'une demande<br>
          &nbsp;&nbsp;<span class="file">index.php?p=form_tracking</span> — Tableau de suivi propriétaire (owners + admins)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_access</span> — Gestion des accès administrateur<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_forms</span> — Gestion des formulaires, étapes, destinataires<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_settings</span> — Configuration SMTP (super admin)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=admin_alerts</span> — Configuration des alertes J-N<br>
          &nbsp;&nbsp;<span class="file">index.php?p=stats</span> — Statistiques et tableaux de bord<br>
          &nbsp;&nbsp;<span class="file">index.php?p=monitoring</span> — Tableau de bord de surveillance<br>
          &nbsp;&nbsp;<span class="file">index.php?p=health</span> — Point de contrôle de santé (HTTP 200/503)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=rgpd</span> — Conformité RGPD (export, suppression, purge)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=backup</span> — Sauvegarde et restauration<br>
          &nbsp;&nbsp;<span class="file">index.php?p=download</span> — Téléchargement sécurisé des pièces jointes<br>
          &nbsp;&nbsp;<span class="file">index.php?p=confirm_action</span> — Confirmation d'actions sensibles<br>
          &nbsp;&nbsp;<span class="file">index.php?p=screenshot</span> — Sert les captures docs/screenshots/ (contourne IIS)<br>
          &nbsp;&nbsp;<span class="file">remind.php</span> — Script CLI de relance automatique (lazy cron 1×/heure)<br>
          &nbsp;&nbsp;<span class="file">alert_check.php</span> — Script CLI de vérification des alertes J-N (lazy cron 1×/jour)<br>
          &nbsp;&nbsp;<span class="file">index.php?p=docs</span> — Cette page de documentation<br>
          &nbsp;&nbsp;<span class="file">index.php?p=changelog</span> — Journal des versions (parse CHANGELOG.md)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> classes/</span> — DatabaseMigrations.php (migrations v0→v11 + seeding)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> PHPMailer/</span> — Librairie d'envoi d'emails<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> db/</span> — Base de données SQLite (workflow.db)<br>
          &nbsp;&nbsp;<span class="dir"><span aria-hidden="true">📁</span> cache/</span> — Cache file-based (LDAP suggestions, settings)
        </div>
      </div>
    </details>

    <details>
      <summary>Schéma de la base de données (simplifié)</summary>
      <div class="detail-body">
        <h3 class="u-mt-0-53896a">Table <code>forms</code></h3>
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
            <tr><td>settings</td><td>key (PK), value, updated_at, updated_by</td><td>Paramètres configurables (SMTP, délais…)</td></tr>
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
          <li>La fonction <code>get_auth_user()</code> transforme ce compte en adresse email (ex : <code>prenom.nom@<?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('email_domain', 'dreets.gouv.fr')) ?></code>).</li>
          <li>Les pages <code>index.php?p=form</code>, <code>index.php?p=dashboard</code>, <code>index.php?p=admin_forms</code> et <code>index.php?p=admin_access</code> nécessitent cette authentification.</li>
          <li>La page <code>index.php?p=validate</code> est accessible <strong>sans authentification</strong> (les validateurs externes n'ont pas forcément de compte DREETS).</li>
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
            <tr><td>Langage</td><td>PHP 8.4+ (procédural, sans framework)</td></tr>
            <tr><td>Base de données</td><td>SQLite (fichier db/workflow.db, mode WAL)</td></tr>
            <tr><td>Envoi d'emails</td><td>PHPMailer via SMTP (pas d'auth SMTP)</td></tr>
            <tr><td>Relance automatique</td><td>Lazy cron (remind.php, 1×/heure) — pas de Planificateur Windows requis</td></tr>
            <tr><td>Vérification des alertes</td><td>Lazy cron (alert_check.php, 1×/jour)</td></tr>
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
          <li><strong>Agent</strong> accède à <code>index.php?p=form&f=onboarding</code> et remplit le formulaire.</li>
          <li>Les données sont enregistrées dans <code>submissions</code> (champ <code>data</code> en JSON).</li>
          <li><code>advance_workflow()</code> est appelée : elle crée les tokens pour l'étape d'ordre 1 et envoie les emails.</li>
          <li><strong>Validateur</strong> clique sur le lien dans l'email → arrive sur <code>index.php?p=validate&token=…</code></li>
          <li>Il valide ou refuse. <code>validate_token()</code> met à jour <code>done_at</code> et rappelle <code>advance_workflow()</code>.</li>
          <li>Si validé : les tokens de l'étape suivante sont créés et les emails envoyés.</li>
          <li>Si refusé : le statut de la demande passe à <code>refuse</code> et <code>closed_at</code> est renseigné.</li>
          <li>Quand toutes les étapes sont validées : <code>closed_at</code> est renseigné → la demande est clôturée.</li>
          <li>En parallèle, <code>remind.php</code> tourne une fois par heure (lazy cron) et envoie des relances aux validateurs en attente depuis plus de 48h.</li>
          <li>En parallèle, <code>alert_check.php</code> tourne une fois par jour (lazy cron) et envoie les alertes J-N configurées.</li>
        </ol>
      </div>
    </details>
  </div>
        <?php
        return rtrim((string) ob_get_clean(), " \t");
    }
}
