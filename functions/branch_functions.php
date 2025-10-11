<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
// Notifications helper - include only if function doesn't exist to avoid redeclaration
if (!function_exists('addNotification')) {
    require_once __DIR__ . '/addNotification.php';
}

/**
 * Get all branches
 */
function getAllBranches($conn, $status = 'active')
{
    if ($status === 'all') {
        $sql = "SELECT * FROM branches ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
    } else {
        $sql = "SELECT * FROM branches WHERE status = ? ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$status]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get branch by ID
 */
function getBranchById($conn, $branch_id)
{
    $sql = "SELECT * FROM branches WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$branch_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get user's home branch
 */
function getUserHomeBranch($conn, $user_id)
{
    $sql = "SELECT b.* FROM branches b 
            JOIN users u ON b.id = u.branch_id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get branches user has permissions for
 */
function getUserPermittedBranches($conn, $user_id)
{
    $sql = "SELECT b.*, bp.permission_level 
            FROM branches b 
            LEFT JOIN branch_permissions bp ON b.id = bp.branch_id AND bp.user_id = ?
            WHERE b.status = 'active' 
            AND (bp.user_id IS NOT NULL OR b.id = (SELECT branch_id FROM users WHERE id = ?))
            ORDER BY b.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if user can access branch
 */
function canUserAccessBranch($conn, $user_id, $branch_id, $required_level = 'view')
{
    // Check if it's user's home branch
    $sql = "SELECT branch_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $user_branch = $stmt->fetchColumn();

    if ($user_branch == $branch_id) {
        return true; // Always can access home branch
    }

    // Check permissions
    $sql = "SELECT permission_level FROM branch_permissions 
            WHERE user_id = ? AND branch_id = ? 
            AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $branch_id]);
    $permission = $stmt->fetchColumn();

    if (!$permission) {
        return false;
    }

    // Check permission level hierarchy
    $levels = ['view' => 1, 'manage' => 2, 'admin' => 3];
    return $levels[$permission] >= $levels[$required_level];
}

/**
 * Create cross-branch shift request
 */
function createCrossBranchRequest($conn, $data)
{
    // ...debug output removed...

    $sql = "INSERT INTO cross_branch_shift_requests 
            (requesting_branch_id, target_branch_id, shift_date, start_time, end_time, 
             role_required, urgency_level, description, requested_by_user_id, expires_at, role_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $ok = $stmt->execute([
        $data['requesting_branch_id'],
        $data['target_branch_id'],
        $data['shift_date'],
        $data['start_time'],
        $data['end_time'],
        null, // role_required is not used in user UI, so set to NULL
        $data['urgency_level'],
        $data['description'],
        $data['requested_by_user_id'],
        $data['expires_at'],
        $data['role_id']
    ]);
}

/**
 * Get pending cross-branch requests for a branch
 */
function getPendingRequestsForBranch($conn, $branch_id)
{
    $sql = "SELECT cbr.*, 
                   rb.name as requesting_branch_name, rb.code as requesting_branch_code,
                   tb.name as target_branch_name, tb.code as target_branch_code,
                   u.username as requested_by_username
            FROM cross_branch_shift_requests cbr
            JOIN branches rb ON cbr.requesting_branch_id = rb.id
            JOIN branches tb ON cbr.target_branch_id = tb.id
            JOIN users u ON cbr.requested_by_user_id = u.id
            WHERE (cbr.requesting_branch_id = ? OR cbr.target_branch_id = ?)
            AND cbr.status = 'pending'
            AND (cbr.expires_at IS NULL OR cbr.expires_at > NOW())
            ORDER BY cbr.urgency_level DESC, cbr.created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$branch_id, $branch_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get available employees from target branch for a request
 */
function getAvailableEmployeesForRequest($conn, $request_id)
{
    $sql = "SELECT r.*, u.id as user_id, u.username, u.email, 
                   ur.name as user_role_name, ur.employment_type, ur.base_pay
            FROM cross_branch_shift_requests r
            JOIN users u ON u.branch_id = r.target_branch_id
            LEFT JOIN roles ur ON u.role_id = ur.id
            WHERE r.id = ?
            AND u.id NOT IN (
                SELECT s.user_id FROM shifts s 
                WHERE s.shift_date = r.shift_date 
                AND ((s.start_time <= r.end_time AND s.end_time >= r.start_time))
            )
            ORDER BY u.username";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fulfill cross-branch request
 */
function fulfillCrossBranchRequest($conn, $request_id, $fulfilling_user_id, $approving_user_id)
{
    try {
        $conn->beginTransaction();

        // Get request details
        $sql = "SELECT * FROM cross_branch_shift_requests WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Request not found");
        }

        // Create shift for the fulfilling user at the requesting branch (include role_id if present)
        $sql = "INSERT INTO shifts (user_id, shift_date, start_time, end_time, branch_id, location, role_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $fulfilling_user_id,
            $request['shift_date'],
            $request['start_time'],
            $request['end_time'],
            $request['requesting_branch_id'],
            'Cross-branch coverage',
            isset($request['role_id']) && $request['role_id'] ? $request['role_id'] : null
        ]);

        $shift_id = $conn->lastInsertId();

        // Create coverage record
        $sql = "INSERT INTO shift_coverage 
                (covering_user_id, home_branch_id, working_branch_id, shift_id, request_id, 
                 approved_by_user_id, approval_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'approved')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $fulfilling_user_id,
            $request['target_branch_id'],
            $request['requesting_branch_id'],
            $shift_id,
            $request_id,
            $approving_user_id
        ]);

        // Update request status
        $sql = "UPDATE cross_branch_shift_requests 
                SET status = 'fulfilled', fulfilled_by_user_id = ?, fulfilled_at = NOW() 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$fulfilling_user_id, $request_id]);

        // Close other pending sibling requests for the same shift to avoid multiple acceptances
        // Build a human-friendly note
        $fuStmt = $conn->prepare("SELECT username, branch_id FROM users WHERE id = ? LIMIT 1");
        $fuStmt->execute([$fulfilling_user_id]);
        $fu = $fuStmt->fetch(PDO::FETCH_ASSOC);
        $fulfillerName = $fu['username'] ?? 'A user';
        $fulfillerBranchName = null;
        if (!empty($fu['branch_id'])) {
            $bstmt = $conn->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
            $bstmt->execute([$fu['branch_id']]);
            $fulfillerBranchName = $bstmt->fetchColumn();
        }

        $noteParts = ["Fulfilled by {$fulfillerName}"];
        if ($fulfillerBranchName)
            $noteParts[] = "branch: {$fulfillerBranchName}";
        $note = implode(' ', $noteParts);

        $closeStmt = $conn->prepare(
            "UPDATE cross_branch_shift_requests
             SET status = 'declined', notes = ?, expires_at = NOW()
             WHERE requesting_branch_id = ? AND shift_date = ? AND start_time = ? AND end_time = ? AND id != ? AND status = 'pending'"
        );
        $closeStmt->execute([
            $note,
            $request['requesting_branch_id'],
            $request['shift_date'],
            $request['start_time'],
            $request['end_time'],
            $request_id
        ]);

        // Notify the user who created the request that it was accepted
        try {
            $requesterId = (int) ($request['requested_by_user_id'] ?? 0);
            if ($requesterId > 0) {
                $msg = "Your coverage request for {$request['shift_date']} {$request['start_time']}-{$request['end_time']} was accepted by {$fulfillerName}";
                if (!empty($fulfillerBranchName)) {
                    $msg .= " ({$fulfillerBranchName})";
                }
                // related_id = request id so UI can link back
                addNotification($conn, $requesterId, $msg, 'success', $request_id);
            }
        } catch (Exception $e) {
            // Don't block the main flow if notification fails; log for diagnostics
            error_log('Failed to notify requester for fulfilled cross-branch request: ' . $e->getMessage());
        }

        // Notify requesting branch admins that a request was accepted
        try {
            $admSql2 = "(SELECT id FROM users WHERE branch_id = ? AND role IN ('admin','super_admin'))
                       UNION
                       (SELECT u.id FROM users u JOIN branch_permissions bp ON bp.user_id = u.id WHERE bp.branch_id = ? AND bp.permission_level = 'admin')";
            $admStmt2 = $conn->prepare($admSql2);
            $admStmt2->execute([$request['requesting_branch_id'], $request['requesting_branch_id']]);
            $admins2 = $admStmt2->fetchAll(PDO::FETCH_COLUMN);
            $adminMsg = "Coverage request for {$request['shift_date']} {$request['start_time']}-{$request['end_time']} has been accepted by {$fulfillerName}";
            foreach ($admins2 as $aid) {
                try {
                    addNotification($conn, (int) $aid, $adminMsg, 'success', $request_id);
                } catch (Exception $e) {
                    error_log('Failed to notify requesting branch admin: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('Failed to notify requesting branch admins for fulfilled request: ' . $e->getMessage());
        }

        // Audit: cross-branch request fulfilled
        try {
            require_once __DIR__ . '/../includes/audit_log.php';
            log_audit($conn, $approving_user_id ?? ($_SESSION['user_id'] ?? null), 'cross_branch_request_fulfilled', ['shift_id' => $shift_id, 'fulfiller_user_id' => $fulfilling_user_id, 'approving_user_id' => $approving_user_id], $request_id, 'cross_branch_request', session_id());
        } catch (Exception $e) {
        }

        // If the original requester provided a source_shift_id (they requested coverage for their own shift), remove that original shift now
        if (!empty($request['source_shift_id'])) {
            try {
                // Only remove if the shift still exists and belongs to the requesting user
                $sstmt = $conn->prepare("SELECT user_id FROM shifts WHERE id = ? LIMIT 1");
                $sstmt->execute([(int) $request['source_shift_id']]);
                $owner = $sstmt->fetchColumn();
                if ($owner && (int) $owner === (int) $request['requested_by_user_id']) {
                    $dstmt = $conn->prepare("DELETE FROM shifts WHERE id = ?");
                    $dstmt->execute([(int) $request['source_shift_id']]);
                }
            } catch (Exception $e) {
                // Don't block fulfillment on this cleanup; log instead
                error_log('Failed to remove source shift after fulfillment: ' . $e->getMessage());
            }
        }

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Get cross-branch coverage statistics
 */
function getCrossBranchStats($conn, $branch_id = null, $period_days = 30)
{
    $where_clause = $branch_id ? "AND (cbr.requesting_branch_id = ? OR cbr.target_branch_id = ?)" : "";
    $params = $branch_id ? [$branch_id, $branch_id] : [];

    $sql = "SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN cbr.status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled_requests,
                SUM(CASE WHEN cbr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN cbr.status = 'declined' THEN 1 ELSE 0 END) as declined_requests
            FROM cross_branch_shift_requests cbr
            WHERE cbr.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            $where_clause";

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$period_days], $params));
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>