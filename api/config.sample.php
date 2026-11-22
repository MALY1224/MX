<?php
/* =========================================================================
   MODÈLE DE CONFIGURATION — à copier vers config.php puis à compléter.
   (Voir DEPLOIEMENT.md, étape 2.)
   ========================================================================= */

return [

    /* Type de base de données : 'mysql' (recommandé, inclus chez quasi tous
       les hébergeurs) ou 'sqlite' (fichier local, pratique pour tester). */
    'driver' => 'mysql',

    /* --- Accès MySQL : fournis par votre hébergeur (cPanel, hPanel, etc.) --- */
    'mysql' => [
        'host'     => 'localhost',   // souvent "localhost" chez l'hébergeur
        'port'     => 3306,
        'database' => 'mon_site',    // nom de la base créée dans votre hébergeur
        'user'     => 'mon_utilisateur',
        'password' => 'mon_mot_de_passe',
        'charset'  => 'utf8mb4'
    ],

    /* --- Option SQLite (uniquement si driver = 'sqlite') --- */
    'sqlite' => [
        'path' => __DIR__ . '/data/maxin.sqlite'
    ]

];
