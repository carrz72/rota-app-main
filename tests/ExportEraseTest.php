<?php
use PHPUnit\Framework\TestCase;

class ExportEraseTest extends TestCase
{
    protected $pdo;

    protected function setUp(): void
    {
        // Use in-memory SQLite for tests
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $GLOBALS['conn'] = $this->pdo;

        // Create minimal schema
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, role TEXT, password TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
        $this->pdo->exec("CREATE TABLE login_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, ip_address TEXT, user_agent TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");
        $this->pdo->exec("CREATE TABLE user_sessions (session_id TEXT PRIMARY KEY, user_id INTEGER, ip_address TEXT, user_agent TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP);");
        $this->pdo->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, meta TEXT, ip_address TEXT, user_agent TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);");

        // Insert a test user
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, role, password) VALUES (?, ?, ?, ?)");
        $stmt->execute(['tester', 'tester@example.com', 'user', password_hash('pass1234', PASSWORD_DEFAULT)]);
        $userId = $this->pdo->lastInsertId();

        // Start session and set user
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = 'tester';
        $_SESSION['role'] = 'user';

        // Add current session to user_sessions
        $sid = session_id();
        $stmt = $this->pdo->prepare("INSERT INTO user_sessions (session_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sid, $userId, '127.0.0.1', 'phpunit']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['conn']);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testExportDataProducesJson()
    {
        // Generate a CSRF token
        require_once __DIR__ . '/../includes/csrf.php';
        $token = generate_csrf_token();

        // POST emulation
        $_POST = ['csrf_token' => $token];

        // Capture output
        ob_start();
        require __DIR__ . '/../functions/export_data.php';
        $output = ob_get_clean();

        $this->assertNotEmpty($output, 'Export output should not be empty');
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('user', $data);
        $this->assertEquals('tester', $data['user']['username']);

        // Ensure audit entry created
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'export_data'");
        $count = $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testEraseAccountRemovesUser()
    {
        // Generate a CSRF token
        require_once __DIR__ . '/../includes/csrf.php';
        $token = generate_csrf_token();

        $_POST = ['csrf_token' => $token, 'confirm' => 'ERASE'];

        ob_start();
        require __DIR__ . '/../functions/erase_account.php';
        $output = ob_get_clean();

        $this->assertStringContainsString('erased', strtolower($output));

        // User should be gone
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        $count = $stmt->fetchColumn();
        $this->assertEquals(0, $count, 'User should be deleted');

        // Ensure audit entry created
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'erase_account'");
        $count = $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
