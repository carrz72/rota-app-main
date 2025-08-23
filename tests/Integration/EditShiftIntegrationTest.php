<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EditShiftIntegrationTest extends TestCase
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

    public function test_admin_edits_another_users_shift_and_notifies(): void
    {
        // Seed users and role
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1301,'admin','a@test','x','admin',1,NOW())");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1302,'worker','w@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2301,1302,'CSA',12.50,'hourly',0,NOW())");

        // Existing shift for worker
        $this->conn->exec("INSERT INTO shifts (id, user_id, role_id, shift_date, start_time, end_time, location) VALUES (3301,1302,2301, CURDATE(), '09:00:00','17:00:00','Old', NULL)");

        // Act as admin
        $_SESSION = [ 'user_id' => 1301, 'role' => 'admin', 'username' => 'Admin' ];
        $_POST = [
            'admin_mode' => '1',
            'return_url' => '../admin/manage_shifts.php',
            'shift_id' => 3301,
            'shift_date' => date('Y-m-d', strtotime('+1 day')),
            'start_time' => '10:00:00',
            'end_time' => '18:00:00',
            'location' => 'NewLoc',
            'role_id' => 2301,
            'user_id' => 1302,
        ];

        // Include with a temp chdir so relative includes resolve
        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'edit_shift.php';
        chdir($cwd);

        $stmt = $this->conn->query("SELECT shift_date, start_time, end_time, location, role_id, user_id FROM shifts WHERE id=3301");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('NewLoc', $row['location']);
        $this->assertSame('2301', (string)$row['role_id']);
        $this->assertSame('1302', (string)$row['user_id']);

        // Notification created for the worker
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='shift_update'");
        $stmt->execute([1302]);
        $this->assertGreaterThan(0, (int)$stmt->fetchColumn());
    }
}
