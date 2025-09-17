<?php
// CLI helper to generate an RSA signing keypair into the secure/ directory.
// Usage: php tools/generate_signing_keys.php [--force]
// This intentionally writes only to the local filesystem. Keep the private key secure and out of git.

$force = in_array('--force', $argv, true);
$secureDir = __DIR__ . '/../secure';
if (!is_dir($secureDir)) {
    if (!mkdir($secureDir, 0700, true)) {
        fwrite(STDERR, "Failed to create secure directory: $secureDir\n");
        exit(2);
    }
}
$privPath = $secureDir . '/signing_private.pem';
$pubPath = $secureDir . '/signing_public.pem';

if ((file_exists($privPath) || file_exists($pubPath)) && !$force) {
    fwrite(STDOUT, "Key files already exist in $secureDir. Pass --force to overwrite.\n");
    exit(0);
}

$config = [
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$res = openssl_pkey_new($config);
if ($res === false) {
    fwrite(STDERR, "Failed to generate keypair: " . openssl_error_string() . "\n");
    exit(2);
}

$priv = '';
if (!openssl_pkey_export($res, $priv)) {
    fwrite(STDERR, "Failed to export private key: " . openssl_error_string() . "\n");
    exit(2);
}
$details = openssl_pkey_get_details($res);
$pub = $details['key'] ?? null;
if (empty($pub)) {
    fwrite(STDERR, "Failed to extract public key.\n");
    exit(2);
}

if (file_put_contents($privPath, $priv) === false) {
    fwrite(STDERR, "Failed to write private key to $privPath\n");
    exit(2);
}
if (file_put_contents($pubPath, $pub) === false) {
    fwrite(STDERR, "Failed to write public key to $pubPath\n");
    exit(2);
}
@chmod($privPath, 0600);
@chmod($pubPath, 0644);

fwrite(STDOUT, "Generated RSA keypair:\n");
fwrite(STDOUT, " - private: $privPath\n");
fwrite(STDOUT, " - public:  $pubPath\n");

fwrite(STDOUT, "Note: The private key is written to the workspace secure/ directory. Add /secure/ to your .gitignore and move the private key to a secure secret store for production.\n");
