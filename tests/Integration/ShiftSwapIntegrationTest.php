<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ShiftSwapIntegrationTest extends TestCase
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

    public function test_swap_propose_and_accept_reassigns_shifts(): void
    {
        // Seed two users, roles, and shifts
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1601,'u1','u1@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1602,'u2','u2@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2601,1601,'CSA',12.5,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2602,1602,'CSA',12.5,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO shifts (id, user_id, role_id, shift_date, start_time, end_time, location) VALUES (3601,1601,2601, CURDATE(),'09:00:00','17:00:00','Loc1', NULL)");
        $this->conn->exec("INSERT INTO shifts (id, user_id, role_id, shift_date, start_time, end_time, location) VALUES (3602,1602,2602, CURDATE(),'10:00:00','18:00:00','Loc2', NULL)");

        // Propose by user 1601
        $_SESSION = [ 'user_id' => 1601 ];
        $_POST = [ 'action' => 'propose', 'to_user_id' => 1602, 'from_shift_id' => 3601, 'to_shift_id' => 3602 ];
        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'shift_swap.php';
        chdir($cwd);

        // Find swap id
        $swapId = (int)$this->conn->query("SELECT id FROM shift_swaps ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $swapId);

        // Accept by user 1602
        $_SESSION = [ 'user_id' => 1602 ];
        $_POST = [ 'action' => 'accept', 'swap_id' => $swapId ];
        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'shift_swap.php';
        chdir($cwd);

        $this->assertSame('1602', (string)$this->conn->query("SELECT user_id FROM shifts WHERE id=3601")->fetchColumn());
        $this->assertSame('1601', (string)$this->conn->query("SELECT user_id FROM shifts WHERE id=3602")->fetchColumn());

        // Swap status updated
        $this->assertSame('accepted', $this->conn->query("SELECT status FROM shift_swaps WHERE id={$swapId}")->fetchColumn());
    }
}
