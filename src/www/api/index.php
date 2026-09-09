<?php

require_once __DIR__ . '/../common/myfc.php';
require_once __DIR__ . "/../common/database.php";
require_once __DIR__ . "/../common/lnd.class.php";

//Jakou verzi komunikačního protokolu používá klient
global $client_v;
$client_v = 0;


function getBalance(int $user_idx): int {
    $table = $GLOBALS["DB"]->query(
            "SELECT COALESCE(SUM(paid_msat),0) - COALESCE(SUM(fee_msat),0) AS paid_msat FROM transactions WHERE user_idx = ? and payment_status >= 0 ",
            [$user_idx]
    );

    return (int) ($table[0]['paid_msat'] ?? 0);
}

function getMaxBalanceSat(string $terms_hash): int {
    $table = $GLOBALS["DB"]->query('SELECT max_balance_sat  ' .
            'FROM terms_history where hash = ?',
            [$terms_hash]);
    return ($table[0]['max_balance_sat'] ?? 0);
}

function encrypt_res($json) {
    global $sid;
    global $encrypt_key;
    global $client_v;

    $json_res = new stdClass();
    $json_res->time = time();
    $json_res->sid = $sid;
    $my_nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $json_res->nonce = b64e($my_nonce);
//$json_res->ciphertext = json_encode($json_cipher);
    $data = json_encode($json);
    if (($client_v > 0) && (strlen($data) > 200)) { //podporuje kompresi
      $compressed = gzcompress($data);
      addtolog("test compress", "Original:".strlen($data) ." Compressed:".strlen($compressed));
      if (strlen($data) > strlen($compressed)) {
          //komprese je efektivní, posílám komprimovaně
          $data = $compressed;
      }
    }
    $cipherdata = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
                    $data, '', $my_nonce, $encrypt_key);
    if ($client_v > 0) {
      echo(json_encode($json_res)."\0".$cipherdata);  
    } else { //verze 1 a vyšší umožňuje binární data
      $json_res->ciphertext = b64e(
            $cipherdata);
      echo(json_encode($json_res));
    }
}

function api_error_response(int $code, string $message, bool $returnjson = false, array $extra = []) {
    $json_res = new stdClass();
    $json_res->error = true;
    $json_res->code = $code;
    $json_res->time = time();
    $json_res->message = $message;

    // přidání libovolných extra parametrů
    foreach ($extra as $key => $value) {
        $json_res->$key = $value;
    }


    global $fnName;
    addtolog('<- ' . $fnName, json_encode($json_res));

    if ($returnjson) {
        return $json_res;
    } else {
        echo json_encode($json_res);
    }
}

function errorLnd($returnjson = false) {
    $json_res = new stdClass();
    $json_res->error = true;
    $json_res->code = 7;
    $json_res->message = 'LND failure, please try again later.';
    if ($returnjson) {
        return $json_res;
    } else {
        echo(json_encode($json_res));
    }
}

function err_OK($returnjson = false) {
    $json_res = new stdClass();
    $json_res->error = false;
    $json_res->code = 0;
    $json_res->time = time();
    $json_res->message = "OK";
    if ($returnjson) {
        return $json_res;
    } else {
        echo(json_encode($json_res));
        global $fnName;
// addtolog('<- ' . $fnName, json_encode($json_res));
    }
}

function err_not_implemented($returnjson = false) {
    return api_error_response(99, "This feature is not yet implemented.", $returnjson);
}

function err_invalidJson($returnjson = false) {
    return api_error_response(1, 'Invalid json', $returnjson);
}

function err_missingParam(string $missingParam, $returnjson = false) {
    return api_error_response(2, "Missing '$missingParam'", $returnjson);
}

function err_badTime($returnjson = false) {
    return api_error_response(3, "Timestamp out of range.", $returnjson);
}

function err_badRequest($returnjson = false) {
    return api_error_response(4, "Bad request.", $returnjson);
}

function err_sessionExpired($returnjson = false) {
    return api_error_response(5, "Session expired.", $returnjson);
}

function err_insufficientBalance($returnjson = false) {
    return api_error_response(6, "Insufficient balance", $returnjson);
}

function err_duplicate_payment($returnjson = false) {
    return api_error_response(7, "Duplicate payment.", $returnjson);
}

function err_invalid_sign($returnjson = false) {
    return api_error_response(8, "Invalid signature", $returnjson);
}

function err_invoiceExpired(int $expired, $returnjson = false) {
    return api_error_response(9, "invoice expired. Valid until " . unixToSQLdatetime($expired) . " +0000 UTC", $returnjson);
}

function err_paymentFailed(string $reason, $returnjson = false) {
    return api_error_response(9, "The payment failed for the following reason:\n" . $reason, $returnjson);
}

function err_balanceExceeded(int $maxSats, $returnjson = false) {
    return api_error_response(10, "The maximum allowed balance of " . $maxSats . " SAT has been exceeded.", $returnjson);
}

function err_LND(string $reason, $returnjson = false) {
    return api_error_response(11, "The following error occurred while communicating with LND:\n" . $reason, $returnjson);
}

function err_invitation($returnjson = false) {
    return api_error_response(12, "This invitation is no longer valid.", $returnjson);
}

function err_only_registered($returnjson = false) {
    return api_error_response(13, "This feature is only available to registered users.", $returnjson);
}

function err_invalid_sign_terms(string $terms, $returnjson = false) {
    return api_error_response(14, "invalid signature of terms of use", $returnjson, ["terms" => $terms]);
}

function err_exception(string $exception, $returnjson = false) {
    return api_error_response(14, $exception, $returnjson);
}

function err_starting($returnjson = false) {
    return api_error_response(16, 'The node is starting up.', $returnjson);
}


function err_maintenance($returnjson = false) {
    return api_error_response(17, 'maintenance is underway, please try again later.', $returnjson);
}


function API_status() {
    err_OK();
}

function API_hello() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        err_invalidJson();
        return;
    }

    foreach ([
'nonce', 'time',
 'ed_pub', 'x_pub', 'sign'
    ] as $k) {
        if (!isset($data[$k])) {
            err_missingParam($k);
            return;
        }
    }

    if (!is_correct_time($data['time'])) {
        err_badTime();
        return;
    }


    $cl_sign = b64d($data['sign']);
    unset($data['sign']);

    $payload = json_encode($data, JSON_UNESCAPED_SLASHES);

    $cl_ed_pub = b64d($data['ed_pub']);
    $cl_x_pub = b64d($data['x_pub']);
    $my_ed_priv = getPrivateKey();
    
    if (!sodium_crypto_sign_verify_detached($cl_sign, $payload, $cl_ed_pub)) {
//TODO: Invalid signature
        $json_res = err_invalid_sign(true);
        $json_res->received = $raw;
        /* $json_res = new stdClass();
          $json_res->data = $data;
          $json_res->payload = b64e($payload);
          $json_res->sign = b64e($cl_sign); */
//$json_res->send = $data['x_pk'];
        $json_res->sign = b64e(sodium_crypto_sign_detached(json_encode($json_res, JSON_UNESCAPED_SLASHES), $my_ed_priv));
        echo(json_encode($json_res));
        return;
    }


// vygeneruje X25519 keypair
    $x25519_kp = sodium_crypto_box_keypair();

// získání veřejného klíče
    $my_x_pub = sodium_crypto_box_publickey($x25519_kp);

// získání soukromého klíče
    $my_x_priv = sodium_crypto_box_secretkey($x25519_kp);
    $shared = sodium_crypto_scalarmult(
            $my_x_priv,
            $cl_x_pub
    );
    if ($shared === false) {
        err_missingParam("x_pub");
        return;
    }

//každý směr má jiný klíč, snižuji riziko duplicity (nonce, key)
    $decrypt_key = hash_hkdf('sha256', $shared, 32, 'x25519-session-key:client->server');
    $encrypt_key = hash_hkdf('sha256', $shared, 32, 'x25519-session-key:server->client');

    $sid = generateRandomString(10);

    /* $table = $GLOBALS["DB"]->query("SELECT idx FROM users where pub_key = ?;",
      [b64e($cl_ed_pub)]);
      $user_idx = $table[0]['idx'] ?? null;

      if (!is_int($user_idx)) {
      //ještě neexistuje
      $table = $GLOBALS["DB"]->query("INSERT INTO users set pub_key = ?, created = utc_timestamp();",
      [b64e($cl_ed_pub)]);

      $user_idx = $GLOBALS["DB"]->lastInsertId();
      } */

    $GLOBALS["DB"]->query("INSERT INTO sessions (sid,  pub_key, decrypt_key, encrypt_key, expiration) VALUES (?,?,?,?, adddate(utc_timestamp(),interval 7 day));",
            [$sid, b64e($cl_ed_pub), $decrypt_key, $encrypt_key]);

    $json_cipher = new stdClass();
    $json_cipher->salt = generateRandomString(random_int(2, 20));
    $json_cipher->sid = $sid;

    $json_res = new stdClass();


//TODO: pouze debug!!!!
    /*    $json_res->debug_sharedSecret = b64e($shared);
      $json_res->debug_decrypt_key = b64e($decrypt_key);
      $json_res->debug_encrypt_key = b64e($encrypt_key);
      $json_res->debug_ed_priv = b64e($my_ed_priv); */

    $json_res->code = 0;
    $json_res->message = "OK";

    $json_res->time = time();
    $json_res->session_expiry = time() + (60 * 60 * 24 * 7);
    $json_res->ed_pub = b64e(getPublicKey());
    $json_res->x_pub = b64e($my_x_pub);
    $my_nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
    $json_res->nonce = b64e($my_nonce);
//$json_res->ciphertext = json_encode($json_cipher);
    $json_res->ciphertext = b64e(
            sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
                    json_encode($json_cipher), '', $my_nonce, $encrypt_key));

    $json_res->sign = b64e(sodium_crypto_sign_detached(json_encode($json_res, JSON_UNESCAPED_SLASHES), $my_ed_priv));

    echo(json_encode($json_res));
}

function SEC_transactions($json) {
    global $cl_idx;
    global $terms_valid_until;
    global $cl_terms_hash;
    if ($cl_idx === null) {
        encrypt_res(err_only_registered(true));
        return;
    }

    set_time_limit(100);
    if (!is_int($json['timeout'] ?? null)) {
        encrypt_res(err_missingParam('timeout', true));
        return;
    }
    $settle_idx = $json['last_settle_idx'];
    $trn_idx = $json['last_trn_idx'];
    $timeout = abs($json['timeout']);

    if ($timeout > 60) {
        $timeout = 60;
    }

    $endtime = time() + $timeout;

    do {

        $table = $GLOBALS["DB"]->query("SELECT UNIX_TIMESTAMP(created) as created, " .
                "UNIX_TIMESTAMP(settled) as settled, expiry_sec, payment_hash, payment_status, " .
                "invoice, requested_msat, paid_msat, fee_msat, max_fee_msat, " .
                "IF(paid_msat = 0, NULL, payment_preimage) AS payment_preimage, " .
                "sign_client, sign_node, settle_idx, trn_idx, memo, ext_info FROM transactions " .
                "WHERE user_idx = ? and (settle_idx > ? or trn_idx > ?) ",
                [$cl_idx, $settle_idx, $trn_idx]);
        sleep(1);
    } while (($endtime > time()) and (connection_status() == 0) and (!connection_aborted()) and (!$table));
    $json_res = new stdClass();
    $json_res->code = 0;
    $json_res->message = 'OK';
    $json_res->balance = getBalance($cl_idx);
    if (($terms_valid_until != null) && ($terms_valid_until < time())) {
        $json_res->max_balance_sat = 0;
    } else {
        $json_res->max_balance_sat = getMaxBalanceSat($cl_terms_hash);
    }

    $json_res->terms_valid_until = $terms_valid_until;
    $json_res->txs = $table;
    
    global $config;
    $addr = $config['clearnet_addr'] ?? "";
    $cfg = require __DIR__ . '/../common/config.php';
    if ($addr == "") {
      if (($cfg['TOR_HOST'] ?? "") != "") {
        $addr = $cfg['TOR_HOST'];
      }
    } else {
      if (($cfg['TOR_HOST'] ?? "") != "") {
        $addr = $addr . ','.$cfg['TOR_HOST'];
      }
    }
    if ($addr != "") {
      $json_res->addr = $addr;
    }
    encrypt_res($json_res);

}

function SEC_addinvoice($json) {
    global $cl_pub_key;
    global $cl_idx;
    global $cl_terms_hash;

    if ($cl_idx === null) {
        encrypt_res(err_only_registered(true));
        return;
    }

    $amt_msat = $json['amt_msat'];

    $expiry = $json['expiry'] ?? (5 * 60);
    if ($expiry < 60) {
        $expiry = 60;
    }
    if ($expiry > 365 * 24 * 3600) { //J: maximalni platnost faktury 365 dnů
        $expiry = 365 * 24 * 3600;
    }
    $memo = $json['memo'];

    $description_hash = $json['description_hash'] ?? null;

    if (($description_hash) and hex2bin($description_hash)) {
        $description_hash = base64_encode(hex2bin($description_hash));
    } else {
        $description_hash = null;
    }

//TODO:Dodělat payerdata!
    /* $payer_identifier = $json['payerdata']['identifier'];
      $payer_name = $json['payerdata']['name']; */

    if ((!is_integer($amt_msat)) or ($amt_msat < 0)) {
        encrypt_res(err_badRequest(true));
        return;
    }
    /* $table_limit = $GLOBALS["DB"]->query(" SELECT COALESCE(u.level, 0) as level, " .
      "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm " .
      "FROM users u " .
      "LEFT JOIN user_group g ON u.level = g.level " .
      "WHERE u.idx = ?", [$cl_idx]); */

    $balance = getBalance($cl_idx);
    $maxBalanceSat = getMaxBalanceSat($cl_terms_hash);
    if ($balance >= ($maxBalanceSat * 1000)) {
        encrypt_res(err_balanceExceeded($maxBalanceSat, true));
        return;
    }

    $preimage = base64_encode(random_bytes(32));

    $json_lnd = array();

    $json_lnd['value_msat'] = $amt_msat;
    $json_lnd['r_preimage'] = $preimage;
    $json_lnd['expiry'] = $expiry;
    $json_lnd['memo'] = $memo;
    if ($description_hash) {
        $json_lnd['description_hash'] = $description_hash;
    }

    $lnd = new lnd();
    initlnd($lnd);
    $lnd_res = $lnd->request('v1/invoices', $json_lnd);

    if (($lnd_res->error ?? false) or (($lnd_res->payment_request ?? null) === null)) {
        encrypt_res($lnd_res);
        return;
//errorLnd();
    } else {

        $json_res = new stdClass();
        $json_res->code = 0;
        $json_res->message = 'OK';

        $invoice = $lnd_res->payment_request;
        $json_res->pay_req = $invoice;
        $json_res->payment_hash = base64_to_base64url($lnd_res->r_hash);

        $created = time();
        $settled = null;
        $invoice = $json_res->pay_req;

        $json_res->sign = signInvoice($cl_pub_key, $created, $settled, 0, 0, $invoice);
        $trn_idx = inc_trn_idx($cl_idx);

        $GLOBALS["DB"]->query("INSERT INTO transactions (user_idx, created, expiry_sec, payment_hash, payment_status, requested_msat, paid_msat, invoice, sign_node, payment_preimage, trn_idx, memo ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$cl_idx, unixToSQLdatetime($created), $expiry, "+" .
                    $json_res->payment_hash, 0, $amt_msat, 0, $invoice, $json_res->sign,
                    base64_to_base64url($preimage), $trn_idx, $memo]);

        encrypt_res($json_res);
    }
}

function SEC_payinvoice($json) {
    global $cl_pub_key;
    global $cl_idx;
    if ($cl_idx === null) {
        encrypt_res(err_only_registered(true));
        return;
    }
    try {
        $invoice = $json['invoice'];
        $amt_msat = $json['amt_msat'];
        $max_fee_msat = $json['max_fee_msat'];
        $fee_msat = $max_fee_msat;
        $created = $json['created'];
        $memo = $json['memo'];
        $sign_client = $json['sign'];
        if (($json['ext_info'] ?? "") == "") {
            $ext_info = "";
        } else {
            $ext_info = json_encode($json['ext_info']);
        }
        $settled_dt = "";
        //$settled_msat = 0;
        if (!is_correct_time($created)) {
            encrypt_res(err_badTime(true));
            return;
        }
        if (!is_int($amt_msat) || ($amt_msat <= 0) || (!is_int($max_fee_msat)) || ($max_fee_msat <= 0) || ($invoice == "")) {
            throw new Exception();
        }
    } catch (Exception) {
//encrypt_res(err_missingParam(Exception,true));
        encrypt_res(err_badRequest(true));
        return;
    }

    if (!verifyInvoice_signedByClient($cl_pub_key, $created, $settled_dt, $amt_msat * -1, $invoice, $max_fee_msat, $sign_client)) {
        encrypt_res(err_invalid_sign(true));
        return;
    }

    $lnd = new lnd();
    initlnd($lnd);
    $lnd_res = $lnd->request('v1/payreq/' . $invoice);
    addtolog('v1/payreq/', json_encode($lnd_res));
    if (($lnd_res->code ?? 0) != 0) {
        //$lnd_res->paid = false;
        addtolog('<- SEC_payinvoice', json_encode($lnd_res));
        encrypt_res(err_LND($lnd_res->message ?? "", true));
        return;
    }

    if (((int) $lnd_res->timestamp + (int) $lnd_res->expiry) < time()) {
        encrypt_res(err_invoiceExpired((int) $lnd_res->timestamp + (int) $lnd_res->expiry, true));
        return;
    }


    $payment_hash = base64url_encode(hex2bin($lnd_res->payment_hash));
    $requested_msat = $lnd_res->num_msat ?? 0;

    $trn_idx = inc_trn_idx($cl_idx);

    try {

        /*        INSERT INTO transactions (user_id, payment_hash, paid_msat, status, created_at)
          SELECT ?, ?, ?, 'pending', UTC_TIMESTAMP()
          FROM DUAL
          WHERE (
          SELECT COALESCE(SUM(paid_msat),0) - COALESCE(SUM(fee_msat),0)
          FROM transactions
          WHERE user_id = ?
          ) >= ?; */

        $GLOBALS["DB"]->query("INSERT INTO transactions (user_idx, created, expiry_sec, " .
                "payment_hash, payment_status, requested_msat, paid_msat, fee_msat, " .
                "max_fee_msat, invoice, sign_client, payment_preimage, trn_idx, memo, ext_info ) " .
                "SELECT  ?,?,?,?,?,?,?,?,?,?,?,?,?,?,? " .
                "FROM DUAL " . //J: kontrola zůstatku
                "WHERE (SELECT COALESCE(SUM(paid_msat),0) - COALESCE(SUM(fee_msat),0) " .
                "FROM transactions " .
                "WHERE user_idx = ? and payment_status >= 0) >= ? " .
                "AND NOT EXISTS (SELECT 1 " . //J: kontrola jestli už není zaplacena
                "FROM transactions " .
                "WHERE payment_hash = ? AND payment_status >= 1);",
                [$cl_idx, unixToSQLdatetime($created), $lnd_res->expiry, "-" . $payment_hash,
                    1, $requested_msat, $amt_msat * -1, $fee_msat, $max_fee_msat,
                    $invoice, $sign_client, "", $trn_idx, $memo, $ext_info,
                    $cl_idx, $amt_msat + $max_fee_msat,
                    "-" . $payment_hash]);
        $idx = $GLOBALS["DB"]->lastInsertId();
        if (ctype_digit($idx)) {
            $idx = (int) $idx;
        }
        if (!is_int($idx) || ($idx == 0)) {
            $table = $GLOBALS["DB"]->query("SELECT user_idx FROM transactions Where payment_hash = ? and payment_status >= 1 ",
                    ["-" . $payment_hash]);
            if ($table) {
                encrypt_res(err_duplicate_payment(true));
            } else {
                encrypt_res(err_insufficientBalance(true));
            }
            return;
        }
    } catch (Exception $exc) {
        //encrypt_res(err_missingParam($exc, true));
        encrypt_res(err_duplicate_payment(true));
        return;
    }
//J: Tady mám platbu zkontrolovanou a zapsanou, jdu ji zaplatit
    try {


        /* Jednoho dne takto možná budu řešit spropitné
          $data = ["dest" => base64_encode(hex2bin($lnd_res->destination)),
          "payment_hash" => base64_encode(hex2bin($lnd_res->payment_hash)),
          "amt_msat" => $amt_msat ,
          "fee_limit_msat" => $max_fee_msat,
          "timeout_seconds" => 60,
          "final_cltv_delta" => 40,
          "allow_self_payment"=> true]; */

        $data = [
            "payment_request" => $invoice,
            "timeout_seconds" => 60,
            "fee_limit_msat" => $max_fee_msat,
            "allow_self_payment" => true
        ];

        if ($requested_msat == 0) {
            $data["amt_msat"] = $amt_msat;
        }


        $lnd_res2 = $lnd->request('v2/router/send', $data);
        addtolog('v2/router/send', json_encode($lnd_res2));
//encrypt_res(err_missingParam(implode(",", $data).' '.json_encode($lnd_res2), true));
//return;
        $paymentOK = null;
        $payment_preimage = null;
        if ($lnd_res2->error ?? false) {
            
        }
        $last_result = end($lnd_res2);
        addtolog('end($lnd_res2)', json_encode($last_result));
        addtolog('end($lnd_res2)->result', json_encode($last_result->result));
        addtolog('end($lnd_res2)->result->status', $last_result->result->status);

        switch ($last_result->result->status ?? "") {
            case "SUCCEEDED":
                $paymentOK = true;
                $fee_msat = $last_result->result->fee_msat ?? $max_fee_msat;
                $payment_preimage = base64_to_base64url($last_result->result->payment_preimage ?? "");
                break;
            case "FAILED":
                $paymentOK = false;
                $fee_msat = 0;
                addtolog('PAYMENT FAILED', "");
                break;
            default : //J: Musím zkontrolovat platbu
                sleep(5);
                $lnd_check = $lnd->request('v2/router/track/' . base64url_encode(hex2bin($lnd_res->payment_hash)) . "=");
                addtolog('v2/router/track/' . base64url_encode(hex2bin($lnd_res->payment_hash)), json_encode($lnd_check));
                switch ($lnd_check->result->status ?? "") {
                    case "SUCCEEDED":
                        $paymentOK = true;
                        $fee_msat = $lnd_check->result->fee_msat ?? $max_fee_msat;
                        $payment_preimage = base64url_encode(hex2bin($lnd_check->result->payment_preimage ?? ""));
                        break;

                    case "FAILED":
                        $paymentOK = false;
                        $fee_msat = 0;
                        break;
                }
                break;
        }
        $settled = time();
        if ($paymentOK === null) {
//J: platba je v neznámém stavu, nebo se ještě zpracovává
            $json_res = new stdClass();
            $json_res->code = 0;
            $json_res->paid = false;
            $json_res->message = 'ok';
            encrypt_res($json_res);
            return;
        } else if ($paymentOK == true) {
//J: platba OK
            $payment_status = 2;
            $node_sign = signInvoice($cl_pub_key, $created, $settled, $amt_msat * -1, $fee_msat, $invoice);
        } else {
//J: platba selhala
            $payment_status = null;
            $node_sign = null;
            $failure_reason = $last_result->result->failure_reason ?? "";
            if ($failure_reason == "") {
                $failure_reason = $last_result->result->message ?? "";
            }
        }
        addtolog("UPDATE transactions", "$idx=" . $idx);
        $GLOBALS["DB"]->query("UPDATE transactions SET settled = ?, payment_status = ?, payment_preimage = ?, fee_msat = ?, settle_idx = ?, sign_node = ? Where idx = ?",
                [unixToSQLdatetime($settled), $payment_status, $payment_preimage, $fee_msat, inc_settle_idx($cl_idx), $node_sign, $idx]);
        if ($paymentOK) {
            $json_res = new stdClass();
            $json_res->code = 0;
            $json_res->paid = true;
            $json_res->message = 'ok';
            encrypt_res($json_res);
            return;
        } else {
            encrypt_res(err_paymentFailed($failure_reason, true));
            return;
        }
    } catch (Exception $exc) {
        addtolog("transactions Exception", $exc);
//echo $exc->getTraceAsString();
    }
}

function SEC_getterms($json) {
    global $cl_idx;
    if ($cl_idx) {
        $table_limit = $GLOBALS["DB"]->query("SELECT COALESCE(u.level, 0) as level, " .
                "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm , h.terms, UNIX_TIMESTAMP(u.terms_time) as terms_time, u.terms_sign, UNIX_TIMESTAMP(u.terms_valid_until) as terms_valid_until " .
                "FROM users u " .
                "LEFT JOIN terms_history h ON u.terms_hash = h.hash " .
                "LEFT JOIN user_group g ON u.level = g.level " .
                "WHERE u.idx = ?", [$cl_idx]);
        /*        $table_limit = $GLOBALS["DB"]->query(" SELECT COALESCE(u.level, 0) as level, " .
          "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm " .
          "FROM users u " .
          "LEFT JOIN user_group g ON u.level = g.level " .
          "WHERE u.idx = ?", [$cl_idx]); */
    } else {
        //$invitation = ($json['invitation']);
        $table_limit = $GLOBALS["DB"]->query("SELECT COALESCE(g.level, 0) as level, " .
                "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm " .
                "FROM invitations i " .
                "LEFT JOIN user_group g ON i.level = g.level " .
                "WHERE i.id = ? and i.cnt > i.used", [$json['invitation'] ?? ""]);

        if (!$table_limit) {
            encrypt_res(err_invitation(true));
            return;
        }
    }

    $level = $table_limit[0]['level'];
    $max_balance = $table_limit[0]['max_balance'];
    $fee_ppm = $table_limit[0]['fee_ppm'];
    global $config;
   /* $table = $GLOBALS["DB"]->query("select * from config", []);
    $config = array_column($table, "value", "id");*/

    $json_res = new stdClass();
    $json_res->code = 0;
    $json_res->message = 'OK';
    $json_res->signed_terms = $table_limit[0]['terms'] ?? null;
    $json_res->terms_time = $table_limit[0]['terms_time'] ?? null;
    $json_res->terms_valid_until = $table_limit[0]['terms_valid_until'] ?? null;
    $json_res->terms_sign = $table_limit[0]['terms_sign'] ?? null;
    if (($json_res->terms_sign != null) && ($json_res->signed_terms == null)) {
        //uživatel podmínky podepsal, ale nenašel jsem je. 
        $json_res->signed_terms = get_terms(-1, 0);
    } 
    $json_res->terms = get_terms($max_balance, $fee_ppm); //$config["terms"] ?? null;
    if ($json_res->terms == $json_res->signed_terms) {
        unset($json_res->terms);
    }
    $json_res->node_name = $config["node_name"] ?? null;
    $json_res->time = time();
    encrypt_res($json_res);
}

function SEC_signterms($json) {
    global $cl_idx;
    global $cl_pub_key;

    $date_sign = $json['time_sign'] ?? $json['time'] ?? 0;

    if (!is_correct_time($date_sign)) {
        encrypt_res(err_badTime(true));
        return;
    }

    $terms_sign = $json['terms_sign'] ?? "";
    if ($terms_sign == "") {
        encrypt_res(err_invalid_sign(true));
        return;
    }

    if ($cl_idx) {
        $table_limit = $GLOBALS["DB"]->query(" SELECT COALESCE(u.level, 0) as level, " .
                "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm " .
                "FROM users u " .
                "LEFT JOIN user_group g ON u.level = g.level " .
                "WHERE u.idx = ?", [$cl_idx]);
    } else {
        //$invitation = ($json['invitation']);
        $table_limit = $GLOBALS["DB"]->query("SELECT COALESCE(g.level, 0) as level, " .
                "COALESCE(g.max_balance_sat,0) as max_balance, COALESCE(g.fee_ppm,0) as fee_ppm " .
                "FROM invitations i " .
                "LEFT JOIN user_group g ON i.level = g.level " .
                "WHERE i.id = ? and i.cnt > i.used", [$json['invitation'] ?? ""]);

        if (!$table_limit) {
            encrypt_res(err_invitation(true));
            return;
        }
    }


    $level = $table_limit[0]['level'];
    $max_balance = $table_limit[0]['max_balance'] ?? 0;
    $fee_ppm = $table_limit[0]['fee_ppm'] ?? 0;

    /*    $table = $GLOBALS["DB"]->query("select * from config", []);
      $config = array_column($table, "value", "id"); */

//    addtolog(get_terms($max_balance, $fee_ppm), $date_sign.':'. $sign);
    $terms = get_terms($max_balance, $fee_ppm);

    if (!verifyTerms_signedByClient($cl_pub_key, $terms, $date_sign, $terms_sign)) {
        encrypt_res(err_invalid_sign_terms($terms, true));
        return;
    };

    $terms_hash = b64e(hash('sha256', $terms, true));

    try {
        $GLOBALS["DB"]->query("INSERT INTO terms_history (hash, terms, terms_time, max_balance_sat, fee_ppm) VALUES (?,?,now(),?,?)", [$terms_hash, $terms, $max_balance, $fee_ppm]);
    } catch (Exception $exc) {
        
    }

    //uživatel již existuje, podepisuje nove podminky
    if ($cl_idx) {
        $table = $GLOBALS["DB"]->query("SELECT idx from users WHERE idx = ? and terms_hash = ?",
                [$cl_idx, $terms_hash]);

        if (!$table) {
            $GLOBALS["DB"]->query("UPDATE users SET terms_hash = ?, terms_time = ?, terms_sign = ?, terms_valid_until = NULL " .
                    "Where idx = ?", [$terms_hash, unixToSQLdatetime($date_sign), $terms_sign, $cl_idx]);
        }
    } else {  //resitrace
        $GLOBALS["DB"]->query("INSERT INTO users (pub_key, created, lastlogin, level, terms_hash, terms_time, terms_sign) " .
                "VALUES (?,now(), now(), ?, ?, ?, ?)", [$cl_pub_key, $level, $terms_hash, unixToSQLdatetime($date_sign), $terms_sign]);

        $GLOBALS["DB"]->query("UPDATE invitations SET used = used + 1 WHERE (id = ?)", [$json['invitation'] ?? ""]);
    }


    encrypt_res(err_OK(true));

    /* $json_res = new stdClass();
      $json_res->code = 0;
      $json_res->message = 'OK';
      $json_res->terms = get_terms($max_balance,$fee_pmm);//$config["terms"] ?? null;
     */




    //$json['cl_sign'];
}

function SEC_uploadbackup($json) {
    global $cl_pub_key;
    try {
        // ověř backup_pswd
        global $config;
        $backup_pswd = $config['backup_pswd'] ?? '';
        if ($backup_pswd != ($json['backup_pswd'] ?? '')) {
            encrypt_res(api_error_response(15, 'Invalid backup password', true));
            return;
        }

        foreach (['backup_dt', 'checksum', 'size', 'data'] as $k) {
            if (empty($json[$k])) {
                encrypt_res(err_missingParam($k, true));
                return;
            }
        }


        $backup_dt = $json['backup_dt'] ?? 0;

        // ověř backup_dt — musí být novější než poslední záloha
        /*
          $last_backup_dt = (int)($config['backup_dt'] ?? 0);
          if (!is_int($backup_dt) || $backup_dt <= $last_backup_dt) {
          encrypt_res(api_error_response(16, 'Backup is not newer than last stored backup', true));
          return;
          } */


        $MAX_BYTES = 50 * 1024 * 1024;
        if (!is_int($json['size']) || $json['size'] > $MAX_BYTES || $json['size'] < 1) {
            encrypt_res(err_badRequest(true));
            return;
        }

        $blob = b64d($json['data']);

        if (strlen($blob) !== $json['size']) {
            encrypt_res(err_badRequest(true));
            return;
        }

        $actual_checksum = b64e(hash('sha256', $blob, true));
        if (!hash_equals($actual_checksum, $json['checksum'])) {
            encrypt_res(api_error_response(17, 'Checksum mismatch', true));
            return;
        }

        // ulož soubor
        $cfg = require __DIR__ . '/../common/config.php';
        $backup_dir = $cfg['DIR_BACKUPS'];
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0700, true);
        }

        $filename = $backup_dir . '/' . $cl_pub_key . '_' . $backup_dt . '.bin';

        if (!atomic_write($filename, $blob)) {
            encrypt_res(api_error_response(18, 'Storage error', true));
            return;
        }

        // ponech jen poslední 3 zálohy
        $files = glob($backup_dir . '/' . $cl_pub_key . '_*.bin');
        if ($files && count($files) > 3) {
            sort($files); // timestamp v názvu = přirozené řazení
            foreach (array_slice($files, 0, count($files) - 3) as $f) {
                unlink($f);
            }
        }

        encrypt_res(err_OK(true));
    } catch (Exception $exc) {
        addtolog("upload Exception", $exc);
        encrypt_res(err_exception($exc,true));
//echo $exc->getTraceAsString();
    }
}

function SEC_listbackups($json) {
    global $cl_pub_key;

    $cfg = require __DIR__ . '/../common/config.php';
    $backup_dir = $cfg['DIR_BACKUPS'];

    // Zálohy tohoto nodu jsou pojmenovány {cl_pub_key}_{backup_dt}.bin
    $pattern = $backup_dir . '/' . $cl_pub_key . '_*.bin';
    $files = glob($pattern);

    $backups = [];

    if ($files) {
        foreach ($files as $filepath) {
            $basename = basename($filepath);

            // Parsuj backup_dt z názvu souboru: {cl_pub_key}_{backup_dt}.bin
            $prefix = $cl_pub_key . '_';
            if (str_starts_with($basename, $prefix)) {
                $rest = substr($basename, strlen($prefix));          // "1714000000.bin"
                $backup_dt = (int) pathinfo($rest, PATHINFO_FILENAME);   // 1714000000
            } else {
                $backup_dt = 0;
            }

            $backups[] = [
                'backup_dt' => $backup_dt,
                'date_utc' => gmdate('Y-m-d H:i:s', $backup_dt),
                'size' => filesize($filepath),
                'filename' => $basename,
            ];
        }

        // Seřaď od nejnovější
        usort($backups, fn($a, $b) => $b['backup_dt'] - $a['backup_dt']);
    }

    $json_res = new stdClass();
    $json_res->error = false;
    $json_res->code = 0;
    $json_res->message = 'OK';
    $json_res->backups = $backups;

    encrypt_res($json_res);
}

/**
 * Vrátí obsah zálohy.
 *
 * Request:  { "cmd": "downloadbackup", "time": ..., "backup_dt": 1714000000 }
 *           backup_dt = 0 nebo chybí → vrátí nejnovější zálohu
 *
 * Response: {
 *   "error": false, "code": 0,
 *   "backup_dt": 1714000000,
 *   "date_utc":  "2024-04-25 10:00:00",
 *   "size":      102400,
 *   "checksum":  "base64url_sha256",
 *   "data":      "base64url_blob"
 * }
 */
function SEC_downloadbackup($json) {
    global $cl_pub_key;

    $cfg = require __DIR__ . '/../common/config.php';
    $backup_dir = $cfg['DIR_BACKUPS'];
    $backup_dt = (int) ($json['backup_dt'] ?? 0);

    if ($backup_dt > 0) {
        // Konkrétní záloha podle backup_dt
        $filepath = $backup_dir . '/' . $cl_pub_key . '_' . $backup_dt . '.bin';

        if (!file_exists($filepath)) {
            encrypt_res(api_error_response(19, 'Backup not found.', true));
            return;
        }
    } else {
        // Nejnovější záloha — najdi soubor s nejvyšším timestamp v názvu
        $pattern = $backup_dir . '/' . $cl_pub_key . '_*.bin';
        $files = glob($pattern);

        if (!$files) {
            encrypt_res(api_error_response(19, 'No backups found.', true));
            return;
        }

        // Seřaď sestupně podle backup_dt v názvu souboru
        usort($files, function ($a, $b) {
            $dt_a = (int) pathinfo(basename($a), PATHINFO_FILENAME);
            $dt_b = (int) pathinfo(basename($b), PATHINFO_FILENAME);
            return $dt_b - $dt_a;
        });

        $filepath = $files[0];
        $basename = basename($filepath);
        $prefix = $cl_pub_key . '_';
        $rest = substr($basename, strlen($prefix));
        $backup_dt = (int) pathinfo($rest, PATHINFO_FILENAME);
    }

    $blob = file_get_contents($filepath);

    if ($blob === false) {
        encrypt_res(api_error_response(18, 'Storage error.', true));
        return;
    }

    $json_res = new stdClass();
    $json_res->error = false;
    $json_res->code = 0;
    $json_res->message = 'OK';
    $json_res->backup_dt = $backup_dt;
    $json_res->date_utc = gmdate('Y-m-d H:i:s', $backup_dt);
    $json_res->size = strlen($blob);
    $json_res->checksum = b64e(hash('sha256', $blob, true));
    $json_res->data = b64e($blob);

    encrypt_res($json_res);
}

function API_secure() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_correct_time($data['time'] ?? 0)) {
        err_badTime();
        return;
    }
    global $sid;
    $sid = $data['sid'];

    $GLOBALS["DB"]->query("DELETE FROM used_nonces " .
            "WHERE created_at < NOW() - INTERVAL 300 SECOND;");

    $GLOBALS["DB"]->query("INSERT IGNORE INTO used_nonces (sid, nonce) VALUES (?,?)", [$sid, b64d($data['nonce'])]);

    if ($GLOBALS["DB"]->affectedRows() === 0) {
        http_response_code(409);
        exit;
    }



    $table = $GLOBALS["DB"]->query("SELECT u.idx as user_idx, UNIX_TIMESTAMP(u.terms_valid_until) as terms_valid_until, u.terms_hash, s.pub_key, s.decrypt_key, s.encrypt_key, s.expiration FROM sessions s " .
            "LEFT JOIN users u ON s.pub_key = u.pub_key " .
            "WHERE s.sid = ? AND s.expiration > utc_timestamp() ", [$sid]);

    /* $table = $GLOBALS["DB"]->query("SELECT user_idx, pub_key, decrypt_key, encrypt_key, expiration FROM sessions WHERE sid = ? AND expiration > utc_timestamp();",
      [$sid]); */

    if ($table == false) {
        err_sessionExpired();
        return;
    }
                 
                    //aktualizace posledního přihlášení
    $GLOBALS["DB"]->query("UPDATE users SET lastlogin = utc_timestamp() WHERE pub_key = ? ",[$table[0]['pub_key']]);
    

    $ciphertext = b64d($data['ciphertext']);
    $nonce = b64d($data['nonce']);
    global $decrypt_key;
    global $encrypt_key;
    global $cl_pub_key;
    global $cl_idx;
    global $terms_valid_until;
    global $cl_terms_hash;
    global $client_v;
    $decrypt_key = $table[0]['decrypt_key'];
    $encrypt_key = $table[0]['encrypt_key'];
    $cl_pub_key = $table[0]['pub_key'];
    $cl_idx = $table[0]['user_idx'];
    $terms_valid_until = $table[0]['terms_valid_until'];
    $cl_terms_hash = $table[0]['terms_hash'];

    $PS = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $decrypt_key);
    if ($PS == false) {
        err_sessionExpired();
        return;
    }
    $client_v = $data['v'] ?? 0;
    if (!is_int($client_v)) {
      $client_v = 0;              
    }
    global $client_pub_key;
    $client_pub_key = $table[0]['pub_key'];
    $sec_json = json_decode($PS, true);
// echo($sec_json);
////echo($sec_json['cmd']);
//return;

    $cmd = $sec_json['cmd'];
    addtolog("secure", $cmd);
    switch ($cmd) {
        case 'transactions':
            SEC_transactions($sec_json);
            break;

        case 'addinvoice':
            SEC_addinvoice($sec_json);
            break;
        case 'payinvoice':
            SEC_payinvoice($sec_json);
            break;

        case 'getinfo':
        case 'getterms':
            SEC_getterms($sec_json);
            break;

        case 'signterms':
            SEC_signterms($sec_json);
            break;

        case 'uploadbackup':
            SEC_uploadbackup($sec_json);
            break;
        case 'listbackups':
            SEC_listbackups($sec_json);
            break;

        case 'downloadbackup':
            SEC_downloadbackup($sec_json);
            break;

        default:
            err_badRequest();
            break;
    }
    /* $sec_json = json_decode($PS);

      $cmd = $sec_json['cmd'];
      switch ($cmd) {
      case 'gettxs':
      SEC_gettxs($sec_json);
      break;
      case 'addinvoice':
      SEC_addinvoice();
      break;
      default:
      err_badRequest();
      break;
      }; */
}

try {
    $GLOBALS["DB"] = new Database();
} catch (Exception $exc) {
    err_starting();
    exit;
}
global $config;
$homeuri = trim(dirname($_SERVER['PHP_SELF']), '/');
//global $uri;

$uri = trim($_SERVER['REQUEST_URI'], '/');
$cmd = $uri;
if (strtoupper($homeuri) . '/' == (strtoupper(substr($uri, 0, strlen($homeuri) + 1)))) {
    $cmd = substr($uri, strlen($homeuri) + 1, 4096);
}

$config = loadconfig();
if (($config['maintenance'] ?? "0") == '1') {
    err_maintenance();
    exit;
}




if (!extension_loaded('sodium')) {
    http_response_code(500);
    die('libsodium not available');
}

//header("Content-Type: application/json");



addtolog($cmd, "");
switch ($cmd) {
    case 'status':
        API_status();
        break;
    case 'hello':
        API_hello();
        break;
    case 'secure':
        API_secure();
        break;
    case 'ping':
        $pubkey = getPublicKey();
        echo json_encode(['ok' => true, 'pub_key' => b64e($pubkey)]);
        break;        
    default:
        err_badRequest();
        break;
}
