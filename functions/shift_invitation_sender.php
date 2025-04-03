<?php
session_start();
require_once '../includes/db.php';
require_once '../functions/addNotification.php';

// Only allow admin access.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../functions/login.php");
    exit;
}

$error = '';
$message = '';

// Fetch users excluding admin.
$stmtUsers = $conn->prepare("SELECT id, username, email FROM users WHERE id <> ?");
$stmtUsers->execute([$_SESSION['user_id']]);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Fetch all roles for the dropdown.
$stmtRoles = $conn->query("SELECT id, name FROM roles ORDER BY name ASC");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form input.
    $invited_user_id_input = trim($_POST['invited_user_id'] ?? '');
    $shift_date      = trim($_POST['shift_date'] ?? '');
    $start_time      = trim($_POST['start_time'] ?? '');
    $end_time        = trim($_POST['end_time'] ?? '');
    $role_id         = trim($_POST['role_id'] ?? ''); // now coming from dropdown
    $location        = trim($_POST['location'] ?? '');
    
    // Use NULL to represent "broadcast to everyone" if "all" is chosen.
    $invited_user_id = ($invited_user_id_input === 'all') ? null : $invited_user_id_input;
    
    // Basic validation.
    if (empty($invited_user_id_input) || empty($shift_date) || empty($start_time) || empty($end_time) || empty($role_id) || empty($location)) {
        $error = "All fields are required.";
    } else {
        // Insert invitation into the database.
        $stmt = $conn->prepare("INSERT INTO shift_invitations (shift_date, start_time, end_time, role_id, location, admin_id, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $admin_id = $_SESSION['user_id'];
        if ($stmt->execute([$shift_date, $start_time, $end_time, $role_id, $location, $admin_id, $invited_user_id])) {
            // Get the invitation ID.
            $invitation_id = $conn->lastInsertId();
            $notif_message = "You have a new shift invitation. Click to view details.";
            
            if (is_null($invited_user_id)) {
                // Broadcast: notify all non-admin users.
                $stmtAll = $conn->prepare("SELECT id FROM users WHERE id <> ?");
                $stmtAll->execute([$admin_id]);
                $allUsers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
                foreach ($allUsers as $user) {
                    // Send a notification to each user.
                    addNotification($conn, $user['id'], $notif_message, "shift-invite", $invitation_id);
                }
            } else {
                // Single user invitation.
                addNotification($conn, $invited_user_id, $notif_message, "shift-invite", $invitation_id);
            }
            
            $message = "Shift invitation sent successfully.";
        } else {
            $error = "Failed to send shift invitation.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Shift Invitation</title>
    <link rel="stylesheet" href="../css/shift_sender.css">
</head>
<body>
    <h1>Send Shift Invitation</h1>
    <?php if (!empty($error)): ?>
        <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif (!empty($message)): ?>
        <p style="color:green;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <form method="POST" action="">
        <p>
            <label for="invited_user_id">Invitee:</label>
            <select name="invited_user_id" id="invited_user_id" required>
                <option value="">Select a user</option>
                <!-- Option to send to Everyone -->
                <option value="all">Everyone</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['username'] . " (" . $user['email'] . ")"); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="shift_date">Shift Date:</label>
            <input type="date" name="shift_date" id="shift_date" required>
        </p>
        <p>
            <label for="start_time">Start Time:</label>
            <input type="time" name="start_time" id="start_time" required>
        </p>
        <p>
            <label for="end_time">End Time:</label>
            <input type="time" name="end_time" id="end_time" required>
        </p>
        <p>
            <label for="role_id">Role:</label>
            <select name="role_id" id="role_id" required>
                <option value="">Select a role</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>">
                        <?php echo htmlspecialchars($role['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="location">Location:</label>
            <input type="text" name="location" id="location" required>
        </p>
        <button type="submit">Send Invitation</button>
    </form>
    <p><a href="../users/dashboard.php">Back to Dashboard</a></p>
</body>
</html>