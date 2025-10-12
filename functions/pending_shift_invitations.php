<?php
session_start();
require_once '../includes/db.php';
require_once '../functions/calculate_pay.php';

$user_id = $_SESSION['user_id'];

// Build an associative array of role IDs to role names.
$stmtRoles = $conn->query("SELECT id, name FROM roles");
$roleList = [];
while ($r = $stmtRoles->fetch(PDO::FETCH_ASSOC)) {
    $roleList[$r['id']] = $r['name'];
}

// Exclude broadcast invitations the user has declined.
$query = "SELECT * FROM shift_invitations 
          WHERE (user_id = :user_id 
              OR (user_id IS NULL AND id NOT IN (SELECT invitation_id FROM decline_responses WHERE user_id = :user_id2)))
          AND status = 'pending'
          ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':user_id2', $user_id, PDO::PARAM_INT);
$stmt->execute();
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no pending invitations, clear shift-invite notifications for the user.
if (empty($invitations)) {
    $stmtClear = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND type = 'shift-invite'");
    $stmtClear->execute([$user_id]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Shift Invitations</title>
    <link rel="stylesheet" href="../css/pending_shift_invitations.css">
</head>

<body>
    <h1>Pending Shift Invitations</h1>
    <?php if (empty($invitations)): ?>
        <p>You have no pending shift invitations.</p>
    <?php else: ?>
        <?php foreach ($invitations as $invitation): ?>
            <?php
            // Format the date and time for display.
            $formattedDate = date("l, F j, Y", strtotime($invitation['shift_date']));
            $formattedStart = date("g:i A", strtotime($invitation['start_time']));
            $formattedEnd = date("g:i A", strtotime($invitation['end_time']));
            // Calculate the estimated pay for this invitation.
            $estimatedPay = calculateInvitationPay($conn, $invitation);
            // Get the role name using our lookup array.
            $roleName = isset($roleList[$invitation['role_id']]) ? $roleList[$invitation['role_id']] : 'Unknown Role';
            ?>
            <div class="invitation">
                <p><strong>Shift Date:</strong> <?php echo htmlspecialchars($formattedDate); ?></p>
                <p><strong>Time:</strong> <?php echo htmlspecialchars($formattedStart); ?> to
                    <?php echo htmlspecialchars($formattedEnd); ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($invitation['location']); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($roleName); ?></p>
                <p><strong>Estimated Pay:</strong> £<?php echo number_format($estimatedPay, 2); ?></p>
                <form method="POST" action="../functions/shift_invitation.php">
                    <input type="hidden" name="invitation_id" value="<?php echo $invitation['id']; ?>">
                    <?php if (isset($_GET['notif_id'])): ?>
                        <input type="hidden" name="notif_id" value="<?php echo htmlspecialchars($_GET['notif_id']); ?>">
                    <?php endif; ?>
                    <button type="submit" name="action" value="accept">Accept</button>
                    <button type="submit" name="action" value="decline">Decline</button>
                </form>
            </div>
            <hr>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="../users/dashboard.php">Back to Dashboard</a></p>
</body>

</html>