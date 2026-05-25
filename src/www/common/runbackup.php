<?php

require_once __DIR__ . '/backupdb.php';
require_once __DIR__ . '/database.php';

$GLOBALS["DB"] = new Database();
try {
    $nodes = $GLOBALS["DB"]->query(
            "SELECT * FROM backup_nodes", []);
    if ($nodes == null) {
        exit;
    }


    $backup_time = time();
    $cfg = require __DIR__ . '/../common/config.php';

    $backup_dir = $cfg['DIR_BACKUPS'] . '/tmp';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0700, true);
    }

    $backup_file = $backup_dir . '/' . date("YmdHis", $backup_time) . '.7z';
    if (!createBackup($backup_file, $backup_time)) {
        exit;
    }

    $table = $GLOBALS['DB']->query('SELECT max(idx) as idx FROM transactions', []);
    $cfg_db = loadconfig();

    for ($i = 0; $i < count($nodes); $i++) {
        try {
            $err = sendBackup(
                    $nodes[$i]['url'], // URL backup nodu
                    $nodes[$i]['ed_pub'], // ed_pub backup nodu v base64url (prázdné = první připojení)
                    $nodes[$i]['pswd'] ?? "", // backup_token
                    $backup_file, // soubor k odeslání
                    $nodes[$i]['idx']
            );
            $GLOBALS["DB"]->query(
                    "UPDATE backup_nodes SET last_result=?, last_backup=now() where idx = ?", [$err, $nodes[$i]['idx']]);

            if ($err === '') {
                writeconfig('backup_idxs', ($cfg_db['settle_index'] . ',' . $table[0]['idx']));
                echo "✓ Záloha úspěšná\n";
            } else {
                echo "✗ Chyba: $err\n";
            }
        } catch (Exception $e) {
            addtolog('EXCEPTION: sendBackup ', $e);
        }
    }

    unlink($backup_file);
} catch (Exception $e) {
    addtolog('EXCEPTION: runbackup ', $e);
}