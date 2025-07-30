<?php
/**
 * Centralized Permission Management System
 * This handles all permission checks throughout the application
 */

class PermissionManager
{
    private $conn;
    private $userId;
    private $userRole;
    private $userBranchId;
    private $isSuperAdmin;

    public function __construct($conn, $userId)
    {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->loadUserData();
    }

    private function loadUserData()
    {
        $stmt = $this->conn->prepare("SELECT role, branch_id FROM users WHERE id = ?");
        $stmt->execute([$this->userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->userRole = $user['role'] ?? null;
        $this->userBranchId = $user['branch_id'] ?? null;
        $this->isSuperAdmin = $this->userRole === 'super_admin';
    }

    // Core permission checks
    public function isSuperAdmin()
    {
        return $this->isSuperAdmin;
    }

    public function isAdmin()
    {
        return in_array($this->userRole, ['admin', 'super_admin']);
    }

    public function getUserBranch()
    {
        return $this->userBranchId;
    }

    // Branch permissions
    public function canViewBranch($branchId)
    {
        return $this->isSuperAdmin || true; // All admins can view all branches
    }

    public function canEditBranch($branchId)
    {
        return $this->isSuperAdmin || ($this->userBranchId == $branchId);
    }

    public function canCreateBranch()
    {
        return $this->isSuperAdmin;
    }

    public function canDeleteBranch($branchId)
    {
        return $this->isSuperAdmin && $branchId != $this->userBranchId; // Can't delete own branch
    }

    // User management permissions
    public function canViewUser($userId)
    {
        if ($this->isSuperAdmin)
            return true;

        $stmt = $this->conn->prepare("SELECT branch_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user['branch_id'] == $this->userBranchId;
    }

    public function canEditUser($userId)
    {
        return $this->canViewUser($userId);
    }

    public function canAssignUserToBranch($targetBranchId)
    {
        return $this->isSuperAdmin || ($targetBranchId == $this->userBranchId);
    }

    public function canChangeUserRole($userId, $newRole)
    {
        if (!$this->isAdmin())
            return false;
        if ($newRole === 'super_admin')
            return $this->isSuperAdmin;
        return $this->canEditUser($userId);
    }

    // Shift permissions
    public function canViewShift($shiftId)
    {
        if ($this->isSuperAdmin)
            return true;

        $stmt = $this->conn->prepare("
            SELECT u.branch_id 
            FROM shifts s 
            JOIN users u ON s.user_id = u.id 
            WHERE s.id = ?
        ");
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        return $shift['branch_id'] == $this->userBranchId;
    }

    public function canManageShiftsForUser($userId)
    {
        return $this->canViewUser($userId);
    }

    // Cross-branch permissions
    public function canCreateCrossBranchRequest()
    {
        return $this->isAdmin() && $this->userBranchId;
    }

    public function canRespondToCrossBranchRequest($requestId)
    {
        $stmt = $this->conn->prepare("SELECT target_branch_id FROM cross_branch_shift_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->isSuperAdmin || ($request['target_branch_id'] == $this->userBranchId);
    }

    // Data filtering for queries
    public function getBranchFilterClause($tableAlias = 'u')
    {
        if ($this->isSuperAdmin) {
            return ['', []]; // No filter for super admin
        }

        if ($this->userBranchId) {
            return ["WHERE {$tableAlias}.branch_id = ?", [$this->userBranchId]];
        }

        return ["WHERE {$tableAlias}.branch_id IS NULL", []];
    }

    // Get accessible branches
    public function getAccessibleBranches($includeInactive = false)
    {
        $statusClause = $includeInactive ? '' : "WHERE status = 'active'";

        if ($this->isSuperAdmin) {
            return $this->conn->query("SELECT * FROM branches {$statusClause} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }

        // All admins can view all branches, but only edit their own
        return $this->conn->query("SELECT * FROM branches {$statusClause} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Require specific permissions
    public function requireSuperAdmin()
    {
        if (!$this->isSuperAdmin) {
            $_SESSION['error_message'] = "Super administrator privileges required.";
            header("Location: admin_dashboard.php");
            exit();
        }
    }

    public function requireBranchAssignment()
    {
        if (!$this->userBranchId && !$this->isSuperAdmin) {
            $_SESSION['error_message'] = "You must be assigned to a branch to access this feature.";
            header("Location: admin_dashboard.php");
            exit();
        }
    }
}
?>