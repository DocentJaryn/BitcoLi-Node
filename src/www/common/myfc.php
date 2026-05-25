<?php
require_once __DIR__ . '/database.php';

$cfg = require __DIR__ . '/config.php';
$seedFile = $cfg['DIR_KEYS'] . "/seed.bin";
$onionFile = $cfg['DIR_KEYS'] . "/onion.txt";
$privKeyFile = $cfg['DIR_KEYS'] . "/privatekey.bin";
$pubKeyFile = $cfg['DIR_KEYS'] . "/publickey.bin";

//$seedFile = "../../data/seed.bin";
/* $onionFile = "../../data/onion.txt";
  $privKeyFile = "../../data/privatekey.bin";
  $pubKeyFile = "../../data/publickey.bin"; */

function is_correct_time($time) {
    return (is_int($time) && (abs(time() - $time) < 120));
}

function getOnionAddr($onionFile): string {
    if (file_exists($onionFile)) {
        return trim(file_get_contents($onionFile));
    } else {
        return null;
    }
}

function getSeedBin() {
    global $seedFile;
    if (file_exists($seedFile)) {
        return file_get_contents($seedFile);
    } else {
        return false;
    }
}


function getPrivateKey() {
    global $privKeyFile;
    if (file_exists($privKeyFile)) {
        return file_get_contents($privKeyFile);
    } else {
        return false;
    }
}

function getPublicKey() {
    global $pubKeyFile;
    if (file_exists($pubKeyFile)) {
        return file_get_contents($pubKeyFile);
    } else {
        return false;
    }
}

function generateRandomString(int $length): string {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $charactersLength = strlen($characters);
    $result = '';

    for ($i = 0; $i < $length; $i++) {
        $result .= $characters[random_int(0, $charactersLength - 1)];
    }

    return $result;
}

function signInvoice($cl_pub_key, $created, $settled, $amount_msat, $fee_msat, $invoice) {
    $ps = $cl_pub_key . ':' . $created . ':' . $settled . ':' . $amount_msat . ':' . $fee_msat . ':' . $invoice;
    $my_ed_priv = getPrivateKey();
    return "1" . b64e(sodium_crypto_sign_detached($ps, $my_ed_priv));
}

function verifyInvoice_signedByClient($cl_pub_key, $created, $settled, $amount_msat, $invoice, $max_fee_msat, $sign) {
    if ((($sign ?? "") == "") || ($sign[0] != '1')) {
        return false;
    }
    try {
        $sign_bin = b64d(substr($sign,1));
        $my_ed_pub = b64e(getPublicKey());
        $payload = $my_ed_pub . ":" . $created . ":" . $settled . ":" . $amount_msat . ":" . $max_fee_msat . ":" . $invoice;
        return (sodium_crypto_sign_verify_detached($sign_bin, $payload, b64d($cl_pub_key)));
    } catch (Exception) {
        return false;
    }
}

function verifyTerms_signedByClient($cl_pub_key, $terms, int $time_sign, $sign) {
    if ((($sign ?? "") == "") || ($sign[0] != '1')) {
        return false;
    }
    try {
        $sign_bin = b64d(substr($sign,1));
        //$my_ed_pub = b64e(getPublicKey());
        $payload = $terms. ":" . $time_sign;
        return (sodium_crypto_sign_verify_detached($sign_bin, $payload, b64d($cl_pub_key)));
    } catch (Exception) {
        return false;
    }

}

function isCorrectPubKey(string $pub_key): bool {
    try {
        $bin = b64d($pub_key);
        if (strlen($bin) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        sodium_crypto_sign_publickey_from_secretkey(
                random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
        );

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function b64d(string $b64): string {
   // return sodium_base642bin($v, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
    return sodium_base642bin($b64, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function b64e(string $bin): string {
    //return sodium_bin2base64($v, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
    return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function base64_to_base64url($base64) {
    return rtrim(strtr($base64, '+/', '-_'), '=');
}

function base64url_encode(string $bin): string {
    return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function base64url_decode(string $b64): string {
    return sodium_base642bin($b64, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}


function get_terms(int $max_balance_sat,int $fee_ppm) {
    $cfg = loadconfig();
    $terms = json_decode($cfg['terms']);
    $terms->node_pub_key = b64e(getPublicKey());
    $terms->max_balance_sat = $max_balance_sat;
    $terms->fee_ppm = $fee_ppm;
    return json_encode($terms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function get_terms_hash(int $max_balance_sat,int $fee_ppm) {
    $terms = get_terms($max_balance_sat, $fee_ppm);
    return b64e(hash('sha256', $terms, true));
}


function decodeBolt11($invoice) {
    $invoice = strtolower($invoice);
    $m = [];
    if (!preg_match('/^(ln\w+?)(1)([02-9ac-hj-np-z]+)$/', $invoice, $m)) {
        throw new Exception("Invalid BOLT11 invoice");
    }

    $hrp = $m[1];
    $data = $m[3];

    $result = [
        "hrp" => $hrp,
        "network" => null,
        "amount_sat" => null,
        "timestamp" => null,
        "description" => null,
        "payment_hash" => null,
        "expiry" => 3600
    ];

    // network
    if (str_starts_with($hrp, "lnbc")) {
        $result["network"] = "bitcoin";
    } elseif (str_starts_with($hrp, "lntb")) {
        $result["network"] = "testnet";
    } elseif (str_starts_with($hrp, "lnbcrt")) {
        $result["network"] = "regtest";
    }

    // amount decode
    $am = [];
    if (preg_match('/ln\w+(\d+)([munp]?)/', $hrp, $am)) {
        $num = intval($am[1]);
        $mult = $am[2] ?? '';

        $btc = match ($mult) {
            'm' => $num * 0.001,
            'u' => $num * 0.000001,
            'n' => $num * 0.000000001,
            'p' => $num * 0.000000000001,
            default => $num
        };

        $result["amount_sat"] = (int) round($btc * 100000000);
    }

    $charset = "qpzry9x8gf2tvdw0s3jn54khce6mua7l";

    $values = [];
    foreach (str_split($data) as $c) {
        $values[] = strpos($charset, $c);
    }

    $bits = "";
    foreach ($values as $v) {
        $bits .= str_pad(decbin($v), 5, "0", STR_PAD_LEFT);
    }

    // timestamp (35 bits)
    $result["timestamp"] = bindec(substr($bits, 0, 35));

    $pos = 35;

    while ($pos + 15 < strlen($bits)) {

        $tag = bindec(substr($bits, $pos, 5));
        $pos += 5;

        $len = bindec(substr($bits, $pos, 10));
        $pos += 10;

        $dataBits = substr($bits, $pos, $len * 5);
        $pos += $len * 5;

        if ($tag == 1) { // payment_hash
            $result["payment_hash"] = bin2hex(bitsToBytes($dataBits));
        }

        if ($tag == 13) { // description
            $result["description"] = bitsToText($dataBits);
        }

        if ($tag == 6) { // expiry
            $result["expiry"] = bindec($dataBits);
        }
    }

    return $result;
}

function bitsToBytes($bits) {
    $bytes = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) == 8) {
            $bytes .= chr(bindec($b));
        }
    }
    return $bytes;
}

function bitsToText($bits) {
    return trim(bitsToBytes($bits));
}

function atomic_write(string $path, string $data): bool {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
