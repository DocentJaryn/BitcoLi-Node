<?php

require_once __DIR__ . '/../common/bip39.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function infoAlert(): string {
    return '
    <div class="alert alert-success infoAlert">
        Changes have been saved.
    </div>

    <script>
        setTimeout(() => {
            document.querySelectorAll(".infoAlert").forEach(el => {
                el.style.transition = "opacity 0.5s ease";
                el.style.opacity = "0";

                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
    </script>';
}


function derive_node_id(string $seed): array {
    $context = "bnode:ed25519-identity:";
    $priv = hash_hmac('sha256', $seed, $context, true);

    $keypair = sodium_crypto_sign_seed_keypair($priv);

    $pub = sodium_crypto_sign_publickey($keypair);
    $privkey = sodium_crypto_sign_secretkey($keypair);

    return [
        'public' => $pub,
        'private' => $privkey
    ];
}

function save_seed(string $seed, string $mnemonic, array $keys): bool {
    global $seedFile, $mnemonicFile, $privKeyFile, $pubKeyFile;

    // Write non-marker files first; seedFile is the last marker
    // that index.php uses to decide whether identity exists.
    return atomic_write($privKeyFile, $keys['private']) && atomic_write($pubKeyFile, $keys['public']) && atomic_write($mnemonicFile, $mnemonic) && atomic_write($seedFile, $seed);
}
