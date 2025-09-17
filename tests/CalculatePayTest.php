<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../functions/calculate_pay.php';
require_once __DIR__ . '/Support/FakePDO.php';

final class CalculatePayTest extends TestCase
{
    public function test_calculateInvitationPay_day_rate(): void
    {
        $pdo = new FakePDO();
        $pdo->setRole(10, [
            'base_pay' => 12.5,
            'has_night_pay' => 0,
            'night_shift_pay' => null,
            'night_start_time' => '22:00:00',
            'night_end_time' => '06:00:00',
        ]);

        $invitation = [
            'role_id' => 10,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ];

        $pay = calculateInvitationPay($pdo, $invitation);
        $this->assertEqualsWithDelta(8 * 12.5, $pay, 0.001);
    }

    public function test_calculateInvitationPay_night_rate(): void
    {
        $pdo = new FakePDO();
        $pdo->setRole(11, [
            'base_pay' => 10.0,
            'has_night_pay' => 1,
            'night_shift_pay' => 15.0,
            'night_start_time' => '22:00:00',
            'night_end_time' => '06:00:00',
        ]);

        // Starts at 23:00, within night hours
        $invitation = [
            'role_id' => 11,
            'start_time' => '23:00:00',
            'end_time' => '02:00:00', // crosses midnight
        ];

        $pay = calculateInvitationPay($pdo, $invitation);
        $this->assertEqualsWithDelta(3 * 15.0, $pay, 0.001);
    }

    public function test_calculatePay_shift_hourly_accumulation(): void
    {
        $pdo = new FakePDO();
        $pdo->setShiftRow(100, [
            'start_time' => '21:00:00',
            'end_time' => '01:00:00', // crosses midnight
            'base_pay' => 12.0,
            'has_night_pay' => 1,
            'night_shift_pay' => 18.0,
            'night_start_time' => '22:00:00',
            'night_end_time' => '06:00:00',
        ]);

        $total = calculatePay($pdo, 100);
        // 21-22 at base (12), 22-01 three hours at night (3 * 18)
        $this->assertEquals(12 + 54, $total);
    }
}
