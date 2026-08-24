<?php

/**
 * CSS spécifique à la page sauvegarde.
 * @var string $css Output variable (used as reference)
 */
declare(strict_types=1);

$css = <<<'CSS'
                .container { max-width: 900px; }

                /* Blocs d'information dans les cartes */
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: .5rem 0;
                    border-bottom: 1px solid #eee;
                    font-size: .9rem;
                }
                .info-row:last-child { border-bottom: none; }
                .info-label { color: #555; font-weight: normal; }
                .info-value { font-weight: bold; color: #1e1e1e; }

                /* Zone de drop / upload */
                .upload-zone {
                    border: 2px dashed var(--c-border);
                    border-radius: var(--r-md);
                    padding: 2rem;
                    text-align: center;
                    margin-bottom: 1rem;
                    background: var(--c-bg-warm);
                }
                .upload-zone p { margin-bottom: .75rem; color: #666; font-size: .9rem; }
                .upload-zone input[type="file"] { font-size: .9rem; }

                /* Purge recap table */
                .purge-recap {
                    background: #fff3e0;
                    border: 1px solid #b45309;
                    border-radius: 4px;
                    padding: 1.25rem;
                    margin-bottom: 1rem;
                }
                .purge-recap h3 { color: #b45309; margin-bottom: .75rem; font-size: 1rem; }
                .purge-recap .purge-counts { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1rem; }
                .purge-recap .purge-count { text-align: center; min-width: 100px; }
                .purge-recap .purge-count strong { display: block; font-size: 1.6rem; color: #b45309; }
                .purge-recap .purge-count span { font-size: .8rem; color: #666; }

                /* Section danger */
                .danger-zone {
                    border: 2px solid #c0392b;
                    border-radius: 4px;
                    padding: 1.5rem;
                    margin-bottom: 1.5rem;
                    background: #fff8f8;
                }
                .danger-zone h2 {
                    color: #c0392b;
                    border-bottom-color: #c0392b;
                }

                /* Stat table spécifique */
                .stat-table { width: 100%; font-size: .85rem; }
                .stat-table td { padding: .35rem .75rem; }
                .stat-table tr:nth-child(even) td { background: #f7f7fb; }
                .stat-table tr:hover td { background: #f0f0f8; }
    CSS;
