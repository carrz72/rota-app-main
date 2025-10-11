<?php
function calculateInvitationPay($conn, $invitation) {
    // Retrieve role details based on role_id in the invitation.
    $stmt = $conn->prepare("SELECT base_pay, has_night_pay, night_shift_pay, night_start_time, night_end_time FROM roles WHERE id = ?");
    $stmt->execute([$invitation['role_id']]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$role) {
        return 0;
    }
    
    // Convert times to timestamps.
    $start_time = strtotime($invitation['start_time']);
    $end_time = strtotime($invitation['end_time']);
    if ($end_time < $start_time) {
        $end_time += 86400; // if the shift ends after midnight, add 24 hours
    }
    
    // Calculate the duration in hours.
    $hours = ($end_time - $start_time) / 3600;
    
    // Determine the appropriate hourly rate.
    $hourly_rate = $role['base_pay'];
    if ($role['has_night_pay']) {
        $night_start = strtotime($role['night_start_time']);
        $night_end = strtotime($role['night_end_time']);
        if ($night_end < $night_start) {
            $night_end += 86400;
        }
        
        // For simplicity, if the shift starts during the night period, use the night rate.
        if ($start_time >= $night_start && $start_time < $night_end) {
            $hourly_rate = $role['night_shift_pay'];
        }
    }
    
    return $hours * $hourly_rate;
}
function calculatePay($conn, $shift_id) {
    $sql = "SELECT s.start_time, s.end_time, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time
            FROM shifts s
            JOIN roles r ON s.role_id = r.id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        return 0; // No shift found, return 0
    }

    $start_time = strtotime($shift['start_time']);
    $end_time = strtotime($shift['end_time']);
    $base_pay = $shift['base_pay'];
    $total_pay = 0; // Initialize total pay

    // Adjust for shifts that span midnight
    if ($end_time < $start_time) {
        $end_time += 86400; // Add 24 hours to end time
    }

    while ($start_time < $end_time) {
        if ($shift['has_night_pay']) {
            $night_start = strtotime($shift['night_start_time']);
            $night_end = strtotime($shift['night_end_time']);

            // Adjust for night shift crossing midnight
            if ($night_end < $night_start) {
                $night_end += 86400;
            }

            // Determine if the current hour falls within the night shift period
            if (($start_time >= $night_start && $start_time < $night_end) || 
                ($start_time + 3600 > $night_start && $start_time + 3600 <= $night_end)) {
                $hourly_rate = $shift['night_shift_pay'];
            } else {
                $hourly_rate = $base_pay;
            }
        } else {
            $hourly_rate = $base_pay;
        }

        $total_pay += $hourly_rate;
        $start_time += 3600;
    }

    return $total_pay;
}

function displayEstimatedPay($conn, $shift_id) {
    $pay = calculatePay($conn, $shift_id);
    echo "Estimated pay for the shift: $" . number_format($pay, 2);
}
?>
