<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ShiftInvitationIntegrationTest extends TestCase
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

    public function test_accept_targeted_invitation_inserts_shift(): void
    {
        // Seed user and role
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1101,'userA','a@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2101,1101,'CSA',12.50,'hourly',0,NOW())");

        // Seed targeted invitation for that user
        $this->conn->exec("INSERT INTO shift_invitations (id, shift_date, start_time, end_time, role_id, location, admin_id, user_id, status, created_at) VALUES (3101, CURDATE(), '09:00:00','17:00:00',2101,'Main',1101,1101,'pending',NOW())");

        $_SESSION = [ 'user_id' => 1101 ];
        $_POST = [ 'invitation_id' => 3101, 'action' => 'accept' ];

        // Include script
        $includeFile = function(string $file) { @include $file; };
        $includeFile(__DIR__ . '/../../functions/shift_invitation.php');

        // Assert a shift was added for user 1101
        $stmt = $this->conn->query("SELECT * FROM shifts WHERE user_id=1101 ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('2101', (string)$row['role_id']);
        $this->assertSame('Main', $row['location']);
    }

    public function test_decline_targeted_invitation_marks_declined_and_notification_read(): void
    {
        // Seed user, role, targeted invitation and a notification
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1201,'userB','b@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2201,1201,'CSA',12.50,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO shift_invitations (id, shift_date, start_time, end_time, role_id, location, admin_id, user_id, status, created_at) VALUES (3201, CURDATE(), '10:00:00','14:00:00',2201,'Site',1201,1201,'pending',NOW())");
        $this->conn->exec("INSERT INTO notifications (id, user_id, type, message, is_read, created_at) VALUES (4201,1201,'shift-invite','New invite',0,NOW())");

        $_SESSION = [ 'user_id' => 1201 ];
        $_POST = [ 'invitation_id' => 3201, 'action' => 'decline', 'notif_id' => 4201 ];

        $includeFile = function(string $file) { @include $file; };
        $includeFile(__DIR__ . '/../../functions/shift_invitation.php');

        // Invitation should be marked declined
        $stmt = $this->conn->query("SELECT status FROM shift_invitations WHERE id=3201");
        $status = $stmt->fetchColumn();
        $this->assertSame('declined', $status);

        // Notification should be marked read
        $stmt = $this->conn->query("SELECT is_read FROM notifications WHERE id=4201");
        $isRead = $stmt->fetchColumn();
        $this->assertEquals(1, (int)$isRead);
    }
}
