<?php
require '../includes/auth.php';
requireAdmin(); // Only admins can access

// Fetch total users
$totalUsers = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
// Fetch total shifts
$totalShifts = $conn->query("SELECT COUNT(*) FROM shifts")->fetchColumn();
// Fetch role distribution
$roles = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);
// Fetch shifts per day
$shiftsPerDay = $conn->query("
    SELECT shift_date, COUNT(*) as count
    FROM shifts
    GROUP BY shift_date
    ORDER BY shift_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users with their roles
$users = $conn->query("
    SELECT id, username, email, role, created_at 
    FROM users 
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

// Handle role update if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $userId = $_POST['user_id'];
    $newRole = $_POST['role'];
    
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    if ($stmt->execute([$newRole, $userId])) {
        $successMessage = "User role updated successfully";
    } else {
        $errorMessage = "Error updating user role";
    }
}

// Determine view type (week or day)
$viewType = isset($_GET['view']) ? $_GET['view'] : 'week'; // Default to week view

// Get current week and day for filtering
$currentWeekStart = isset($_GET['week_start']) ? $_GET['week_start'] : date('Y-m-d', strtotime('monday this week'));
$currentDay = isset($_GET['day']) ? $_GET['day'] : date('Y-m-d');

// Calculate previous and next week/day for navigation
$prevWeekStart = date('Y-m-d', strtotime('-1 week', strtotime($currentWeekStart)));
$nextWeekStart = date('Y-m-d', strtotime('+1 week', strtotime($currentWeekStart)));
$prevDay = date('Y-m-d', strtotime('-1 day', strtotime($currentDay)));
$nextDay = date('Y-m-d', strtotime('+1 day', strtotime($currentDay)));

// Get all shifts with user and role info based on view type
if ($viewType === 'week') {
    $stmt = $conn->prepare("
        SELECT s.*, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE s.shift_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)
        ORDER BY s.shift_date, s.start_time
    ");
    $stmt->execute([$currentWeekStart, $currentWeekStart]);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else { // day view
    $stmt = $conn->prepare("
        SELECT s.*, u.username, r.name as role_name
        FROM shifts s
        JOIN users u ON s.user_id = u.id
        JOIN roles r ON s.role_id = r.id
        WHERE s.shift_date = ?
        ORDER BY s.start_time
    ");
    $stmt->execute([$currentDay]);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Open Rota">
<link rel="manifest" href="/rota-app-main/manifest.json">
<link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
 
  
    
</head>
<body>
    <div class="container">
        <h1>Admin Dashboard</h1>
        
        <div class="nav-links">
            <a href="../users/dashboard.php">Return to Dashboard</a>
            <a href="../functions/shift_invitation_sender.php">Send Shift Invitations</a>
        </div>
        
        <?php if (isset($successMessage)): ?>
            <div class="success-message"><?php echo $successMessage; ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
            <div class="error-message"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
        
        <div class="stats">
    <div class="stat-box">
        <h3><a href="manage_users.php">Total Users</a></h3>
        <p><a href="manage_users.php"><?php echo $totalUsers; ?></a></p>
    </div>
    <div class="stat-box">
        <h3><a href="manage_shifts.php">Edit Shifts</a></h3>
        <p><a href="manage_shifts.php"><?php echo $totalShifts; ?></a></p>
    </div>
    <div class="stat-box">
        <h3><a href="manage_admins.php">Admins</a></h3>
        <p>
            <a href="manage_admins.php">
                <?php 
                    $adminCount = 0;
                    foreach ($roles as $role) {
                        if ($role['role'] === 'admin') {
                            $adminCount = $role['count'];
                            break;
                        }
                    }
                    echo $adminCount;
                ?>
            </a>
        </p>
    </div>
</div>
        
       
        
        <h2>User Management</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                    <th>Manage Shifts</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <select name="role">
                                <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <button type="submit" name="update_role" class="action-button">Update</button>
                        </form>
                    </td>
                    <td>
    
    <!-- New manage shifts button -->
    <a class="manage_shift" href="manage_shifts.php?user_id=<?php echo $user['id']; ?>" class="action-button">Manage Shifts</a>
</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2>All Shifts</h2>
        
        <div class="view-controls">
            <?php if ($viewType === 'week'): ?>
                <a href="?view=week&week_start=<?php echo $prevWeekStart; ?>">&lt; Previous Week</a>
                <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>">Current Week</a>
                <a href="?view=week&week_start=<?php echo $nextWeekStart; ?>">Next Week &gt;</a>
            <?php else: ?>
                <a href="?view=day&day=<?php echo $prevDay; ?>">&lt; Previous Day</a>
                <a href="?view=day&day=<?php echo date('Y-m-d'); ?>">Today</a>
                <a href="?view=day&day=<?php echo $nextDay; ?>">Next Day &gt;</a>
            <?php endif; ?>
            
            <div class="view-toggle">
                <a href="?view=week&week_start=<?php echo $currentWeekStart; ?>" class="<?php echo $viewType === 'week' ? 'active' : ''; ?>">Week View</a>
                <a href="?view=day&day=<?php echo $currentDay; ?>" class="<?php echo $viewType === 'day' ? 'active' : ''; ?>">Day View</a>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                  
                    <th>User</th>
                    <th>Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Role</th>
                    <th>Location</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allShifts)): ?>
                    <tr><td colspan="6" style="text-align: center;">No shifts found for this period</td></tr>
                <?php else: ?>
                    <?php 
                    $currentDate = ''; 
                    foreach ($allShifts as $shift): 
                        $shiftDate = $shift['shift_date'];
                        
                        // Add a separator row when the date changes
                        if ($viewType === 'week' && $currentDate !== $shiftDate): 
                            $currentDate = $shiftDate;
                    ?>
                        <tr class="date-separator">
                            <td colspan="6" class="day-header"><?php echo date("l, F j, Y", strtotime($currentDate)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($shift['username']); ?></td>
                        <td><?php echo date("D, M j, Y", strtotime($shift['shift_date'])); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['start_time'])); ?></td>
                        <td><?php echo date("g:i A", strtotime($shift['end_time'])); ?></td>
                        <td><?php echo htmlspecialchars($shift['role_name']); ?></td>
                        <td><?php echo htmlspecialchars($shift['location']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    
</body>
</html>