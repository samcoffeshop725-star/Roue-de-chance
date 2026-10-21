<?php
/*
 * ─────────────────────────────────────────────────────────────
 *  Roue de la Chance — configuration
 *  Ce fichier est le seul que vous pourriez avoir à modifier.
 * ─────────────────────────────────────────────────────────────
 */
return [

    // Accès à l'administration (/admin) : votre numéro de téléphone et votre mot de passe de départ.
    // Ils créent votre accès à la première ouverture du site. Ensuite, changez-les quand vous voulez
    // dans l'administration (Paramètres → Accès administrateur) : ces deux lignes ne servent plus.
    'admin_phone' => '',
    'admin_password' => '',

    // Clé demandée par l'écran d'installation : pour réinitialiser l'accès administrateur (voir 'reset_admin'
    // plus bas), ou pour le créer si les deux lignes ci-dessus sont vides.
    'install_key' => '',

    // Base de données centrale partagée par tous les projets.
    //  - 'sqlite' : aucun réglage, un simple fichier dans le dossier data/ (recommandé).
    //  - 'mysql'  : à utiliser si votre hébergeur déconseille SQLite.
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/data/roue-03ebc2dc0ca5331d.sqlite',
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'pass' => '',
        ],
    ],

    // Si votre site passe par Cloudflare ou un autre proxy, indiquez l'en-tête qui
    // contient l'adresse IP réelle des visiteurs, par exemple 'HTTP_CF_CONNECTING_IP'.
    'client_ip_header' => '',

    // Mot de passe administrateur oublié ? Passez cette valeur à true, ouvrez /admin : un écran demande
    // la clé ci-dessus et un nouveau mot de passe (une seule fois). Remettez ensuite false.
    'reset_admin' => false,

    // Afficher le détail des erreurs (laisser à false une fois le site en ligne).
    'debug' => false,
];
