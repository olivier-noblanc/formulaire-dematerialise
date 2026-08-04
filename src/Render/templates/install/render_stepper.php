<?php
/** @var int $step */
?>
    <div class="stepper">
        <div class="step-item <?= $step > 1 ? 'step-done' : 'step-active' ?>">
            <div class="step-icon"><?= $step > 1 ? '✓' : '1' ?></div>
            <div class="step-label">Prérequis</div>
        </div>
        <div class="step-item <?= $step >= 2 ? ($step > 2 ? 'step-done' : 'step-active') : 'step-upcoming' ?>">
            <div class="step-icon"><?= $step > 2 ? '✓' : '2' ?></div>
            <div class="step-label">Configuration</div>
        </div>
        <div class="step-item <?= $step >= 3 ? 'step-active' : 'step-upcoming' ?>">
            <div class="step-icon">3</div>
            <div class="step-label">Installation</div>
        </div>
    </div>
