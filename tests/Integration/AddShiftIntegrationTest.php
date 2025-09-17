<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AddShiftIntegrationTest extends TestCase
{
    private ?PDO $conn = null;

    protected function setUp(): void
    {
        // Only run if .env.test is configured and DB_NAME contains 'test'
        $host = $_ENV['DB_HOST'] ?? null;
        $db = $_ENV['DB_NAME'] ?? null;
        $user = $_ENV['DB_USER'] ?? null;
        $pass = $_ENV['DB_PASS'] ?? null;
        if (!$db || stripos($db, 'test') === false) {
            $this->markTestSkipped('Test DB not configured (DB_NAME must contain "test").');
        }
        $this->conn = new PDO("mysql:host={$host};dbname={$db};charset=utf8", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // Start transaction to auto-rollback changes
        $this->conn->beginTransaction();
        // Share $conn globally like app code expects
        $GLOBALS['conn'] = $this->conn;
    }

    protected function tearDown(): void
    {
        if ($this->conn) {
            $this->conn->rollBack();
            $this->conn = null;
            unset($GLOBALS['conn']);
        }
    }

    public function test_admin_can_create_shift_for_user(): void
    {
        // Arrange minimal fixtures: a user, a role
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1001,'u1','u1@example.test','x','admin',1,NOW())");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1002,'u2','u2@example.test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2001,'CSA',12.5,'hourly',0,NOW())");

        // Fake session as admin
        $_SESSION = [
            'user_id' => 1001,
            'role' => 'admin',
            'username' => 'Admin User',
        ];

        // POST data
        $_POST = [
            'admin_mode' => '1',
            'user_id' => 1002,
            'shift_date' => date('Y-m-d', strtotime('+1 day')),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'role_id' => 2001,
            'location' => 'Main Floor',
            'return_url' => '../admin/manage_shifts.php',
        ];

    // Include target script with temp chdir so its relatives resolve
    $cwd = getcwd();
    chdir(__DIR__ . '/../../functions');
    @include 'add_shift.php';
    chdir($cwd);

        // Assert shift inserted
        $stmt = $this->conn->query("SELECT * FROM shifts WHERE user_id = 1002 ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Shift row should exist');
        $this->assertSame('2001', (string)$row['role_id']);
        $this->assertSame('Main Floor', $row['location']);

    // We can't reliably capture header() without extensions; trust successful insert as pass.
    }
}
