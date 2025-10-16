<?php
/**
 * VAPID Keys Generator
 * Run this ONCE to generate your push notification keys
 * Then store them securely and DELETE this file
 */

require_once 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

echo "===========================================\n";
echo "  VAPID KEYS GENERATOR FOR PUSH NOTIFICATIONS\n";
echo "===========================================\n\n";

try {
    // Try using library method first
    try {
        $keys = VAPID::createVapidKeys();
    } catch (Exception $e) {
        // Fallback: use pre-generated keys for development
        // In production, you should generate proper keys
        echo "⚠️  Using sample keys for development. Generate proper keys for production!\n\n";
        $keys = [
            'publicKey' => 'BMrynh06K7vNvRFfK9WHwJBpXmXSOj08-4T3FXdxGD2S3LrW0HHbxF0XtqOWwp3Vj3XLchLXvKJqS5K6kY6K-fU',
            'privateKey' => 'AOkC5qEPkLp9HEaOiPmYmEzYLAd8pLCWr6gQnL0D5YqM'
        ];
    }

    echo "✅ Keys ready!\n\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "PUBLIC KEY (use in JavaScript):\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo $keys['publicKey'] . "\n\n";

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "PRIVATE KEY (keep secret on server):\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo $keys['privateKey'] . "\n\n";

    echo "===========================================\n";
    echo "⚠️  IMPORTANT:\n";
    echo "===========================================\n";
    echo "1. Copy both keys NOW\n";
    echo "2. Store them in includes/push_config.php\n";
    echo "3. NEVER commit them to version control\n";
    echo "4. DELETE THIS FILE after use\n";
    echo "===========================================\n\n";

    // Optionally create the config file automatically
    $configContent = "<?php\n";
    $configContent .= "/**\n";
    $configContent .= " * Push Notifications Configuration\n";
    $configContent .= " * NEVER commit this file to version control!\n";
    $configContent .= " */\n\n";
    $configContent .= "// Public key (used in JavaScript - safe to expose)\n";
    $configContent .= "define('VAPID_PUBLIC_KEY', '{$keys['publicKey']}');\n\n";
    $configContent .= "// Private key (server-side only - keep secret!)\n";
    $configContent .= "define('VAPID_PRIVATE_KEY', '{$keys['privateKey']}');\n\n";
    $configContent .= "// Subject (your email or website URL)\n";
    $configContent .= "define('VAPID_SUBJECT', 'mailto:admin@openrota.com'); // Change this!\n";

    file_put_contents(__DIR__ . '/includes/push_config.php', $configContent);

    echo "✅ Config file created at: includes/push_config.php\n";
    echo "   Edit the VAPID_SUBJECT to match your email/URL\n\n";

} catch (Exception $e) {
    echo "❌ Error generating keys: " . $e->getMessage() . "\n";
    exit(1);
}
?>