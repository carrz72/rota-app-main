<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../users/dashboard.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
function h($s)
{
    return htmlentities((string) $s);
}

$id = intval($_GET['id'] ?? 0);
$action = null;
if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM audit_admin_actions WHERE id = ?');
    $stmt->execute([$id]);
    $action = $stmt->fetch(PDO::FETCH_ASSOC);
}
?><!doctype html>
<html>

<head>
    <?php $PAGE_TITLE = 'Audit Admin Action';
    require_once __DIR__ . '/admin_head.php'; ?>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
</head>

<body>
    <div class="admin-container">
        <h1>Audit Admin Actions</h1>
        <p class="muted">View an admin action and download signed proof.</p>
        <form method="get">
            <label>Action ID: <input name="id" value="<?php echo h($id); ?>" /></label>
            <button type="submit">Load</button>
        </form>

        <?php if ($action): ?>
            <h2>Action #<?php echo h($action['id']); ?> — <?php echo h($action['action']); ?></h2>
            <pre><?php echo h(json_encode($action, JSON_PRETTY_PRINT)); ?></pre>
            <p>
                <a href="../functions/get_audit_admin_action.php?id=<?php echo h($action['id']); ?>">Download signed proof
                    (JSON)</a>
            </p>
        <?php else: ?>
            <p>No action loaded.</p>
        <?php endif; ?>

        <p><a href="admin_dashboard.php">Back to dashboard</a></p>
    </div>
</body>

</html>