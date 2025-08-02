<?php
// Helper to calculate estimated pay for a coverage request (not yet a shift)
require_once __DIR__ . '/calculate_pay.php';
function calculateCoverageRequestPay($conn, $request)
{
    // $request must have: role_id, start_time, end_time
    if (empty($request['role_id']) || empty($request['start_time']) || empty($request['end_time'])) {
        return 0;
    }
    return calculateInvitationPay($conn, $request);
}
