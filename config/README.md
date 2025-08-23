This folder may contain runtime secrets for local development only.

To enable encrypted archive operations, create a file `enc_key` containing a 32-byte key (base64 or raw). Alternatively set environment variable `ROTA_APP_ENC_KEY`.

IMPORTANT: Do not commit the `enc_key` file to git. Add it to .gitignore.

Generate a random 32-byte key (PowerShell):

```powershell
$bytes = New-Object 'Byte[]' 32; (New-Object System.Security.Cryptography.RNGCryptoServiceProvider).GetBytes($bytes); [Convert]::ToBase64String($bytes) | Out-File -Encoding ascii enc_key
```

Or with PHP (CLI):

```php
php -r "file_put_contents('enc_key', base64_encode(random_bytes(32)) . PHP_EOL);"
```
