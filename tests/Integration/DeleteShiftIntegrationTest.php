<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DeleteShiftIntegrationTest extends TestCase
{
    private ?PDO $conn = null;

    protected function setUp(): void
    {
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
        $this->conn->beginTransaction();
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

    public function test_user_deletes_own_shift(): void
    {
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1401,'user','u@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2401,1401,'CSA',12.5,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO shifts (id, user_id, role_id, shift_date, start_time, end_time, location) VALUES (3401,1401,2401,CURDATE(),'09:00:00','17:00:00','Loc', NULL)");

        $_SESSION = [ 'user_id' => 1401 ];
        $_POST = [ 'shift_id' => 3401 ];

        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'delete_shift.php';
        chdir($cwd);

        $stmt = $this->conn->query("SELECT COUNT(*) FROM shifts WHERE id=3401");
        $this->assertSame('0', (string)$stmt->fetchColumn());

        $stmt = $this->conn->query("SELECT COUNT(*) FROM notifications WHERE user_id=1401 AND type='success' AND message LIKE 'Shift deleted%'");
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }
}
