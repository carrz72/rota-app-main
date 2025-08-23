<?php
// Asymmetric signing helper using OpenSSL
// Private key should be stored out-of-repo in `/secure/signing_private.pem` when possible,
// or provided via env ROTA_APP_SIGNING_KEY. Public key may live in `/secure/signing_public.pem`
// or `config/signing_public.pem` for compatibility.

function get_secure_private_path()
{
    return __DIR__ . '/../secure/signing_private.pem';
}

function get_secure_public_path()
{
    return __DIR__ . '/../secure/signing_public.pem';
}

function load_private_key_pem()
{
    // 1) Environment override (useful for container / secrets store)
    if (!empty($_ENV['ROTA_APP_SIGNING_KEY'])) {
        return $_ENV['ROTA_APP_SIGNING_KEY'];
    }

    // 2) Prefer secure path outside of repo
    $secure = get_secure_private_path();
    if (file_exists($secure)) {
        return trim(@file_get_contents($secure));
    }

    // 3) Fallback to old config path
    $file = __DIR__ . '/../config/signing_private.pem';
    if (file_exists($file)) {
        return trim(@file_get_contents($file));
    }

    return null;
}

function load_public_key_pem()
{
    if (!empty($_ENV['ROTA_APP_SIGNING_PUB'])) {
        return $_ENV['ROTA_APP_SIGNING_PUB'];
    }

    // Prefer secure public key
    $securePub = get_secure_public_path();
    if (file_exists($securePub)) {
        return trim(@file_get_contents($securePub));
    }

    // Fallback to config path
    $file = __DIR__ . '/../config/signing_public.pem';
    if (file_exists($file)) {
        return trim(@file_get_contents($file));
    }
    return null;
}

function sign_payload($payload)
{
    $pem = load_private_key_pem();
    if (empty($pem)) throw new Exception('Signing private key not configured');
    $priv = openssl_pkey_get_private($pem);
    if ($priv === false) throw new Exception('Invalid private key');
    $data = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = '';
    $ok = openssl_sign($data, $sig, $priv, OPENSSL_ALGO_SHA256);
    openssl_pkey_free($priv);
    if (!$ok) throw new Exception('Signing failed');
    return base64_encode($sig);
}

function verify_signature($payload, $b64sig)
{
    $pem = load_public_key_pem();
    if (empty($pem)) throw new Exception('Signing public key not configured');
    $pub = openssl_pkey_get_public($pem);
    if ($pub === false) throw new Exception('Invalid public key');
    $data = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = base64_decode($b64sig, true);
    $res = openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256);
    openssl_pkey_free($pub);
    return $res === 1;
}

function public_key_fingerprint()
{
    $pem = load_public_key_pem();
    if (empty($pem)) return null;
    // normalize and compute sha256 of key
    $clean = preg_replace('/\s+/', '', $pem);
    return hash('sha256', $clean);
}
