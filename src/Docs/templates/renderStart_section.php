
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
        
