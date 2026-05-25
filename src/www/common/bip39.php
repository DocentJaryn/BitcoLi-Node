<?php

class BIP39
{
    private array $wordlist;

    public function __construct()
    {
        $this->wordlist = file(__DIR__ . '/wordlist_en.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (count($this->wordlist) !== 2048) {
            throw new Exception("Wordlist mus� obsahovat 2048 slov.");
        }
    }

    public function generateMnemonic(int $entropyBits = 128): string
    {
        if (!in_array($entropyBits, [128, 192, 256])) {
            throw new Exception("Entropy mus� b�t 128, 192 nebo 256 bit.");
        }

        $entropy = random_bytes($entropyBits / 8);

        $entropyBitsStr = $this->bytesToBits($entropy);

        $hash = hash('sha256', $entropy, true);
        $hashBits = $this->bytesToBits($hash);

        $checksumLength = $entropyBits / 32;

        $bits = $entropyBitsStr . substr($hashBits, 0, $checksumLength);

        $chunks = str_split($bits, 11);

        $words = [];
        foreach ($chunks as $chunk) {
            $index = bindec($chunk);
            $words[] = $this->wordlist[$index];
        }

        return implode(' ', $words);
    }

    public function mnemonicToSeed(string $mnemonic, string $passphrase = ''): string
    {
        if (!$this->validateMnemonic($mnemonic)) {
            throw new Exception("Neplatn� BIP39 mnemotechnika.");
        }

        $salt = "mnemonic" . $passphrase;

        return hash_pbkdf2(
            "sha512",
            $mnemonic,
            $salt,
            2048,
            64,
            true
        );
    }

    private function validateMnemonic(string $mnemonic): bool
    {
        $words = preg_split('/\s+/', trim($mnemonic));

        if (!in_array(count($words), [12,15,18,21,24])) {
            return false;
        }

        $bits = "";

        foreach ($words as $word) {

            $index = array_search($word, $this->wordlist, true);

            if ($index === false) {
                return false;
            }

            $bits .= str_pad(decbin($index), 11, "0", STR_PAD_LEFT);
        }

        $totalBits = strlen($bits);

        $checksumLength = $totalBits / 33;
        $entropyLength = $totalBits - $checksumLength;

        $entropyBits = substr($bits, 0, $entropyLength);
        $checksumBits = substr($bits, $entropyLength);

        $entropy = $this->bitsToBytes($entropyBits);

        $hash = hash("sha256", $entropy, true);
        $hashBits = $this->bytesToBits($hash);

        return $checksumBits === substr($hashBits, 0, $checksumLength);
    }

    private function bytesToBits(string $bytes): string
    {
        $bits = '';

        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    private function bitsToBytes(string $bits): string
    {
        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}