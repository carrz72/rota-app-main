<?php
// Simple encryption helper using openssl AES-256-GCM

function get_encryption_key()
{
    // Prefer environment variable ROTA_APP_ENC_KEY, then fallback to a file in config/enc_key
    if (!empty($_ENV['ROTA_APP_ENC_KEY'])) return $_ENV['ROTA_APP_ENC_KEY'];
    $keyFile = __DIR__ . '/../config/enc_key';
    if (file_exists($keyFile)) {
        $k = trim(@file_get_contents($keyFile));
        if ($k !== '') {
            // If file contains base64, decode it; if result is not 32 bytes, derive a 32-byte key
            if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $k) && strlen($k) % 4 === 0) {
                $decoded = base64_decode($k, true);
                if ($decoded !== false) {
                    $k = $decoded;
                }
            }
            if (strlen($k) !== 32) {
                // Derive a 32-byte binary key from provided material
                $k = hash('sha256', $k, true);
            }
            return $k;
        }
    }
    return null;
}

function encrypt_blob($plaintext)
{
    $key = get_encryption_key();
    if (empty($key)) throw new Exception('Encryption key not configured');
    $method = 'aes-256-gcm';
    $ivlen = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($ivlen);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new Exception('Encryption failed');
    // store mac/tag alongside
    return ['algorithm' => $method, 'iv' => $iv, 'mac' => $tag, 'data' => $cipher];
}

function decrypt_blob($record)
{
    $key = get_encryption_key();
    if (empty($key)) throw new Exception('Encryption key not configured');
    $method = $record['algorithm'];
    $iv = $record['iv'];
    $tag = $record['mac'];
    $cipher = $record['data'];
    $plain = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new Exception('Decryption failed');
    return $plain;
}
