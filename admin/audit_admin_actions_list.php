<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../users/dashboard.php'); exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
$csrf = generate_csrf_token();

// simple pagination
$page = max(1, intval($_GET['page'] ?? 1)); $per = 50; $offset = ($page-1)*$per;
$stmt = $conn->prepare('SELECT id, admin_user_id, action, affected_count, created_at FROM audit_admin_actions ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $per, PDO::PARAM_INT); $stmt->bindValue(2, $offset, PDO::PARAM_INT); $stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = (int)$conn->query('SELECT COUNT(*) FROM audit_admin_actions')->fetchColumn();
$pages = max(1, ceil($total / $per));
?>
<!doctype html><html><head><meta charset="utf-8"><title>Admin Actions</title><link rel="stylesheet" href="../css/admin_dashboard.css"></head><body>
<div class="admin-container">
    <h1>Admin Actions</h1>
    <form id="exportForm" method="post" action="../functions/export_audit_admin_actions.php">
        <?php echo csrf_input_field(); ?>
        <table>
            <thead><tr><th></th><th>ID</th><th>Admin</th><th>Action</th><th>Affected</th><th>When</th></tr></thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo htmlentities($r['id']); ?>"></td>
                        <td><?php echo htmlentities($r['id']); ?></td>
                        <td><?php echo htmlentities($r['admin_user_id']); ?></td>
                        <td><?php echo htmlentities($r['action']); ?></td>
                        <td><?php echo htmlentities($r['affected_count']); ?></td>
                        <td><?php echo htmlentities($r['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:12px;">
            <label><input type="checkbox" name="include_archives" value="1"> Include archived blobs (base64) in export (large)</label>
        </div>
        <div style="margin-top:12px;">
            <button type="submit">Export selected proofs (ZIP)</button>
        </div>
    </form>
    <div style="margin-top:12px;">
        Pages: 
        <?php for($i=1;$i<=$pages;$i++): ?>
            <?php if ($i==$page): ?><strong><?php echo $i;?></strong><?php else: ?><a href="?page=<?php echo $i;?>"><?php echo $i;?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
</div>
</body></html>
