<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ShiftSwapInvalidAcceptTest extends TestCase
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

    public function test_accepting_nonexistent_swap_is_noop(): void
    {
        $_SESSION = [ 'user_id' => 1801 ];
        $_POST = [ 'action' => 'accept', 'swap_id' => 999999 ];

        $cwd = getcwd();
        chdir(__DIR__ . '/../../functions');
        ob_start();
        @include 'shift_swap.php';
        $out = ob_get_clean();
        chdir($cwd);

        $this->assertSame('not_found', trim((string)$out));
    }
}
