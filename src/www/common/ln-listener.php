<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/myfc.php';
require_once __DIR__ . '/constants.php';
/*
 * tento soubor zajišťuje nepřetržitou kontrolu příchozích transakcí a 
 * kontrolu odchozích transakci, které jsou ve stavu IN_FLIGHT 
 * 
 * 
 * 
  Vytvoř službu:

  sudo nano /etc/systemd/system/lnlistener.service

  Obsah:

  [Unit]
  Description=Lightning PHP Listener
  After=network.target

  [Service]
  User=webX
  WorkingDirectory=/var/www/clients/clientX/webY/web
  ExecStart=/usr/bin/php ln-listener.php
  Restart=always
  RestartSec=3

  [Install]
  WantedBy=multi-user.target

  Pak:

  sudo systemctl daemon-reload
  sudo systemctl enable lnlistener
  sudo systemctl start lnlistener

  Kontrola:

  sudo systemctl status lnlistener

 */

function subscribe() {
    global $macaroonHex;
    global $cfg_db;
    global $cfg_file;
    $url = sprintf(
            'https://%s/v1/invoices/subscribe?settle_index=%s',
            $cfg_file['LND_HOST'],
            $cfg_db['settle_index']
    );

    //echo $url;

    $headers = [
        'Grpc-Metadata-macaroon:' . $macaroonHex,
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$buffer) {

            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    processInvoiceJson($line);
                } catch (Exception $e) {
                    print_r($e);
                    addtolog('EXCEPTION: requestSubscribeinv ', $e);
                }
            }

            return strlen($data);
        }
    ]);

    curl_exec($ch);
    curl_close($ch);
}

/**
 * Zpracování jedné invoice zprávy
 */
function processInvoiceJson($jsonString) {
    $json = json_decode($jsonString);

    if (!$json || !isset($json->result)) {
        return;
    }

    if ($json->result->state !== 'SETTLED') {
        return;
    }

    $result = $json->result;

    $r_hash = '+' . base64_to_base64url($result->r_hash);
    $amt_paid_msat = (int) $result->amt_paid_msat;
    $invoice = $result->payment_request;

    //echo $r_hash;
    //echo "r_hash = $r_hash invoice=$invoice<br>";

    $table = $GLOBALS['DB']->query(
            'SELECT a.user_idx, UNIX_TIMESTAMP(a.created) as created, a.settled, 
                a.requested_msat, a.paid_msat, b.pub_key 
         FROM transactions a, users b
         WHERE a.payment_hash = ? 
           AND a.invoice = ? 
           AND a.user_idx = b.idx',
            [$r_hash, $invoice]
    );

    if ($table) {
        addtolog("table OK $r_hash", $invoice);
    }

    if ($table && isCorrectPubKey($table[0]['pub_key'] ?? "")) {

        //echo "nasel $r_hash";
        addtolog("nasel: $r_hash", $invoice);

        $created = $table[0]['created'];
        $settled = time();

        $sign = signInvoice(
                $table[0]['pub_key'],
                $created,
                $settled,
                $amt_paid_msat,
                0,
                $invoice
        );

        $settleIDX = inc_settle_idx($table[0]['user_idx']);

        $GLOBALS['DB']->query(
                'UPDATE transactions 
             SET settled = ?, settled_lnd = ?, paid_msat = ?, 
                 payment_status = 2, settle_idx = ?, sign_node = ?
             WHERE payment_hash = ?',
                [
                    unixToSQLdatetime($settled),
                    unixToSQLdatetime($result->settle_date),
                    $amt_paid_msat,
                    $settleIDX,
                    $sign,
                    $r_hash
                ]
        );
    }

    // settle_index a add_index se aktualizují vždy — i pokud faktura nepatří
    // tomuto nodu. Tím se zaručí, že se při příštím subscribe nepřehrají
    // už zpracované faktury z LND streamu.
    writeconfig('settle_index', $result->settle_index);
    writeconfig('add_index', $result->add_index);
}

function update_terms_hash() {
    $table = $GLOBALS['DB']->query('SELECT level, max_balance_sat, fee_ppm, terms_hash FROM user_group ', []);

    foreach ($table as $row) {
        //max_balance_sat, fee_ppm, terms_hash
        $terms = get_terms($row['max_balance_sat'], $row['fee_ppm']);
        $terms_hash_calc = b64e(hash('sha256', $terms, true));
        if ($row['terms_hash'] != $terms_hash_calc) {
            $GLOBALS['DB']->query('UPDATE user_group SET terms_hash = ? WHERE level = ?  ', [$terms_hash_calc, $row['level']]);
        }


        // práce s daty
    }
}

function check_payment_in_flight() {
    //t.idx, t.user_idx, t.payment_hash, t.paid_msat, UNIX_TIMESTAMP(t.created) as created, t.paid_msat, t.invoice, u.pub_key
    $table = $GLOBALS['DB']->query('SELECT t.idx, t.user_idx, t.payment_hash, t.paid_msat, UNIX_TIMESTAMP(t.created) as created, t.paid_msat, t.invoice, u.pub_key FROM transactions t ' .
            'LEFT JOIN users u ON u.idx= t.user_idx ' .
            'Where t.payment_status = 1', []);
    foreach ($table as $row) {
        $payment_hash = $row['payment_hash'];
        if (substr($payment_hash, 0, 1) == '-') {
            $payment_hash = substr($payment_hash, 1);
            $lnd = new lnd();
            initlnd($lnd);
            $lnd_check = $lnd->request('v2/router/track/' . $payment_hash . "=");
            addtolog('v2/router/track/' . $payment_hash . "=", json_encode($lnd_check));
            $paymentOK = null;
            $fee_msat = 0;
            $payment_preimage = null;
            switch ($lnd_check->result->status ?? "") {
                case "SUCCEEDED":
                    $paymentOK = true;
                    $fee_msat = $lnd_check->result->fee_msat ?? 0;
                    $payment_preimage = base64url_encode(hex2bin($lnd_check->result->payment_preimage ?? ""));
                    break;

                case "FAILED":
                    $paymentOK = false;
                    break;
            }
            if ($paymentOK !== null) {
                $settled = time();
                $node_sign = null;
                $payment_status = null;
                if ($paymentOK) {
                    $payment_status = 2;
                    $node_sign = signInvoice($row['pub_key'], $row['created'], $settled, $row['paid_msat'], $fee_msat, $row['invoice']);
                }
                $cl_idx = $row['user_idx'];
                $idx = $row['idx'];

                addtolog("UPDATE transactions", "idx=" . $idx);
                $GLOBALS["DB"]->query("UPDATE transactions SET settled = ?, payment_status = ?, payment_preimage = ?, fee_msat = ?, settle_idx = ?, sign_node = ? Where idx = ?",
                        [unixToSQLdatetime($settled), $payment_status, $payment_preimage, $fee_msat, inc_settle_idx($cl_idx), $node_sign, $idx]);
            }
        }
    }
}

function canRunning() {
  global $cfg_db;
  return ((($cfg_db['maintenance'] ?? "0") == '0') && ((int)($cfg_db['db_version'] ?? "0") == DB_VERSION));
}

$DBOK = false;
try {
    $GLOBALS["DB"] = new Database();
    $DBOK = true;
} catch (Exception $exc) {
    
}


if (!$DBOK) {
    sleep(5);
    try {
        $GLOBALS["DB"] = new Database();
    } catch (Exception $exc) {
        http_response_code(500);
        die('database connection unavailable');
    }
}

writeconfig('app_started_at', time());



global $cfg_file;
global $cfg_db;
global $macaroonHex;
$cfg_db = loadconfig();

if ((int)($cfg_db['db_version'] ?? "0") < DB_VERSION) {
    writeconfig('db_upgrading', '1');
    // ... Tady bude aktualizace z jednotlivých verzi
    writeconfig('db_version',   (string) DB_VERSION);
    writeconfig('db_upgrading', '0');
    $cfg_db = loadconfig();
}


$cfg_file = require __DIR__ . '/config.php';
$macaroonHex = strtoupper(bin2hex(file_get_contents($cfg_file['LND_MACAROON'])));
/* $cfg_db = loadconfig();
  subscribe(); */
$last_backup = time() - 300;
$last_heartbeat = 0;   // vynutí okamžitý první heartbeat

//J:smažu při startu všechny starý soubory
$dir = $cfg_file['DIR_KEYS'] . '/tmp';
foreach (glob($dir . '/*.7za') ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}


while (true) {
    $cfg_db = loadconfig();
    while (!canRunning()) {
        $cfg_db = loadconfig();
        sleep(10);
    }
    
   $cfg_db = loadconfig();
   if (canRunning()) {
    try {
        update_terms_hash();
    } catch (Exception $e) {
        print_r($e);
        sleep(2);
    }}

   $cfg_db = loadconfig();
   if (canRunning()) {
    try {
        check_payment_in_flight();
    } catch (Exception $e) {
        print_r($e);
        sleep(2);
    }
}
$cfg_db = loadconfig();
   if (canRunning()) {
    try {
        subscribe();
    } catch (Exception $e) {
        print_r($e);
        sleep(2);
    }}

$cfg_db = loadconfig();
       if (canRunning()) {
    try {
        $table = $GLOBALS['DB']->query('SELECT max(idx) as idx FROM transactions', []);

        if ((((($cfg_db['backup_idxs'] ?? "") != ($cfg_db['settle_index'] . ',' . $table[0]['idx'])) &&
                ($last_backup + 300 < time())) || (($cfg_db['backup_idxs'] ?? "") == 'backupnow')) 
                //nebo alespoň cca. jednou denně
                || ($last_backup + (60*60*20 + (random_int(0,60*60*8))) < time())) {
            if (($cfg_db['backup_idxs'] ?? "") == 'backupnow') {
                writeconfig('backup_idxs', 'backuprunning');
            }
            $last_backup = time();
            exec(PHP_BINARY . ' ' . __DIR__ . '/runbackup.php > /dev/null 2>&1 &');
        }
    } catch (Exception $e) {
        print_r($e);
        sleep(2);
    }}

// Heartbeat — posílá se nezávisle na maintenance/canRunning,
// aby proxy server vždy znal aktuální Tor adresu nodu.
// Spouští se na pozadí aby neblokoval hlavní smyčku.
if ($last_heartbeat + PROXY_HEARTBEAT_INTERVAL < time()) {
    $last_heartbeat = time();
    $tor = $cfg_file['TOR_HOST'];
    if ($tor !== '') {
        exec(PHP_BINARY . ' ' . __DIR__ . '/runheartbeat.php > /dev/null 2>&1 &');
    }
}

}