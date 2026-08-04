<?php
$donut = \App\Core\App::html()->renderDonutChart($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
?>
<!-- Graphique de répartition des statuts -->
<div class="card">
  <h2><span aria-hidden="true">📊</span> Répartition des soumissions</h2>
  <?= $donut ?>
</div>
