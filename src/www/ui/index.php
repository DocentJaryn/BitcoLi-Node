<?php
require_once __DIR__ . '/../common/phpqrcode.php';
require_once __DIR__ . '/../common/myfc.php';
require_once __DIR__ . '/../common/constants.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . "/../common/database.php";

try {
    $GLOBALS["DB"] = new Database();
} catch (Throwable $e) {
    http_response_code(503);
    require __DIR__ . '/starting.php';
    exit;
}

$cfg = require __DIR__ . '/../common/config.php';
$configdb = loadconfig();

// Pokud probíhá migrace DB, zobraz informační stránku a počkej
if (($configdb['db_upgrading'] ?? '0') === '1') {
    http_response_code(503);
    require __DIR__ . '/upgrading.php';
    exit;
}

// Pokud verze DB již odpovídá očekávané verzi, vymaž případné staré varování
// (mohlo zůstat z předchozí obnovy, která byla mezitím migrována při restartu)
if (($configdb['restore_version_mismatch'] ?? '') !== ''
    && (int)($configdb['db_version'] ?? 0) === DB_VERSION) {
    writeconfig('restore_version_mismatch', '');
    $configdb = loadconfig();
}
$backup_dir_fb = $cfg['DIR_KEYS'] . '/backups';
$seedFile = $cfg['DIR_KEYS'] . "/seed.bin";
$mnemonicFile = $cfg['DIR_KEYS'] . "/mnemonic.txt";
$privKeyFile = $cfg['DIR_KEYS'] . "/privatekey.bin";
$pubKeyFile = $cfg['DIR_KEYS'] . "/publickey.bin";
$onion = $cfg['TOR_HOST'] ?? ""; 
if ($onion == "") {
  $onion = getOnionAddr($cfg['DIR_TOR'] . "/hostname");
}

$error = null;
$success = null;
$saveOK = ($_GET['saveok'] ?? "") == 1;


if (isset($_GET['backup_status'])) {
    header('Content-Type: application/json');

    $idx = (int) $_GET['backup_status'];
    $node = $GLOBALS["DB"]->query(
            "SELECT * FROM backup_nodes WHERE idx = ?", [$idx]
    );

    if (!$node) {
        echo json_encode(['error' => true, 'message' => 'Backup node not found.']);
        exit;
    }

    require_once __DIR__ . '/../common/backupdb.php';  // obsahuje getBackupList()

    $list = getBackupList($node[0]['url'], $node[0]['ed_pub']);

    if ($list === null) {
        echo json_encode(['error' => true, 'message' => 'Could not connect to backup node.']);
        exit;
    }

    echo json_encode(['error' => false, 'backups' => $list]);
    exit;
}

// ── GET ?users_page=N&users_search=… ─────────────────────────────────────────
if (isset($_GET['users_page'])) {
    header('Content-Type: application/json');

    $page    = max(1, (int) ($_GET['users_page'] ?? 1));
    $search  = trim($_GET['users_search'] ?? '');
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    $balanceExpr = "COALESCE((
        SELECT SUM(t.paid_msat - t.fee_msat)
        FROM transactions t
        WHERE t.user_idx = u.idx AND (t.payment_status >= 1)
    ), 0)";

    if ($search !== '') {
        $like  = '%' . $search . '%';
        $total = $GLOBALS["DB"]->query(
            "SELECT COUNT(*) AS cnt FROM users WHERE pub_key LIKE ?", [$like]
        )[0]['cnt'];
        $users = $GLOBALS["DB"]->query(
            "SELECT u.idx, u.pub_key, u.created, u.lastLogin, u.level,
                    u.terms_hash, u.terms_time, u.terms_valid_until,
                    g.name AS group_name,
                    $balanceExpr AS balance_msat
             FROM users u
             LEFT JOIN user_group g ON u.level = g.level
             WHERE u.pub_key LIKE ?
             ORDER BY u.idx DESC
             LIMIT ? OFFSET ?",
            [$like, $perPage, $offset]
        );
    } else {
        $total = $GLOBALS["DB"]->query("SELECT COUNT(*) AS cnt FROM users", [])[0]['cnt'];
        $users = $GLOBALS["DB"]->query(
            "SELECT u.idx, u.pub_key, u.created, u.lastLogin, u.level,
                    u.terms_hash, u.terms_time, u.terms_valid_until,
                    g.name AS group_name,
                    $balanceExpr AS balance_msat
             FROM users u
             LEFT JOIN user_group g ON u.level = g.level
             ORDER BY u.idx DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
    }

    $now = time();
    foreach ($users as &$u) {
        $u['terms_expired'] = $u['terms_valid_until'] !== null && strtotime($u['terms_valid_until']) < $now;
        $u['terms_due']     = $u['terms_valid_until'] !== null && !$u['terms_expired'];
    }
    unset($u);

    echo json_encode([
        'total' => (int) $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'users' => $users,
    ]);
    exit;
}

// ── GET ?user_detail=IDX ──────────────────────────────────────────────────────
if (isset($_GET['user_detail'])) {
    header('Content-Type: application/json');

    $idx  = (int) $_GET['user_detail'];
    $rows = $GLOBALS["DB"]->query(
        "SELECT u.*,
                g.name AS group_name,
                COALESCE((
                    SELECT SUM(t.paid_msat - t.fee_msat)
                    FROM transactions t
                    WHERE t.user_idx = u.idx AND t.payment_status >= 1
                ), 0) AS balance_msat
         FROM users u
         LEFT JOIN user_group g ON u.level = g.level
         WHERE u.idx = ?",
        [$idx]
    );

    if (!$rows) {
        echo json_encode(['error' => true, 'message' => 'User not found.']);
        exit;
    }

    $u   = $rows[0];
    $now = time();
    $u['terms_expired'] = $u['terms_valid_until'] !== null && strtotime($u['terms_valid_until']) < $now;
    $u['terms_due']     = $u['terms_valid_until'] !== null && !$u['terms_expired'];

    $txns = $GLOBALS["DB"]->query(
        "SELECT idx, created, settled, paid_msat, requested_msat, fee_msat, memo, payment_status
         FROM transactions
         WHERE user_idx = ?
         ORDER BY COALESCE(settled, created) DESC
         LIMIT 20",
        [$idx]
    );

    $groups = $GLOBALS["DB"]->query("SELECT level, name FROM user_group ORDER BY level", []);

    echo json_encode([
        'error'        => false,
        'user'         => $u,
        'transactions' => $txns,
        'groups'       => $groups,
    ]);
    exit;
}

// ── GET ?txns_page=N&txns_search=… ───────────────────────────────────────────
if (isset($_GET['txns_page'])) {
    header('Content-Type: application/json');

    $page    = max(1, (int) ($_GET['txns_page'] ?? 1));
    $search  = trim($_GET['txns_search'] ?? '');
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $baseSelect = "SELECT t.idx, t.created, t.settled, t.paid_msat, t.requested_msat,
                          t.fee_msat, t.memo, t.payment_hash, t.payment_status,
                          u.pub_key
                   FROM transactions t
                   LEFT JOIN users u ON u.idx = t.user_idx";

    if ($search !== '') {
        $like  = '%' . $search . '%';
        $total = $GLOBALS["DB"]->query(
            "SELECT COUNT(*) AS cnt FROM transactions t
             WHERE t.memo LIKE ? OR t.payment_hash LIKE ? OR t.invoice LIKE ?",
            [$like, $like, $like]
        )[0]['cnt'];
        $txns = $GLOBALS["DB"]->query(
            "$baseSelect
             WHERE t.memo LIKE ? OR t.payment_hash LIKE ? OR t.invoice LIKE ?
             ORDER BY COALESCE(t.settled, t.created) DESC
             LIMIT ? OFFSET ?",
            [$like, $like, $like, $perPage, $offset]
        );
    } else {
        $total = $GLOBALS["DB"]->query("SELECT COUNT(*) AS cnt FROM transactions", [])[0]['cnt'];
        $txns  = $GLOBALS["DB"]->query(
            "$baseSelect
             ORDER BY COALESCE(t.settled, t.created) DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );
    }

    echo json_encode([
        'total' => (int) $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'txns'  => $txns,
    ]);
    exit;
}

// ── GET ?stats ────────────────────────────────────────────────────────────────
if (isset($_GET['stats'])) {
    header('Content-Type: application/json');

    $users_total = (int) $GLOBALS["DB"]->query(
        "SELECT COUNT(*) AS c FROM users", [])[0]['c'];
    $users_active_30d = (int) $GLOBALS["DB"]->query(
        "SELECT COUNT(*) AS c FROM users WHERE lastLogin >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [])[0]['c'];
    $users_terms_expired = (int) $GLOBALS["DB"]->query(
        "SELECT COUNT(*) AS c FROM users WHERE terms_valid_until IS NOT NULL AND terms_valid_until < NOW()", [])[0]['c'];
    $users_no_terms = (int) $GLOBALS["DB"]->query(
        "SELECT COUNT(*) AS c FROM users WHERE terms_hash IS NULL", [])[0]['c'];

    $balance_row = $GLOBALS["DB"]->query(
        "SELECT COALESCE(SUM(t.paid_msat - t.fee_msat), 0) AS total_msat
         FROM transactions t WHERE t.payment_status >= 1", [])[0];
    $total_balance_msat = (int) $balance_row['total_msat'];

    $tx_row = $GLOBALS["DB"]->query(
        "SELECT
            COUNT(*) AS total,
            SUM(payment_status = 2)        AS settled,
            SUM(payment_status = 1)        AS in_flight,
            SUM(payment_status = 0)        AS issued,
            SUM(payment_status IS NULL)    AS failed,
            COALESCE(SUM(CASE WHEN payment_status = 2 THEN paid_msat ELSE 0 END), 0) AS volume_msat,
            COALESCE(SUM(CASE WHEN payment_status >= 1 THEN fee_msat ELSE 0 END), 0) AS fees_msat
         FROM transactions", [])[0];

    $tx_30d = $GLOBALS["DB"]->query(
        "SELECT COUNT(*) AS total, SUM(payment_status = 2) AS settled
         FROM transactions
         WHERE COALESCE(settled, created) >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [])[0];

    $groups = $GLOBALS["DB"]->query(
        "SELECT g.name, g.level, g.max_balance_sat, g.fee_ppm,
                COUNT(u.idx) AS user_count,
                COALESCE(SUM(
                    (SELECT SUM(t.paid_msat - t.fee_msat)
                     FROM transactions t
                     WHERE t.user_idx = u.idx AND t.payment_status >= 1)
                ), 0) AS balance_msat
         FROM user_group g
         LEFT JOIN users u ON u.level = g.level
         GROUP BY g.level
         ORDER BY g.level", []
    );

    $top_users = $GLOBALS["DB"]->query(
        "SELECT u.pub_key,
                COALESCE(SUM(t.paid_msat - t.fee_msat), 0) AS balance_msat
         FROM users u
         LEFT JOIN transactions t ON t.user_idx = u.idx AND t.payment_status >= 1
         GROUP BY u.idx
         ORDER BY balance_msat DESC
         LIMIT 5", []
    );

    echo json_encode([
        'users'        => [
            'total'         => $users_total,
            'active_30d'    => $users_active_30d,
            'terms_expired' => $users_terms_expired,
            'no_terms'      => $users_no_terms,
        ],
        'balance'      => ['total_msat' => $total_balance_msat],
        'transactions' => [
            'total'       => (int) $tx_row['total'],
            'settled'     => (int) $tx_row['settled'],
            'in_flight'   => (int) $tx_row['in_flight'],
            'issued'      => (int) $tx_row['issued'],
            'failed'      => (int) $tx_row['failed'],
            'volume_msat' => (int) $tx_row['volume_msat'],
            'fees_msat'   => (int) $tx_row['fees_msat'],
        ],
        'last_30d'     => [
            'txns_total'   => (int) $tx_30d['total'],
            'txns_settled' => (int) $tx_30d['settled'],
        ],
        'groups'    => $groups,
        'top_users' => $top_users,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid CSRF token. Reload the page and try again.';
    } else {
        $bip = new BIP39();
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case "cancel_restore":
                    writeconfig('maintenance', '0');
                    writeconfig('restore_db',  '0');
                    unset($_SESSION['restore']);
                    header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
                    exit;

                case "toggle_maintenance":
                    $current = $configdb['maintenance'] ?? '0';
                    $new_val = $current === '1' ? '0' : '1';
                    writeconfig('maintenance', $new_val);
                    if ($new_val === '0') {
                        // Čistíme pomocné flagy z restore procesu
                        writeconfig('restore_missing_tables',   '');
                        writeconfig('restore_version_mismatch', '');
                    }
                    header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
                    exit;

                case "edit_nodename":
                    writeconfig("node_name", $_POST['node_name']);
                    $success = true;
                    break;
                case "edit_clearnet":
                    $clearnet = trim($_POST['clearnet_addr'] ?? '');
                    if ($clearnet === '') {
                        writeconfig('clearnet_addr', '');   // prázdné = pouze Tor
                        $success = true;
                        break;
                    }
                    // Pokud uživatel nezadal žádné schéma, doplň http://
                    if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $clearnet)) {
                        $clearnet = 'http://' . $clearnet;
                    }
                    // Povolené je pouze http:// a https://, jiné protokoly odmítni
                    $scheme = strtolower((string) parse_url($clearnet, PHP_URL_SCHEME));
                    if ($scheme !== 'http' && $scheme !== 'https') {
                        $error = 'Only http:// and https:// are allowed for the clearnet address.';
                        break;
                    }
                    writeconfig('clearnet_addr', $clearnet);
                    $success = true;
                    break;
                case "edit_invitation":
                    $GLOBALS["DB"]->query("UPDATE invitations SET level=?, id=?, description=?,cnt=? Where idx=?", [$_POST['level'], $_POST['id'], $_POST['description'], $_POST['cnt'], $_POST['idx']]);
                    $success = true;
                    break;
                case "add_invitation":
                    $GLOBALS["DB"]->query("INSERT INTO invitations (level, id, description, cnt, used, created) VALUES (?, random_string(15), ?, ?, '0', now());", [$_POST['level'], $_POST['description'], $_POST['cnt']]);
                    $success = true;
                    break;

                case "edit_user_group":
                    $max = $_POST['max_balance_sat'] ?? null;
                    $fee = $_POST['fee_ppm'] ?? null;
                    $deadline = $_POST['sign_deadline_days'] ?? null;

                    if (!(ctype_digit($max) && ctype_digit($fee) && ctype_digit($deadline) &&
                            (int) $max >= 0 && (int) $max <= 1000000 &&
                            (int) $fee >= 0 && (int) $fee <= 1000 &&
                            (int) $deadline >= 0 && (int) $deadline <= 36)) {
                        $error = 'Invalid value';
                    } else {
                        $usergroup_t = $GLOBALS["DB"]->query("SELECT * FROM user_group where level = ?", [$_POST['level']]);
                        if ($usergroup_t) {
                            $GLOBALS["DB"]->query("UPDATE user_group SET max_balance_sat=?, fee_ppm=?, name=? Where level=?", [$max, $fee, $_POST['name'], $_POST['level']]);
                            if (($usergroup_t[0]['max_balance_sat'] != $max) ||
                                    ($usergroup_t[0]['fee_ppm'] != $fee)) {
//               $error = 'TODO: Update terms';
                                $hash = get_terms_hash($max, $fee);
                                $GLOBALS["DB"]->query("UPDATE users SET terms_valid_until=ADDDATE(now(), INTERVAL ? DAY) Where level=? and ((terms_hash <> ?) or (terms_hash is null))", [(int) $deadline, $_POST['level'], $hash]);
                                $GLOBALS["DB"]->query("UPDATE users SET terms_valid_until=null Where level=? and terms_hash = ?", [$_POST['level'], $hash]);
                            }
                            $success = true;
                            header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?saveusergroup=1#usergroup-' . $_POST['level']);
                            exit;
                        } else {
                            $error = 'Record not found.';
                        }
                    }
                    /*           $error = $_POST['max_balance_sat'].' '.$_POST['fee_ppm'].' '.$_POST['name'].' '.$_POST['level']; */
                    break;

                case "add_backup_node":
                    $GLOBALS["DB"]->query(
                            "INSERT INTO backup_nodes (url, ed_pub, description, pswd, created) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())",
                            [
                                rtrim($_POST['url'], '/'),
                                $_POST['ed_pub'],
                                $_POST['description'],
                                $_POST['pswd'] ?? "",
                            ]
                    );
                    $success = true;
                    break;

                case "edit_backup_node":
                    $GLOBALS["DB"]->query(
                            "UPDATE backup_nodes SET url=?, ed_pub=?, description=?, pswd=? WHERE idx=?",
                            [
                                rtrim($_POST['url'], '/'),
                                $_POST['ed_pub'],
                                $_POST['description'],
                                $_POST['pswd'],
                                $_POST['idx'],
                            ]
                    );
                    $success = true;
                    break;

                case "delete_backup_node":
                    $GLOBALS["DB"]->query(
                            "DELETE FROM backup_nodes WHERE idx=?",
                            [$_POST['delete_backup_node']]
                    );
                    $success = true;
                    break;
                case "edit_backuppswd" :
                    writeconfig("backup_pswd", $_POST['backuppswd'] ?? "");
                    $success = true;
                    break;
                case "run_backup":
                    writeconfig("backup_idxs", 'backupnow');
                    header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?runbackup=1#backup');
                    exit;
                    break;
                case "add_terms_lang":
                case "edit_terms_lang": {
                        $lang = strtolower(trim($_POST['lang'] ?? ''));
                        $text = $_POST['text'] ?? '';

                        if ($lang === '' || !preg_match('/^[a-z]{2,2}$/', $lang)) {
                            $error = "Invalid language code. Use 2 lowercase letters (e.g. 'en', 'cs').";
                            break;
                        }

                        // Načti aktuální terms
                        $terms_raw = $configdb['terms'] ?? '{}';
                        $terms_json = json_decode($terms_raw, true) ?? [];

                        // Nastav nebo přepiš jazyk
                        $terms_json['lng'][$lang] = $text;
                        writeconfig('terms', json_encode($terms_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        if (($_POST['require_sign'] ?? 0) == 1) {
                            //Je vyžadován podpis nových podmínek
                            $deadline = $_POST['sign_deadline_days'] ?? 30;

                            $usergroup_t = $GLOBALS["DB"]->query("SELECT * FROM user_group", []);
                            for ($i = 0; $i < count($usergroup_t); $i++) {
                                $hash = get_terms_hash($usergroup_t[$i]['max_balance_sat'], $usergroup_t[$i]['fee_ppm']);
                                $GLOBALS["DB"]->query("UPDATE users SET terms_valid_until=ADDDATE(now(), INTERVAL ? DAY) Where level=? and ((terms_hash <> ?) or (terms_hash is null))", [(int) $deadline, $usergroup_t[$i]['level'], $hash]);
                                $GLOBALS["DB"]->query("UPDATE users SET terms_valid_until=null Where level=? and terms_hash = ?", [$usergroup_t[$i]['level'], $hash]);
                            }
                        }
                        $configdb = loadconfig(); // obnov lokální cache
                        $success = true;
                        header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?saveterms=1#terms-' . $lang);
                        exit;
                        break;
                    }

                case "edit_user": {
                        header('Content-Type: application/json');

                        $idx      = (int) ($_POST['idx'] ?? 0);
                        $level    = (int) ($_POST['level'] ?? 0);
                        $deadline = (int) ($_POST['sign_deadline_days'] ?? 30);

                        if ($deadline < 1 || $deadline > 365) {
                            echo json_encode(['ok' => false, 'error' => 'Invalid deadline (1–365 days).']);
                            exit;
                        }

                        $current = $GLOBALS["DB"]->query(
                            "SELECT level FROM users WHERE idx = ?", [$idx]
                        );
                        if (!$current) {
                            echo json_encode(['ok' => false, 'error' => 'User not found.']);
                            exit;
                        }

                        $GLOBALS["DB"]->query(
                            "UPDATE users SET level = ? WHERE idx = ?", [$level, $idx]
                        );

                        if ($current[0]['level'] != $level) {
                            // Skupina se změnila — načti terms hash nové skupiny
                            $grp = $GLOBALS["DB"]->query(
                                "SELECT max_balance_sat, fee_ppm FROM user_group WHERE level = ?", [$level]
                            );
                            if ($grp) {
                                $hash = get_terms_hash($grp[0]['max_balance_sat'], $grp[0]['fee_ppm']);
                                // Uživatelé s jiným (nebo žádným) hash musí podepsat do $deadline dní
                                $GLOBALS["DB"]->query(
                                    "UPDATE users
                                     SET terms_valid_until = ADDDATE(NOW(), INTERVAL ? DAY)
                                     WHERE idx = ? AND (terms_hash <> ? OR terms_hash IS NULL)",
                                    [$deadline, $idx, $hash]
                                );
                                // Pokud hash souhlasí, deadline se zruší
                                $GLOBALS["DB"]->query(
                                    "UPDATE users SET terms_valid_until = NULL
                                     WHERE idx = ? AND terms_hash = ?",
                                    [$idx, $hash]
                                );
                            }
                        }

                        echo json_encode(['ok' => true]);
                        exit;
                    }

                case "delete_terms_lang": {
                        $lang = strtolower(trim($_POST['lang'] ?? ''));

                        if ($lang === '') {
                            $error = "Missing language code.";
                            break;
                        }

                        $terms_raw = $configdb['terms'] ?? '{}';
                        $terms_json = json_decode($terms_raw, true) ?? [];

                        if (!isset($terms_json['lng'][$lang])) {
                            $error = "Language '$lang' not found.";
                            break;
                        }

                        unset($terms_json['lng'][$lang]);

                        writeconfig('terms', json_encode($terms_json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $configdb = loadconfig();
                        $success = true;
                        header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?saveterms=1#terms-');
                        exit;

                        break;
                    }
            }
        } else {
            if (isset($_POST['gen'])) {
                // ── Nová peněženka ────────────────────────────────────────────
                $mnemonic = $bip->generateMnemonic(128);
                $seed     = $bip->mnemonicToSeed($mnemonic);
                $keys     = derive_node_id($seed);
                if (save_seed($seed, $mnemonic, $keys)) {
                    $success = true;
                } else {
                    $error = "Failed to write seed to disk.";
                }

            } elseif (isset($_POST['restore_seed'])) {
                // ── Krok 1 → 2: Validuj seed, zapiš na disk, přejdi na krok 2 ──
                $mnemonic = trim($_POST['mnemonic'] ?? '');
                if ($mnemonic === '') {
                    $error = "Please enter your BIP39 recovery phrase.";
                } else {
                    try {
                        $seed = $bip->mnemonicToSeed($mnemonic);
                        $keys = derive_node_id($seed);
                        if (!save_seed($seed, $mnemonic, $keys)) {
                            throw new Exception("Failed to write seed to disk.");
                        }
                        // Nastav flagy — uzel je v nedokončeném stavu
                        writeconfig('restore_db',  '1');
                        writeconfig('maintenance', '1');
                        $_SESSION['restore'] = ['mnemonic' => $mnemonic];
                        header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?restore_step=2');
                        exit;
                    } catch (Exception $e) {
                        $error = "Invalid mnemonic: " . $e->getMessage();
                    }
                }

            } elseif (isset($_POST['restore_find'])) {
                // ── Krok 2 → 3: Prohledej zálohovací servery ─────────────────
                $urls_raw    = trim($_POST['backup_urls'] ?? '');
                $backup_urls = array_values(array_filter(
                    array_map('trim', preg_split('/[\n,]+/', $urls_raw)),
                    fn($u) => $u !== ''
                ));

                require_once __DIR__ . '/../common/backupdb.php';
                $all_backups = [];
                $failed_urls = [];

                foreach ($backup_urls as $url) {
                    $list = getBackupList($url, '', 0);
                    if ($list === null) {
                        $failed_urls[] = $url;
                    } else {
                        foreach ($list as $b) {
                            $b['_server_url'] = $url;
                            $all_backups[] = $b;
                        }
                    }
                }
                usort($all_backups, fn($a, $b) => $b['backup_dt'] - $a['backup_dt']);

                $_SESSION['restore'] = array_merge($_SESSION['restore'] ?? [], [
                    'backup_urls' => $backup_urls,
                    'backups'     => $all_backups,
                    'failed_urls' => $failed_urls,
                ]);

                if (empty($all_backups)) {
                    // Žádná záloha nenalezena — zůstaň na kroku 2 s chybou
                    $error = empty($failed_urls)
                        ? "No backups found on the specified servers."
                        : "Could not reach: " . implode(', ', $failed_urls) . ". No backups found.";
                    // Zůstaneme na kroku 2 — přesměrování bez redirect, $error se zobrazí
                    $_GET['restore_step'] = 2;
                } else {
                    header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?restore_step=3');
                    exit;
                }

            } elseif (isset($_POST['restore_skip_db'])) {
                // ── Krok 2: Přeskočit obnovu DB ──────────────────────────────
                writeconfig('restore_db',  '0');
                // maintenance necháme — uživatel ho zruší sám po kontrole
                unset($_SESSION['restore']);
                header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
                exit;

            } elseif (isset($_POST['restore_execute'])) {
                // ── Krok 3: Stáhni, dekomprimuj, importuj, přepiš DB ─────────
                $ctx = $_SESSION['restore'] ?? null;
                $backup_dt  = (int) ($_POST['backup_dt']          ?? 0);
                $backup_url = trim($_POST['backup_server_url'] ?? '');

                if (!$ctx || $backup_dt === 0 || $backup_url === '') {
                    $error = "Invalid selection. Please go back and try again.";
                    $_GET['restore_step'] = 3;
                } else {
                    require_once __DIR__ . '/../common/backupdb.php';

                    $tmp_archive = sys_get_temp_dir() . '/bitcoli_restore_' . time() . '.7z';
                    $tmp_sql     = sys_get_temp_dir() . '/bitcoli_restore_' . time() . '.sql';
                    $tmp_db      = 'bitcoli_restore_tmp_' . time();
                    $zip_pswd    = 'tajneheslo';

                    do {
                        $err = downloadBackup($backup_url, '', 0, $tmp_archive, $backup_dt);
                        if ($err !== '') { $error = "Download failed: $err"; break; }

                        exec("7za e -so -p" . escapeshellarg($zip_pswd)
                            . " " . escapeshellarg($tmp_archive) . " backup.sql"
                            . " > " . escapeshellarg($tmp_sql) . " 2>&1",
                            $out_ex, $rc_ex);
                        if ($rc_ex !== 0) {
                            $error = "Decompression failed (exit $rc_ex): " . implode("\n", $out_ex);
                            break;
                        }

                        $h = escapeshellarg($cfg['MYSQL_HOST']);
                        $u = escapeshellarg($cfg['MYSQL_USER']);
                        $p = escapeshellarg($cfg['MYSQL_PASSWORD']);

                        exec("mysql --skip-ssl -h$h -u$u -p$p -e "
                            . escapeshellarg("CREATE DATABASE IF NOT EXISTS `$tmp_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"),
                            $out_cr, $rc_cr);
                        if ($rc_cr !== 0) { $error = "Could not create temporary database."; break; }

                        exec("mysql --skip-ssl -h$h -u$u -p$p " . escapeshellarg($tmp_db)
                            . " < " . escapeshellarg($tmp_sql) . " 2>&1",
                            $out_im, $rc_im);
                        if ($rc_im !== 0) {
                            $error = "Import failed: " . implode("\n", $out_im);
                            exec("mysql --skip-ssl -h$h -u$u -p$p -e " . escapeshellarg("DROP DATABASE IF EXISTS `$tmp_db`;"));
                            break;
                        }

                        // config je kritická — bez ní nelze zapsat flagy po obnově
                        $required       = TABLES_TO_BACKUP;

                        // Načti seznam VŠECH tabulek ze zálohy
                        $tmp_tables = array_column(
                            $GLOBALS["DB"]->query(
                                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
                                [$tmp_db]
                            ),
                            'TABLE_NAME'
                        );

                        // Chybějící požadované tabulky
                        $missing_tables = array_values(array_diff($required, $tmp_tables));

                        if (in_array('config', $missing_tables)) {
                            $error = "Restored database is missing the 'config' table. Cannot continue.";
                            exec("mysql --skip-ssl -h$h -u$u -p$p -e " . escapeshellarg("DROP DATABASE IF EXISTS `$tmp_db`;"));
                            break;
                        }

                        if ($missing_tables) {
                            addtolog("restore", "WARNING: missing tables in backup: " . implode(', ', $missing_tables));
                        }

                        // ── Per-table RENAME ──────────────────────────────────
                        // Jen tabulky které jsou v záloze se přesunou do prod.
                        // Tabulky které v záloze nejsou (applog, …) zůstanou
                        // v prod beze změny.
                        // Přesunuté původní tabulky se zálohovají do _old DB.

                        $prod_db     = $cfg['MYSQL_DBNAME'];
                        $old_db      = $prod_db . '_old_' . time();
                        $errors_swap = [];

                        exec("mysql --skip-ssl -h$h -u$u -p$p -e "
                            . escapeshellarg("CREATE DATABASE IF NOT EXISTS `$old_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"),
                            $out_old, $rc_old);
                        if ($rc_old !== 0) {
                            $error = "Could not create backup database '$old_db'.";
                            exec("mysql --skip-ssl -h$h -u$u -p$p -e " . escapeshellarg("DROP DATABASE IF EXISTS `$tmp_db`;"));
                            break;
                        }

                        foreach ($tmp_tables as $tbl) {
                            // Existuje tabulka v prod? → přesuň do _old
                            $prod_has = $GLOBALS["DB"]->query(
                                "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?",
                                [$prod_db, $tbl]
                            );

                            $sql = "SET FOREIGN_KEY_CHECKS=0; ";
                            if ((int)($prod_has[0]['c'] ?? 0) > 0) {
                                $sql .= "RENAME TABLE `$prod_db`.`$tbl` TO `$old_db`.`$tbl`; ";
                            }
                            $sql .= "RENAME TABLE `$tmp_db`.`$tbl` TO `$prod_db`.`$tbl`; ";
                            $sql .= "SET FOREIGN_KEY_CHECKS=1;";

                            exec("mysql --skip-ssl -h$h -u$u -p$p -e " . escapeshellarg($sql) . " 2>&1",
                                $out_rn, $rc_rn);
                            if ($rc_rn !== 0) {
                                $errors_swap[] = "Table '$tbl': " . implode(" ", $out_rn);
                            }
                        }

                        exec("mysql --skip-ssl -h$h -u$u -p$p -e " . escapeshellarg("DROP DATABASE IF EXISTS `$tmp_db`;"));

                        if ($errors_swap) {
                            $error = "Restore partially failed:\n" . implode("\n", $errors_swap)
                                   . "\nOriginal tables preserved in '$old_db'.";
                            break;
                        }

                        addtolog("restore", "DB swap OK: prod=$prod_db, old=$old_db, backup_dt=$backup_dt");

                        // Po swapu je v config hodnotě zálohy — přepis flagů do nové DB
                        // (writeconfig() zapisuje do aktuálního připojení které ještě ukazuje
                        //  na prod_db, ale RENAME TABLE tam již přesunul nové tabulky)
                        writeconfig('maintenance', '1');  // zůstane zapnuto — uživatel ho zruší po kontrole
                        writeconfig('restore_db',  '0');
                        if ($missing_tables) {
                            writeconfig('restore_missing_tables', implode(', ', $missing_tables));
                        } else {
                            writeconfig('restore_missing_tables', '');
                        }

                        // Zkontroluj verzi DB v záloze oproti aktuální verzi aplikace
                        $restored_configdb = loadconfig();
                        $restored_db_ver   = (int) ($restored_configdb['db_version'] ?? 0);
                        $app_db_ver        = DB_VERSION;
                        if ($restored_db_ver !== $app_db_ver) {
                            writeconfig('restore_version_mismatch',
                                "Backup DB version: $restored_db_ver, application expects: $app_db_ver"
                            );
                            addtolog("restore", "WARNING: DB version mismatch — backup=$restored_db_ver, app=$app_db_ver");
                        } else {
                            writeconfig('restore_version_mismatch', '');
                        }

                        unset($_SESSION['restore']);
                        addtolog("restore", "DB restore complete backup_dt=$backup_dt url=$backup_url");
                        $success = true;

                    } while (false);

                    @unlink($tmp_archive);
                    @unlink($tmp_sql);

                    if ($error) {
                        // Zůstaň na kroku 3
                        $_SESSION['restore'] = $ctx;
                        $_GET['restore_step'] = 3;
                    }
                }

            }
        }

        if ($success) {
            header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?saveok=1#');
            exit;
        }
    }
}


$configdb       = loadconfig();   // reload — POST mohl změnit restore_db / maintenance
$invitations_t  = $GLOBALS["DB"]->query("SELECT * FROM invitations", []);
$usergroup_t    = $GLOBALS["DB"]->query("SELECT * FROM user_group", []);
$backup_nodes_t = $GLOBALS["DB"]->query("SELECT * FROM backup_nodes ORDER BY idx", []);
?><!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BitcoLi_v2 Node</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: #0d1117;
                color: #c9d1d9;
                min-height: 100vh;
                display: flex;
                justify-content: center;
                padding: 2rem 1rem;
            }

            .container {
                max-width: 640px;
                width: 100%;
            }

            h1 {
                color: #f7931a;
                font-size: 1.8rem;
                margin-bottom: 1.5rem;
                padding-bottom: 0.75rem;
                border-bottom: 1px solid #21262d;
            }

            .card {
                background: #161b22;
                border: 1px solid #30363d;
                border-radius: 8px;
                padding: 1.25rem;
                margin-bottom: 1rem;
            }

            .card h2 {
                color: #e6edf3;
                font-size: 1.1rem;
                margin-bottom: 1rem;
            }

            .card h3 {
                color: #e6edf3;
                font-size: 0.95rem;
                margin-bottom: 0.5rem;
            }

            label {
                display: block;
                color: #8b949e;
                font-size: 0.85rem;
                margin-bottom: 0.25rem;
            }

            code, .mono {
                font-family: "SF Mono", "Fira Code", "Fira Mono", Menlo, monospace;
                background: #0d1117;
                border: 1px solid #30363d;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;
                display: block;
                word-break: break-all;
                font-size: 0.8rem;
                color: #7ee787;
                margin-bottom: 0.75rem;
            }

            /*code.editable {
                color: #7ee787;
                border: 1px solid #7ee787;
            /*    color: #f7931a;
                border: 1px solid #f7931a;*/
            /*    background: #161b22;
            } */
            textarea {
                width: 100%;
                background: #0d1117;
                border: 1px solid #30363d;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;
                color: #c9d1d9;
                font-family: "SF Mono", "Fira Code", Menlo, monospace;
                font-size: 0.85rem;
                resize: vertical;
            }

            textarea:focus {
                outline: none;
                border-color: #f7931a;
            }

            textarea[readonly] {
                color: #8b949e;
            }

            .qr-wrap {
                text-align: center;
                margin-top: 1rem;
            }

            .qr-wrap img {
                border-radius: 6px;
                background: #fff;
                padding: 8px;
            }

            .btn {
                display: inline-block;
                background: #f7931a;
                color: #0d1117;
                border: none;
                border-radius: 6px;
                padding: 0.6rem 1.25rem;
                font-size: 0.9rem;
                font-weight: 600;
                cursor: pointer;
                margin-top: 0.5rem;
            }

            .btn:hover {
                background: #e8850f;
            }

            .btn-secondary {
                background: #21262d;
                color: #c9d1d9;
                border: 1px solid #30363d;
            }

            .btn-secondary:hover {
                background: #30363d;
            }

            .separator {
                border: none;
                border-top: 1px solid #21262d;
                margin: 0.75rem 0;
            }

            .alert {
                padding: 0.75rem 1rem;
                border-radius: 6px;
                margin-bottom: 1rem;
                font-size: 0.9rem;
            }

            .alert-error {
                background: #3d1117;
                border: 1px solid #f85149;
                color: #f85149;
            }

            .alert-success {
                background: #0f2a1f;
                border: 1px solid #3fb950;
                color: #3fb950;
            }
            .alert-muted {
                color: #8b949e;
            }

            .status-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 0.4rem;
                vertical-align: middle;
            }

            .status-dot.green {
                background: #3fb950;
            }
            .status-dot.yellow {
                background: #d29922;
            }

            .alert-warning {
                background: #3d2e00;
                border: 1px solid #d29922;
                color: #d29922;
            }

            .danger-zone {
                border-color: #f85149;
            }

            .danger-zone h2 {
                color: #f85149;
            }

            .btn-danger {
                background: #da3633;
                color: #fff;
            }

            .btn-danger:hover {
                background: #b62324;
            }

            details summary {
                cursor: pointer;
                color: #8b949e;
                font-size: 0.9rem;
                padding: 0.5rem 0;
                user-select: none;
            }

            details summary:hover {
                color: #c9d1d9;
            }

            details[open] summary {
                margin-bottom: 0.75rem;
            }

            .confirm-input {
                width: 100%;
                background: #0d1117;
                border: 1px solid #30363d;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;
                color: #c9d1d9;
                font-size: 0.85rem;
                margin-bottom: 0.5rem;
            }

            .confirm-input:focus {
                outline: none;
                border-color: #f85149;
            }


            select.mono {
                width: 100%;
                background: #0d1117;
                /*    border: 1px solid #30363d;*/
                border: 1px solid #7ee787;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;
                color: #7ee787;
                font-family: "SF Mono", "Fira Code", "Fira Mono", Menlo, monospace;
                font-size: 0.85rem;
            }

            select.mono:focus {
                outline: none;
                border-color: #f7931a;
            }

            input {
                width: 100%;
                background: #0d1117;
                border: 1px solid #7ee787;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;

                color: #7ee787;
                font-family: "SF Mono", "Fira Code", "Fira Mono", Menlo, monospace;
                font-size: 0.85rem;

                outline: none;
                transition: border-color 0.15s ease;

                margin-bottom: 0.75rem;
            }

            /* focus stav */
            input:focus {
                border-color: #f7931a;
            }

            .mono-input {
                width: 100%;
                background: #0d1117;
                border: 1px solid #7ee787;
                border-radius: 4px;
                padding: 0.5rem 0.75rem;
                color: #7ee787;
                font-family: "SF Mono", "Fira Code", "Fira Mono", Menlo, monospace;
                font-size: 0.85rem;
                outline: none;
                transition: border-color 0.15s ease;
                margin-bottom: 0.75rem;
            }

            .mono-input:focus {
                border-color: #f7931a;
            }

        </style>
    </head>
    <body>
        <div class="container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($saveOK): echo infoAlert();
            endif;
            ?>
            <h1>BitcoLi_v2 Node</h1>

            <?php
            $in_maintenance = ($configdb['maintenance'] ?? '0') === '1';
            $in_restore_db  = ($configdb['restore_db']  ?? '0') === '1';
            $has_seed       = file_exists($seedFile);
            ?>

            <?php if ($in_maintenance && $has_seed && !$in_restore_db): ?>
                <!-- ── Maintenance banner ── -->
                <div class="alert" style="background:#1a1200; border-color:#d29922; color:#d29922;
                                          margin-bottom:1rem; font-size:0.85rem;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                        <span>
                            🔧 <strong>Maintenance mode is active.</strong>
                            The node is not accepting new payments. Please check your data and disable maintenance when ready.
                        </span>
                        <form method="post" style="margin:0; flex-shrink:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_maintenance">
                            <button class="btn btn-secondary" style="margin:0; white-space:nowrap;">
                                ✓ Disable maintenance
                            </button>
                        </form>
                    </div>
                    <?php
                    $missing = trim($configdb['restore_missing_tables'] ?? '');
                    if ($missing !== ''):
                    ?>
                        <hr style="border-color:#d29922; opacity:0.3; margin:0.6rem 0;">
                        <div style="font-size:0.82rem;">
                            ⚠ The following tables were not found in the backup and were not restored:
                            <code style="display:inline; background:none; border:none; padding:0;
                                         color:#d29922; font-size:0.82rem;"><?= htmlspecialchars($missing) ?></code><br>
                            These tables will be created automatically by the application on next update or restart.
                        </div>
                    <?php endif; ?>

                    <?php
                    $ver_mismatch = trim($configdb['restore_version_mismatch'] ?? '');
                    if ($ver_mismatch !== ''):
                    ?>
                        <hr style="border-color:#f85149; opacity:0.3; margin:0.6rem 0;">
                        <div style="font-size:0.82rem; color:#f85149;">
                            ⚠ <strong>Database version mismatch</strong> — <?= htmlspecialchars($ver_mismatch) ?>.<br>
                            Please restart the application so it can upgrade the database schema to the current version.
                            Do not disable maintenance mode until the restart is complete.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($has_seed && !$in_restore_db): ?>
                <?php require __DIR__ . '/identity.php'; ?>
                <?php require __DIR__ . '/show_seed.php'; ?>
                <?php require __DIR__ . '/user_group.php'; ?>
                <?php require __DIR__ . '/terms.php'; ?>
                <?php require __DIR__ . '/invitations.php'; ?>
                <?php require __DIR__ . '/backup.php'; ?>
                <?php require __DIR__ . '/statistics.php'; ?>
                <?php require __DIR__ . '/users.php'; ?>
                <?php require __DIR__ . '/transactions.php'; ?>
            <?php else: ?>
                <?php require __DIR__ . '/seed_form.php'; ?>
            <?php endif; ?>

        </div>
    </body>
</html>
<script>
    var hash = window.location.hash;
//if (hash && hash.startsWith('#terms-')) {
    var el = document.querySelector(hash);
    if (el) {
        // Otevři i všechny nadřazené <details>
        var parent = el;
        while (parent) {
            if (parent.tagName === 'DETAILS')
                parent.open = true;
            parent = parent.parentElement;
        }
        el.scrollIntoView({behavior: 'smooth', block: 'start'});
//    }
    }
</script>
