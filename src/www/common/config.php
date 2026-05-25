<?php
if (($_ENV['BITCOLI_MYSQL_HOST'] ?? null) !== null) {
return [
'MYSQL_HOST' => $_ENV['BITCOLI_MYSQL_HOST'],
'MYSQL_DBNAME' => $_ENV['BITCOLI_MYSQL_DATABASE'],//'bitcoliv2',
'MYSQL_USER' => $_ENV['BITCOLI_MYSQL_USER'],
'MYSQL_PASSWORD' => $_ENV['BITCOLI_MYSQL_PSWD'],
'DIR_KEYS' => $_ENV['BITCOLI_KEYS_DIR'],
'DIR_TOR' => $_ENV['BITCOLI_TOR_DIR'] ?? "",
'DIR_BACKUPS'=> $_ENV['BITCOLI_BACKUPS_DIR'],
'TOR_HOST' => $_ENV['BITCOLI_TOR_HOST'],
'TOR_PROXY' => $_ENV['BITCOLI_TOR_PROXY'],
'LND_HOST' => $_ENV['LND_HOST'],
'LND_MACAROON' => $_ENV['LND_MACAROON'],
];
} else {
return [
'MYSQL_HOST' => throw new RuntimeException('MYSQL_HOST is not set. Set BITCOLI_MYSQL_HOST env variable or hardcode the host address in config.php (e.g.localhost).'),
'MYSQL_DBNAME' => 'bitcoliv2',
'MYSQL_USER' => 'root',
'MYSQL_PASSWORD' => 'secret',
'DIR_KEYS' => '/data/keys',
'DIR_TOR' => '/data/tor',
'DIR_BACKUPS'=> '/data/backups',
'TOR_PROXY' => '127.0.0.1:9050',
'TOR_HOST' => throw new RuntimeException('TOR_HOST is not set. Set BITCOLI_TOR_HOST env variable or hardcode the .onion address in config.php.'),
'LND_HOST' => throw new RuntimeException('LND_HOST is not set. Set LND_HOST env variable or hardcode the host:port in config.php (e.g. 192.168.1.10:8080).'),
//'LND_HOST' => '192.168.1.72:8080',
'LND_MACAROON' => '/lnd/admin.macaroon',
];
}