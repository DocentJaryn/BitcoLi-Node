<?php
/**
 * runheartbeat.php
 *
 * Spouští se na pozadí z ln-listener.php:
 *   exec(PHP_BINARY . ' runheartbeat.php <tor_addr> > /dev/null 2>&1 &');
 *
 * Pošle podepsaný heartbeat na proxy server s aktuální Tor adresou nodu
 * přes Tor SOCKS5 proxy (stejně jako zálohy) — IP adresa nodu zůstane skryta.
 * Okamžitě skončí, nikdy neblokuje hlavní vlákno ln-listener.php.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/myfc.php';
require_once __DIR__ . '/constants.php';

$cfg      = require __DIR__ . '/config.php';
$tor_addr = trim($cfg['TOR_HOST'] ?? '');

if ($tor_addr === '') {
    exit(1);
}

if (!str_starts_with($tor_addr, 'http')) {
    $tor_addr = 'http://' . $tor_addr;
}

$GLOBALS["DB"] = new Database();

$priv = getPrivateKey();
$pub  = getPublicKey();

if (!$priv || !$pub) {
    addtolog('heartbeat', 'Cannot send heartbeat — keys not available');
    exit(1);
}

$timestamp = time();
$message   = $tor_addr . ':' . $timestamp;
$signature = b64e(sodium_crypto_sign_detached($message, $priv));

$payload = json_encode([
    'node_id'   => b64e($pub),
    'tor_addr'  => $tor_addr,
    'timestamp' => $timestamp,
    'signature' => $signature,
]);

$ch = curl_init(PROXY_HEARTBEAT_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_PROXY          => $cfg['TOR_PROXY'],
    CURLOPT_PROXYTYPE      => CURLPROXY_SOCKS5_HOSTNAME,
]);

$resp  = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
curl_close($ch);

if ($errno !== 0) {
    addtolog('heartbeat', "Failed (curl errno $errno: $err) tor=$tor_addr");
    exit(1);
}

addtolog('heartbeat', "OK tor=$tor_addr resp=$resp");
exit(0);