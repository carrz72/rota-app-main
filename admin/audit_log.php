<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Restrict to super_admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../users/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    // Join users to provide friendly usernames when available
    $stmt = $conn->prepare("SELECT SQL_CALC_FOUND_ROWS audit_log.id, audit_log.user_id, u.username AS username, audit_log.action, audit_log.meta, audit_log.ip_address, audit_log.user_agent, audit_log.created_at FROM audit_log LEFT JOIN users u ON audit_log.user_id = u.id ORDER BY audit_log.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = $conn->query("SELECT FOUND_ROWS()")->fetchColumn();
} catch (Exception $e) {
    $rows = [];
    $total = 0;
}

$pages = max(1, ceil($total / $perPage));

function h($s) { return htmlentities((string)$s); }

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Log - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <style>
        :root{ --gap:12px; --panel-bg:#fff; --muted:#666; }
        .table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        table { width:100%; border-collapse:collapse; min-width:720px; }
        th,td { padding:8px; border-bottom:1px solid #eee; text-align:left; font-size:13px; vertical-align:top; }
        .pager { margin-top:12px; }
        .muted { color:var(--muted); font-size:12px; }
        .meta { font-family:monospace; white-space:pre-wrap; max-width:600px; font-size:13px; }

        /* Responsive tweaks */
        @media (max-width:800px) {
            table { font-size:13px; }
            .meta { font-size:12px; }
            .admin-container h1 { font-size:20px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <h1>Audit Log</h1>
        <p class="muted">Showing recent audit events. Only super admins can access this page.</p>

        <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Meta</th>
                    <th>IP</th>
                    <th>Agent</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7">No audit events found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo h($r['id']); ?></td>
                            <td>
                                <?php if (!empty($r['username'])): ?>
                                    <?php echo h($r['username']); ?> <span class="muted">(<?php echo h($r['user_id']); ?>)</span>
                                <?php else: ?>
                                    <?php echo h($r['user_id']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($r['action']); ?></td>
                            <td class="meta"><?php echo h($r['meta']); ?></td>
                            <td><?php echo h($r['ip_address']); ?></td>
                            <td><?php echo h($r['user_agent']); ?></td>
                            <td><?php echo h($r['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div class="pager">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php if ($i === $page): ?>
                    <strong><?php echo $i; ?></strong>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
                &nbsp;
            <?php endfor; ?>
        </div>

        <p><a href="admin_dashboard.php" class="admin-btn">Back to Admin Dashboard</a></p>
    </div>
</body>
</html>
