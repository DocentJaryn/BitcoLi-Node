<?php
define('BACKUP_PLACEHOLDERS', [
    '#defaultbackup#'  => 'https://bitcoli.com/bv2/test/api',
    '#node_alfa#'  => 'http://4f2h3r2iruaafw2pi6vzrxvff3cskt7h7cfxjpzquccwb3zk5zaxvtid.onion'
]);

define('TABLES_TO_BACKUP', [
    'users',
    'transactions',
    'config',
    'user_group',
    'terms_history',
    'invitations',
    'backup_nodes'
]);

// Aktuální verze schématu DB požadovaná touto verzí aplikace.
// Pøi startu aplikace se porovná s hodnotou db_version v tabulce config.
// Pokud se liší, spustí se migrace (db_upgrading=1) a db_version se aktualizuje.
define('DB_VERSION', 1);

// URL proxy serveru pro heartbeat registraci
define('PROXY_HEARTBEAT_URL', 'https://bitcoli.com/bv2/api/heartbeat/');
 
// Jak èasto posílat heartbeat (sekundy)
define('PROXY_HEARTBEAT_INTERVAL', 270);  // 4.5 minuty — worker mìøí každých 5 minut