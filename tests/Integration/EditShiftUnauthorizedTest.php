<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EditShiftUnauthorizedTest extends TestCase
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

    public function test_user_cannot_edit_someone_elses_shift(): void
    {
        // Seed two users and a role; create a shift for user B
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1701,'userA','a@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1702,'userB','b@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2701,1702,'CSA',12.5,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO shifts (id, user_id, role_id, shift_date, start_time, end_time, location) VALUES (3701,1702,2701, CURDATE(),'09:00:00','17:00:00','LocB', NULL)");

        // Act as user A (not admin) attempting to edit user B's shift
        $_SESSION = [ 'user_id' => 1701, 'role' => 'user', 'username' => 'userA' ];
        $_POST = [
            'shift_id' => 3701,
            'shift_date' => date('Y-m-d', strtotime('+1 day')),
            'start_time' => '10:00:00',
            'end_time' => '18:00:00',
            'location' => 'New',
            'role_id' => 2701,
        ];

        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'edit_shift.php';
        chdir($cwd);

        // Verify shift unchanged
        $row = $this->conn->query("SELECT location, start_time, end_time FROM shifts WHERE id=3701")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('LocB', $row['location']);
        $this->assertSame('09:00:00', $row['start_time']);
        $this->assertSame('17:00:00', $row['end_time']);

        // Error message set in session
        $this->assertArrayHasKey('error_message', $_SESSION);
        $this->assertStringContainsString('not authorized', strtolower($_SESSION['error_message'] ?? ''));
    }
}
