<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BroadcastDeclineIntegrationTest extends TestCase
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

    public function test_decline_broadcast_invitation_records_decline_but_does_not_mark_notification_read(): void
    {
        // A broadcast invitation has user_id NULL
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, email_verified, created_at) VALUES (1501,'user','u@test','x','user',1,NOW())");
        $this->conn->exec("INSERT INTO roles (id, user_id, name, base_pay, employment_type, has_night_pay, created_at) VALUES (2501,1501,'CSA',12.5,'hourly',0,NOW())");
        $this->conn->exec("INSERT INTO shift_invitations (id, shift_date, start_time, end_time, role_id, location, admin_id, user_id, status, created_at) VALUES (3501, CURDATE(),'09:00:00','17:00:00',2501,'Main',1501,NULL,'pending',NOW())");
        $this->conn->exec("INSERT INTO notifications (id, user_id, type, message, is_read, created_at) VALUES (4501,1501,'shift-invite','Broadcast invite',0,NOW())");

        $_SESSION = [ 'user_id' => 1501 ];
        $_POST = [ 'invitation_id' => 3501, 'action' => 'decline', 'notif_id' => 4501 ];

        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        @include 'shift_invitation.php';
        chdir($cwd);

        $stmt = $this->conn->query("SELECT COUNT(*) FROM decline_responses WHERE invitation_id=3501 AND user_id=1501");
        $this->assertSame('1', (string)$stmt->fetchColumn());

        $stmt = $this->conn->query("SELECT is_read FROM notifications WHERE id=4501");
        $this->assertSame('0', (string)$stmt->fetchColumn(), 'Broadcast decline should not mark notification as read');
    }
}
