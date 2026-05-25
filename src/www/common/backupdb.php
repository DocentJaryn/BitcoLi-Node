<?php
/**
 * BitcoLi Backup Client
 * Odešle zálohu na vzdálený BitcoLi node.
 *
 * Použití:
 *   $err = sendBackup(
 *       'http://your-backup-node.onion',
 *       'base64url_ed_pub_backup_nodu',
 *       'backup_pswd',
 *       '/cesta/k/souboru.bin'
 *   );
 *   if ($err !== '') { echo "Chyba: $err"; }
 */

require_once __DIR__ . '/myfc.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';
$cfg = require __DIR__ . '/config.php';

/**
 * Interní helper — HTTP POST s JSON tělem a logováním.
 */
function backup_http_post(string $url, array $payload, string $backup_file): ?array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    global $cfg;
    addtolog("backup :" . $backup_file, "POST $url " . substr($json, 0, 200).' TOR_PROXY:'.$cfg['TOR_PROXY']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        // Pro Tor .onion adresy odkomentuj:
        CURLOPT_PROXY     => $cfg['TOR_PROXY'],
//         CURLOPT_PROXY     => 'socks5h://127.0.0.1:9050',
         CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err) {
        addtolog("backup :" . $backup_file, "cURL chyba: $curl_err");
        return null;
    }

    addtolog("backup :" . $backup_file, "HTTP $http_code odpověď: " . substr($response, 0, 500));

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        addtolog("backup :" . $backup_file, "Chyba parsování JSON: $response");
        return null;
    }

    return $decoded;
}


function createBackup(string $backup_file, $backup_time): bool {
    $cfg = require __DIR__ . '/config.php';

    $filename = date("YmdHis", $backup_time) . '_' . $cfg['MYSQL_DBNAME'];
    $tables   = implode(' ', TABLES_TO_BACKUP);

/*    $seed = getSeedBin();
    if (!$seed) {
        $err = "Nepodařilo se načíst seed (seedFile není nastaven nebo soubor neexistuje)";
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $backup_encryption_key = hash_hkdf(
        'sha256',
        $seed,
        32,
        'bitcoli:backup-file:' . date("YmdHis", $backup_time)  // váže klíč ke konkrétnímu backup nodu
    );



    $zip_pswd = b64e($backup_encryption_key);*/

$zip_pswd = 'tajneheslo';
//    print_r('$zip_pswd='.$zip_pswd.'<br>');

    $command = "mysqldump --opt --skip-ssl"
        . " -h" . escapeshellarg($cfg['MYSQL_HOST'])
        . " -u" . escapeshellarg($cfg['MYSQL_USER'])
        . " -p" . escapeshellarg($cfg['MYSQL_PASSWORD'])
        . " "   . escapeshellarg($cfg['MYSQL_DBNAME'])
        . " "   . $tables
        . " | 7za a -si" . "backup.sql"
        . " -p\"" . $zip_pswd . "\""
        . " "    . $backup_file;

/*  $command = "mysqldump --opt -h".$cfg['MYSQL_HOST']
    ." -u".$cfg['MYSQL_USER']
    ." -p".$cfg['MYSQL_PASSWORD'] 
    ." ".$cfg['MYSQL_DBNAME']
    ." ".$tables
    ." | 7za a -si".$backup_file.".sql -p".$zip_pswd." " .$backup_file;*/

    exec($command, $output, $exit_code);
//    system($command, $exit_code);

//  $backup_file = date("YmdHis").'_'. $cfg['MYSQL_DBNAME'];
//  $command = "mysqldump --opt -h".$cfg['MYSQL_HOST']." -u".$cfg['MYSQL_USER']." -p".$cfg['MYSQL_PASSWORD'] ." ".$cfg['MYSQL_DBNAME']." ".$tables." | 7za a -si".$backup_file.".sql -spf -p".$zip_pswd." '" .$backup_file."'";
//system($command);
    return $exit_code === 0;
}


/**
 * Provede hello handshake s backup nodem, ověří jeho Ed25519 podpis a derivuje
 * session klíče pro následující šifrovanou komunikaci.
 *
 * Pokud $backup_node_edpub není zadán (prázdný řetězec), klíč se přijme ze
 * odpovědi serveru (TOFU — trust on first use) a automaticky uloží do tabulky
 * backup_nodes (podle $backup_node_idx), aby příští připojení mohlo klíč ověřit.
 *
 * @param string $backup_node_url    URL backup nodu (bez lomítka na konci)
 * @param string $backup_node_edpub  Známý Ed25519 pub klíč serveru v base64url,
 *                                   nebo '' při prvním připojení
 * @param int    $backup_node_idx    Primární klíč řádku v tabulce backup_nodes
 *                                   (0 = neukládej, používá getBackupList bez DB)
 * @param string $log_tag            Řetězec pro addtolog (typicky cesta k souboru)
 *
 * @return array{
 *   sid: string,
 *   encrypt_key: string,
 *   decrypt_key: string,
 *   server_ed_pub_bin: string
 * }|string  Asociativní pole při úspěchu, popis chyby při selhání.
 */
function backup_handshake(
    string $backup_node_url,
    string $backup_node_edpub,
    int    $backup_node_idx,
    string $log_tag
): array|string {

    // ── Načti vlastní klíče ───────────────────────────────────────────────────

    $my_ed_priv = getPrivateKey();
    $my_ed_pub  = getPublicKey();

    if (!$my_ed_priv || !$my_ed_pub) {
        $err = "Nepodařilo se načíst vlastní klíče (privKeyFile/pubKeyFile)";
        addtolog("backup :" . $log_tag, $err);
        return $err;
    }

    // ── Krok 1: Hello ────────────────────────────────────────────────────────

    $x25519_kp = sodium_crypto_box_keypair();
    $my_x_pub  = sodium_crypto_box_publickey($x25519_kp);
    $my_x_priv = sodium_crypto_box_secretkey($x25519_kp);

    $hello_payload = [
        'nonce'  => b64e(random_bytes(16)),
        'time'   => time(),
        'ed_pub' => b64e($my_ed_pub),
        'x_pub'  => b64e($my_x_pub),
    ];
    $hello_payload['sign'] = b64e(
        sodium_crypto_sign_detached(
            json_encode($hello_payload, JSON_UNESCAPED_SLASHES),
            $my_ed_priv
        )
    );

    addtolog("backup :" . $log_tag, "Hello: ed_pub=" . b64e($my_ed_pub));

    $hello_res = backup_http_post($backup_node_url . '/hello', $hello_payload, $log_tag);

    if (!$hello_res) {
        $err = "Žádná odpověď na hello od $backup_node_url";
        addtolog("backup :" . $log_tag, $err);
        return $err;
    }

    if (($hello_res['code'] ?? -1) !== 0) {
        $err = "Hello selhalo: " . ($hello_res['message'] ?? json_encode($hello_res));
        addtolog("backup :" . $log_tag, $err);
        return $err;
    }

    addtolog("backup :" . $log_tag, "Hello OK, sid=" . ($hello_res['sid'] ?? '?'));

    // ── Krok 2: Ověř podpis odpovědi serveru ─────────────────────────────────

    $server_sign            = b64d($hello_res['sign'] ?? '');
    $hello_res_without_sign = $hello_res;
    unset($hello_res_without_sign['sign']);
    $hello_res_json = json_encode($hello_res_without_sign, JSON_UNESCAPED_SLASHES);

    $server_ed_pub_bin = null;

    if ($backup_node_edpub !== '') {
        // Ověř proti známému klíči
        $expected_bin = b64d($backup_node_edpub);
        if (!sodium_crypto_sign_verify_detached($server_sign, $hello_res_json, $expected_bin)) {
            $err = "Neplatný podpis serveru — klíč serveru neodpovídá backup_node_edpub!";
            addtolog("backup :" . $log_tag, $err);
            return $err;
        }
        $server_ed_pub_bin = $expected_bin;
        addtolog("backup :" . $log_tag, "Podpis serveru ověřen proti backup_node_edpub");
    } else {
        // TOFU: klíč není znám — přijmi ze odpovědi a uložím do DB
        $from_res_bin = b64d($hello_res['ed_pub'] ?? '');
        if (!sodium_crypto_sign_verify_detached($server_sign, $hello_res_json, $from_res_bin)) {
            $err = "Neplatný podpis serveru v hello odpovědi";
            addtolog("backup :" . $log_tag, $err);
            return $err;
        }
        $server_ed_pub_bin  = $from_res_bin;
        $server_ed_pub_b64  = $hello_res['ed_pub'] ?? '';
        addtolog("backup :" . $log_tag,
            "TOFU: backup_node_edpub nebyl zadán, přijímám klíč ze odpovědi: $server_ed_pub_b64"
        );

        // Uložit klíč do DB, aby příští připojení mohlo ověřovat
        if ($backup_node_idx > 0) {
            $GLOBALS["DB"]->query(
                "UPDATE backup_nodes SET ed_pub = ? WHERE idx = ? AND (ed_pub = '' OR ed_pub IS NULL)",
                [$server_ed_pub_b64, $backup_node_idx]
            );
            addtolog("backup :" . $log_tag,
                "TOFU: ed_pub uložen do backup_nodes.idx=$backup_node_idx"
            );
        }
    }

    // ── Krok 3: Derivuj session klíče ────────────────────────────────────────

    $server_x_pub = b64d($hello_res['x_pub'] ?? '');
    $shared = sodium_crypto_scalarmult($my_x_priv, $server_x_pub);

    if ($shared === false) {
        $err = "Nepodařilo se vypočítat sdílený tajný klíč (x_pub serveru je neplatný)";
        addtolog("backup :" . $log_tag, $err);
        return $err;
    }

    $encrypt_key = hash_hkdf('sha256', $shared, 32, 'x25519-session-key:client->server');
    $decrypt_key = hash_hkdf('sha256', $shared, 32, 'x25519-session-key:server->client');

    $decrypted = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
        b64d($hello_res['ciphertext'] ?? ''),
        '',
        b64d($hello_res['nonce'] ?? ''),
        $decrypt_key
    );

    if ($decrypted === false) {
        $err = "Nepodařilo se dešifrovat hello ciphertext (špatný klíč nebo poškozená data)";
        addtolog("backup :" . $log_tag, $err);
        return $err;
    }

    $sid = json_decode($decrypted, true)['sid'] ?? '';
    addtolog("backup :" . $log_tag, "Session klíče derivovány, SID=$sid");

    return [
        'sid'               => $sid,
        'encrypt_key'       => $encrypt_key,
        'decrypt_key'       => $decrypt_key,
        'server_ed_pub_bin' => $server_ed_pub_bin,
    ];
}


/**
 * Odešle zálohu souboru na vzdálený BitcoLi node.
 *
 * @param string $backup_node_url   URL backup nodu, např. 'http://xyz.onion' (bez lomítka na konci)
 * @param string $backup_node_edpub Ed25519 veřejný klíč backup nodu v base64url
 *                                  (prázdný řetězec = TOFU, klíč se přijme a uloží)
 * @param string $backup_pswd       Sdílené tajemství (backup_pswd z DB config backup nodu)
 * @param string $backup_file       Absolutní cesta k souboru který se zálohuje
 * @param int    $backup_node_idx   Primární klíč řádku v tabulce backup_nodes
 *
 * @return string Prázdný řetězec při úspěchu, popis chyby při selhání
 */
function sendBackup(
    string $backup_node_url,
    string $backup_node_edpub,
    string $backup_pswd,
    string $backup_file,
    int    $backup_node_idx = 0
): string {

    $backup_node_url = BACKUP_PLACEHOLDERS[$backup_node_url] ?? $backup_node_url;
    addtolog("backup :" . $backup_file, "Zahajuji zálohu na $backup_node_url");

    // ── Handshake ─────────────────────────────────────────────────────────────

    $hs = backup_handshake($backup_node_url, $backup_node_edpub, $backup_node_idx, $backup_file);
    if (is_string($hs)) return $hs;  // chybová zpráva

    $sid               = $hs['sid'];
    $encrypt_key       = $hs['encrypt_key'];
    $decrypt_key       = $hs['decrypt_key'];
    $server_ed_pub_bin = $hs['server_ed_pub_bin'];

    // ── Krok 4: Načti soubor ─────────────────────────────────────────────────

    if (!file_exists($backup_file)) {
        $err = "Soubor neexistuje: $backup_file";
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $plaintext = file_get_contents($backup_file);
    if ($plaintext === false) {
        $err = "Nepodařilo se načíst soubor: $backup_file";
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $backup_dt = time();
    addtolog("backup :" . $backup_file,
        "Soubor načten, velikost=" . strlen($plaintext) . " B, backup_dt=$backup_dt"
    );

    // ── Krok 5: Zašifruj zálohu backup klíčem ────────────────────────────────
    // Klíč je derivován ze seedu + ed_pub backup nodu → každý backup node má jiný klíč

    $seed = getSeedBin();
    if (!$seed) {
        $err = "Nepodařilo se načíst seed (seedFile není nastaven nebo soubor neexistuje)";
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $backup_encryption_key = hash_hkdf(
        'sha256',
        $seed,
        32,
        'bitcoli:backup:' . b64e($server_ed_pub_bin)  // váže klíč ke konkrétnímu backup nodu
    );

    $backup_nonce   = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $encrypted_blob = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
        $plaintext,
        b64e($server_ed_pub_bin),   // AAD — brání přehrání šifrátu na jiný node
        $backup_nonce,
        $backup_encryption_key
    );

    $blob = $backup_nonce . $encrypted_blob;
    addtolog("backup :" . $backup_file, "Záloha zašifrována, blob=" . strlen($blob) . " B");

    // ── Krok 6: Sestav a zašifruj secure request ─────────────────────────────

    $secure_payload = [
        'cmd'          => 'uploadbackup',
        'time'         => time(),
        'backup_pswd'  => $backup_pswd,
        'backup_dt'    => $backup_dt,
        'size'         => strlen($blob),
        'checksum'     => b64e(hash('sha256', $blob, true)),
        'data'         => b64e($blob),
    ];

    $secure_nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext   = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
        json_encode($secure_payload, JSON_UNESCAPED_SLASHES),
        '',
        $secure_nonce,
        $encrypt_key
    );

    addtolog("backup :" . $backup_file,
        "Odesílám uploadbackup, checksum=" . $secure_payload['checksum']
    );

    // ── Krok 7: Odešli a zpracuj odpověď ─────────────────────────────────────

    $secure_res = backup_http_post(
        $backup_node_url . '/secure',
        [
            'sid'        => $sid,
            'time'       => time(),
            'nonce'      => b64e($secure_nonce),
            'ciphertext' => b64e($ciphertext),
        ],
        $backup_file
    );

    if (!$secure_res) {
        $err = "Žádná odpověď na secure request";
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $decrypted_res = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
        b64d($secure_res['ciphertext'] ?? ''),
        '',
        b64d($secure_res['nonce'] ?? ''),
        $decrypt_key
    );

    if ($decrypted_res === false) {
        $err = "Nepodařilo se dešifrovat odpověď serveru. Raw: " . json_encode($secure_res);
        addtolog("backup :" . $backup_file, $err);
        return $err;
    }

    $result = json_decode($decrypted_res, true);
    addtolog("backup :" . $backup_file, "Odpověď serveru: " . json_encode($result));

    if (($result['error'] ?? true) === false && ($result['code'] ?? -1) === 0) {
        addtolog("backup :" . $backup_file,
            "Záloha úspěšná. backup_dt=$backup_dt, blob=" . strlen($blob) . " B"
        );
        return '';  // ← úspěch
    }

    $err = "Server odmítl zálohu: kód=" . ($result['code'] ?? '?') .
           ", zpráva=" . ($result['message'] ?? json_encode($result));
    addtolog("backup :" . $backup_file, $err);
    return $err;
}


/**
 * Vrátí seznam záloh na vzdáleném backup nodu, nebo null při chybě.
 *
 * @param int $backup_node_idx  Primární klíč v tabulce backup_nodes (0 = neukládej ed_pub)
 */
function getBackupList(
    string $backup_node_url,
    string $backup_node_edpub,
    int    $backup_node_idx = 0,
    string $backup_file = 'listbackups'
): ?array {

    $backup_node_url = BACKUP_PLACEHOLDERS[$backup_node_url] ?? $backup_node_url;

    // ── Handshake ─────────────────────────────────────────────────────────────

    $hs = backup_handshake($backup_node_url, $backup_node_edpub, $backup_node_idx, $backup_file);
    if (is_string($hs)) {
        addtolog("backup :" . $backup_file, "Handshake selhal: $hs");
        return null;
    }

    $sid         = $hs['sid'];
    $encrypt_key = $hs['encrypt_key'];
    $decrypt_key = $hs['decrypt_key'];

    // Secure request — listbackups
    $secure_payload = ['cmd' => 'listbackups', 'time' => time()];
    $secure_nonce   = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext     = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
        json_encode($secure_payload, JSON_UNESCAPED_SLASHES), '', $secure_nonce, $encrypt_key
    );

    $secure_res = backup_http_post(
        $backup_node_url . '/secure',
        ['sid' => $sid, 'time' => time(), 'nonce' => b64e($secure_nonce), 'ciphertext' => b64e($ciphertext)],
        $backup_file
    );
    if (!$secure_res) return null;

    $decrypted_res = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
        b64d($secure_res['ciphertext'] ?? ''), '', b64d($secure_res['nonce'] ?? ''), $decrypt_key
    );
    if ($decrypted_res === false) return null;

    $result = json_decode($decrypted_res, true);
    return ($result['error'] ?? true) === false ? ($result['backups'] ?? []) : null;
}



/**
 * Stáhne zálohu ze vzdáleného backup nodu a uloží ji jako soubor.
 *
 * @param string $backup_node_url    URL backup nodu (bez lomítka na konci)
 * @param string $backup_node_edpub  Ed25519 pub klíč serveru v base64url, nebo '' (TOFU)
 * @param int    $backup_node_idx    Primární klíč v tabulce backup_nodes (0 = neukládej ed_pub)
 * @param string $dest_file          Cesta, kam se má záloha uložit
 * @param int    $backup_dt          Unix timestamp konkrétní zálohy; 0 = nejnovější
 *
 * @return string  Prázdný řetězec při úspěchu, popis chyby při selhání
 */
function downloadBackup(
    string $backup_node_url,
    string $backup_node_edpub,
    int    $backup_node_idx,
    string $dest_file,
    int    $backup_dt = 0
): string {

    $backup_node_url = BACKUP_PLACEHOLDERS[$backup_node_url] ?? $backup_node_url;
    $log = $dest_file ?: 'downloadbackup';
    addtolog("backup :" . $log, "Zahajuji stahování zálohy z $backup_node_url (backup_dt=$backup_dt)");

    // ── Handshake ─────────────────────────────────────────────────────────────

    $hs = backup_handshake($backup_node_url, $backup_node_edpub, $backup_node_idx, $log);
    if (is_string($hs)) return $hs;

    $sid               = $hs['sid'];
    $encrypt_key       = $hs['encrypt_key'];
    $decrypt_key       = $hs['decrypt_key'];
    $server_ed_pub_bin = $hs['server_ed_pub_bin'];

    // ── Secure request: downloadbackup ────────────────────────────────────────

    $secure_payload = [
        'cmd'       => 'downloadbackup',
        'time'      => time(),
        'backup_dt' => $backup_dt,   // 0 = server vybere nejnovější
    ];

    $secure_nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext   = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
        json_encode($secure_payload, JSON_UNESCAPED_SLASHES),
        '',
        $secure_nonce,
        $encrypt_key
    );

    addtolog("backup :" . $log, "Odesílám downloadbackup request");

    $secure_res = backup_http_post(
        $backup_node_url . '/secure',
        [
            'sid'        => $sid,
            'time'       => time(),
            'nonce'      => b64e($secure_nonce),
            'ciphertext' => b64e($ciphertext),
        ],
        $log
    );

    if (!$secure_res) {
        $err = "Žádná odpověď na secure request (downloadbackup)";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    // ── Dešifruj odpověď ─────────────────────────────────────────────────────

    $decrypted_res = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
        b64d($secure_res['ciphertext'] ?? ''),
        '',
        b64d($secure_res['nonce'] ?? ''),
        $decrypt_key
    );

    if ($decrypted_res === false) {
        $err = "Nepodařilo se dešifrovat odpověď serveru. Raw: " . json_encode($secure_res);
        addtolog("backup :" . $log, $err);
        return $err;
    }

    $result = json_decode($decrypted_res, true);
    addtolog("backup :" . $log, "Odpověď serveru: error=" . ($result['error'] ? 'true' : 'false')
        . " code=" . ($result['code'] ?? '?')
        . " backup_dt=" . ($result['backup_dt'] ?? '?')
        . " size=" . ($result['size'] ?? '?'));

    if (($result['error'] ?? true) !== false || ($result['code'] ?? -1) !== 0) {
        $err = "Server odmítl download: kód=" . ($result['code'] ?? '?')
             . ", zpráva=" . ($result['message'] ?? json_encode($result));
        addtolog("backup :" . $log, $err);
        return $err;
    }

    // ── Ověř checksum ─────────────────────────────────────────────────────────

    $blob = b64d($result['data'] ?? '');

    $expected_checksum = $result['checksum'] ?? '';
    $actual_checksum   = b64e(hash('sha256', $blob, true));

    if (!hash_equals($expected_checksum, $actual_checksum)) {
        $err = "Checksum nesedí! Očekáván=$expected_checksum, skutečný=$actual_checksum";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    addtolog("backup :" . $log, "Checksum OK, blob=" . strlen($blob) . " B");

    // ── Rozšifruj zálohu ─────────────────────────────────────────────────────
    // Blob je: nonce (12 B) || ChaCha20-Poly1305 ciphertext
    // Klíč je derivován ze seedu + ed_pub backup nodu (stejně jako v sendBackup)

    $nonce_len = SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES;

    if (strlen($blob) <= $nonce_len) {
        $err = "Přijatý blob je příliš krátký (délka=" . strlen($blob) . " B)";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    $seed = getSeedBin();
    if (!$seed) {
        $err = "Nepodařilo se načíst seed";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    $backup_encryption_key = hash_hkdf(
        'sha256',
        $seed,
        32,
        'bitcoli:backup:' . b64e($server_ed_pub_bin)  // stejný kontext jako v sendBackup
    );

    $blob_nonce     = substr($blob, 0, $nonce_len);
    $blob_encrypted = substr($blob, $nonce_len);

    $plaintext = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
        $blob_encrypted,
        b64e($server_ed_pub_bin),   // AAD — stejné jako v sendBackup
        $blob_nonce,
        $backup_encryption_key
    );

    if ($plaintext === false) {
        $err = "Nepodařilo se rozšifrovat zálohu (špatný klíč nebo poškozená data)";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    addtolog("backup :" . $log, "Záloha rozšifrována, plaintext=" . strlen($plaintext) . " B");

    // ── Ulož na disk ──────────────────────────────────────────────────────────

    if (!atomic_write($dest_file, $plaintext)) {
        $err = "Nepodařilo se zapsat soubor: $dest_file";
        addtolog("backup :" . $log, $err);
        return $err;
    }

    $actual_dt = (int) ($result['backup_dt'] ?? $backup_dt);
    addtolog("backup :" . $log,
        "Záloha úspěšně stažena a uložena. backup_dt=$actual_dt, dest=$dest_file"
    );

    return '';  // ← úspěch
}