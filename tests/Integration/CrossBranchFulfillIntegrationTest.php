<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CrossBranchFulfillIntegrationTest extends TestCase
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

    // Include branch_functions.php with a temporary chdir so its relative requires resolve
    $cwd = getcwd();
    chdir(__DIR__ . '/../../functions');
    require_once 'branch_functions.php';
    chdir($cwd);
    }

    protected function tearDown(): void
    {
        if ($this->conn) {
            $this->conn->rollBack();
            $this->conn = null;
            unset($GLOBALS['conn']);
        }
    }

    public function test_fulfill_cross_branch_request_creates_shift_and_coverage_and_updates_request(): void
    {
        // Seed branches
        $this->conn->exec("INSERT INTO branches (id, name, code, status, created_at) VALUES (501,'ReqBranch','RB','active',NOW()),(502,'TargetBranch','TB','active',NOW())");

        // Seed users (requester and approver/manager can be same for test); fulfilling user belongs to target branch
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, created_at, branch_id, email_verified) VALUES (601,'Requester','req@test','x','admin',NOW(),501,1)");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, created_at, branch_id, email_verified) VALUES (602,'Fulfiller','ful@test','x','user',NOW(),502,1)");
        $this->conn->exec("INSERT INTO users (id, username, email, password, role, created_at, branch_id, email_verified) VALUES (603,'Approver','app@test','x','admin',NOW(),501,1)");

        // Seed request: from requesting branch 501 to target 502
        $this->conn->exec("INSERT INTO cross_branch_shift_requests (id, requesting_branch_id, target_branch_id, shift_date, start_time, end_time, role_required, urgency_level, description, requested_by_user_id, status, created_at) VALUES (701,501,502, CURDATE(), '09:00:00','17:00:00','CSA','medium','Help needed',601,'pending',NOW())");

        // Call function under test
        $ok = fulfillCrossBranchRequest($this->conn, 701, 602, 603);
        $this->assertTrue($ok);

        // A shift should be created for fulfilling user at requesting branch
        $stmt = $this->conn->prepare("SELECT * FROM shifts WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([602]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($shift, 'Shift should be inserted');
        $this->assertSame('501', (string)$shift['branch_id']); // working at requesting branch
        $this->assertSame('Cross-branch coverage', $shift['location']);

        // Coverage record should exist and link to the shift and request
        $stmt = $this->conn->prepare("SELECT * FROM shift_coverage WHERE request_id = ? AND shift_id = ?");
        $stmt->execute([701, $shift['id']]);
        $cov = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($cov, 'Coverage record should be inserted');
        $this->assertSame('602', (string)$cov['covering_user_id']);
        $this->assertSame('502', (string)$cov['home_branch_id']);
        $this->assertSame('501', (string)$cov['working_branch_id']);
        $this->assertSame('603', (string)$cov['approved_by_user_id']);

        // Request should be updated to fulfilled
        $stmt = $this->conn->prepare("SELECT status, fulfilled_by_user_id FROM cross_branch_shift_requests WHERE id = ?");
        $stmt->execute([701]);
        [$status, $fulfilled_by] = $stmt->fetch(PDO::FETCH_NUM);
        $this->assertSame('fulfilled', $status);
        $this->assertSame('602', (string)$fulfilled_by);
    }
}
