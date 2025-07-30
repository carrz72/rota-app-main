<?php
// Super Admin Functions
// Super admins can manage the entire system including creating branches and assigning branch admins

function isSuperAdmin($userId, $conn)
{
    // Check if user has super_admin role or is specifically marked as super admin
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND role = 'super_admin'");
    $stmt->execute([$userId]);
    return $stmt->fetch() !== false;
}

function requireSuperAdmin()
{
    require_once 'auth.php';
    requireAdmin(); // Must be admin first

    global $conn;
    if (!isSuperAdmin($_SESSION['user_id'], $conn)) {
        $_SESSION['error_message'] = "Access denied. Super administrator privileges required.";
        header("Location: admin_dashboard.php");
        exit();
    }
}

function canManageAllBranches($userId, $conn)
{
    return isSuperAdmin($userId, $conn);
}

function canCreateBranches($userId, $conn)
{
    return isSuperAdmin($userId, $conn);
}

function canAssignUsersToBranches($userId, $conn)
{
    return isSuperAdmin($userId, $conn);
}
?>