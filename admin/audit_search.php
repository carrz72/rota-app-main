<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Restrict to super_admin only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../users/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
$csrf_token = generate_csrf_token();

function h($s) { return htmlentities((string)$s); }

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(10, min(200, intval($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

$filters = [
    'user' => trim((string)($_GET['user'] ?? '')),
    'action' => trim((string)($_GET['action'] ?? '')),
    'ip' => trim((string)($_GET['ip'] ?? '')),
    'session_id' => trim((string)($_GET['session_id'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];

$wheres = [];
$params = [];

if ($filters['user'] !== '') {
    // allow partial match on user_id or username (joined users table)
    $wheres[] = '(audit_log.user_id LIKE :user OR u.username LIKE :user)';
    $params[':user'] = '%' . $filters['user'] . '%';
}

if ($filters['action'] !== '') {
    $wheres[] = 'action LIKE :action';
    $params[':action'] = '%' . $filters['action'] . '%';
}

if ($filters['ip'] !== '') {
    $wheres[] = 'ip_address LIKE :ip';
    $params[':ip'] = '%' . $filters['ip'] . '%';
}

if ($filters['session_id'] !== '') {
    // session_id is now stored in its own column; partial match against it
    $wheres[] = 'audit_log.session_id LIKE :session_id';
    $params[':session_id'] = '%' . $filters['session_id'] . '%';
}

if ($filters['q'] !== '') {
    $wheres[] = '(meta LIKE :q OR user_agent LIKE :q OR action LIKE :q)';
    $params[':q'] = '%' . $filters['q'] . '%';
}

// date handling: expect YYYY-MM-DD from input[type=date]
if ($filters['date_from'] !== '') {
    $d = $filters['date_from'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $wheres[] = "created_at >= :date_from";
        $params[':date_from'] = $d . ' 00:00:00';
    }
}

if ($filters['date_to'] !== '') {
    $d = $filters['date_to'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $wheres[] = "created_at <= :date_to";
        $params[':date_to'] = $d . ' 23:59:59';
    }
}

$whereSql = '';
// We'll join the users table so we can show friendly usernames and allow searching by username.
$fromSql = ' FROM audit_log LEFT JOIN users u ON audit_log.user_id = u.id';
if (!empty($wheres)) {
    $whereSql = ' WHERE ' . implode(' AND ', $wheres);
}

try {
    // total count (include join so username filters work)
    $countSql = "SELECT COUNT(*)" . $fromSql . $whereSql;
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    // fetch rows with pagination (select username from users)
    $sql = "SELECT audit_log.id, audit_log.user_id, u.username AS username, audit_log.action, audit_log.meta, audit_log.ip_address, audit_log.user_agent, audit_log.created_at" . $fromSql . $whereSql . " ORDER BY audit_log.created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
    $total = 0;
}

$pages = max(1, ceil($total / $perPage));

// If export requested, stream full CSV of matching rows (no pagination)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $csvSql = "SELECT audit_log.id, audit_log.user_id, u.username AS username, audit_log.action, audit_log.meta, audit_log.ip_address, audit_log.user_agent, audit_log.created_at" . $fromSql . $whereSql . " ORDER BY audit_log.created_at DESC";
        $csvStmt = $conn->prepare($csvSql);
        foreach ($params as $k => $v) {
            $csvStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $csvStmt->execute();
        $csvRows = $csvStmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','user_id','username','action','meta','ip_address','user_agent','created_at']);
        foreach ($csvRows as $cr) {
            fputcsv($out, [$cr['id'], $cr['user_id'], $cr['username'], $cr['action'], $cr['meta'], $cr['ip_address'], $cr['user_agent'], $cr['created_at']]);
        }
        fclose($out);
        exit;
    } catch (Exception $e) {
        // fall through to normal page with error / empty set
    }
}

// helper to build query strings while preserving filters
function qs(array $overrides = []) {
    $base = array_merge($_GET, $overrides);
    return http_build_query($base);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Search - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <style>
        :root{ --gap:12px; --panel-bg:#fff; --muted:#666; }
        .search-panel { background:var(--panel-bg); padding:12px; border:1px solid #e6e6e6; margin-bottom:14px; border-radius:6px; }
        label { display:block; font-size:13px; margin-top:8px; }
        input[type=text], input[type=date], select { padding:8px; width:100%; box-sizing:border-box; border:1px solid #ddd; border-radius:4px; }
        .row { display:flex; gap:var(--gap); flex-wrap:wrap; align-items:flex-end; }
        .row .col { flex:1 1 220px; min-width:140px; }
        .table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        table { width:100%; border-collapse:collapse; min-width:720px; }
        th,td { padding:8px; border-bottom:1px solid #eee; text-align:left; font-size:13px; vertical-align:top; }
        .muted { color:var(--muted); font-size:12px; }
        .meta { font-family:monospace; white-space:pre-wrap; max-width:600px; }
        .pager { margin-top:12px; }
        .controls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .results-head { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .admin-btn { display:inline-block; padding:8px 10px; background:#2d6cdf; color:#fff; border-radius:4px; text-decoration:none; }

        /* Modal adjustments */
        #metaModal > div, #anonymiseModal > div, #deleteAllModal > div { max-width:900px; width:90%; }

        /* Responsive tweaks for small screens */
        @media (max-width: 800px) {
            .row { flex-direction:column; align-items:stretch; }
            .row .col { min-width:unset; }
            .results-head { flex-direction:column; align-items:stretch; }
            .controls { justify-content:flex-start; }
            .admin-btn, button { width:100%; box-sizing:border-box; }
            table { font-size:13px; }
            .meta { font-size:12px; }
            #metaModal > div, #anonymiseModal > div, #deleteAllModal > div { width:96%; max-height:90%; overflow:auto; }
            .view-meta { width:100%; display:block; margin-top:6px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <h1>Audit Search</h1>
        <p class="muted">Search the audit log. Use the filters below to narrow results. Results are limited and paginated.</p>

        <div class="search-panel">
            <form method="get" action="audit_search.php">
                <div class="row">
                    <div class="col">
                        <label for="user">User (id or username, partial)</label>
                        <input id="user" name="user" type="text" placeholder="Enter user id or username (partial)" value="<?php echo h($filters['user']); ?>">
                    </div>

                    <div class="col">
                        <label for="action">Action</label>
                        <input id="action" name="action" type="text" value="<?php echo h($filters['action']); ?>">
                    </div>

                    <div class="col">
                        <label for="ip">IP address</label>
                        <input id="ip" name="ip" type="text" value="<?php echo h($filters['ip']); ?>">
                    </div>

                    <div class="col">
                        <label for="session_id">Session ID</label>
                        <input id="session_id" name="session_id" type="text" placeholder="Partial session id" value="<?php echo h($filters['session_id']); ?>">
                    </div>

                    <div class="col">
                        <label for="q">Free text (meta / user agent)</label>
                        <input id="q" name="q" type="text" value="<?php echo h($filters['q']); ?>">
                    </div>

                    <div class="col">
                        <label for="date_from">Date from</label>
                        <input id="date_from" name="date_from" type="date" value="<?php echo h($filters['date_from']); ?>">
                    </div>

                    <div class="col">
                        <label for="date_to">Date to</label>
                        <input id="date_to" name="date_to" type="date" value="<?php echo h($filters['date_to']); ?>">
                    </div>

                </div>

                <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
                    <div class="controls">
                        <label for="per_page" class="muted">Per page</label>
                        <select id="per_page" name="per_page">
                            <?php foreach ([10,25,50,100,200] as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $perPage == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit">Search</button>
                    <a href="audit_search.php" class="admin-btn">Reset</a>
                </div>
            </form>
        </div>

        <div class="results-head">
            <div class="muted">Showing <?php echo count($rows); ?> of <?php echo $total; ?> matching events</div>
                <div style="display:flex; gap:12px; align-items:center;">
                <a href="?<?php echo qs(['export' => 'csv']); ?>" class="admin-btn">Export CSV</a>
                <a href="admin_dashboard.php" class="admin-btn">Back to Admin Dashboard</a>
                <!-- Anonymise / erase control: opens modal to run a dry-run and then perform anonymisation -->
                <button id="anonymiseBtn" type="button" style="background:#c0392b;color:#fff;padding:8px;border-radius:4px;border:0;">Anonymise selected / by filter</button>
                <!-- Delete all logs (stronger gating) -->
                <button id="deleteAllBtn" type="button" style="background:#7f1d1d;color:#fff;padding:8px;border-radius:4px;border:0;">Delete all logs</button>
            </div>
        </div>

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
                    <tr><td colspan="7">No audit events found for these filters.</td></tr>
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
                            <td class="meta">
                                <?php $metaPreview = strlen($r['meta']) > 120 ? substr($r['meta'],0,120) . '...' : $r['meta']; ?>
                                <span class="meta-preview"><?php echo h($metaPreview); ?></span>
                                <?php if (strlen($r['meta']) > 120): ?>
                                    <button type="button" class="view-meta" data-meta="<?php echo h(addslashes($r['meta'])); ?>">View</button>
                                <?php endif; ?>
                            </td>
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
            <?php if ($pages > 1): ?>
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <strong><?php echo $i; ?></strong>
                    <?php else: ?>
                        <a href="?<?php echo qs(['page' => $i]); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    &nbsp;
                <?php endfor; ?>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>

<div id="metaModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; max-width:900px; width:90%; max-height:80%; overflow:auto; padding:16px; border-radius:6px; position:relative;">
        <button id="metaClose" style="position:absolute; right:8px; top:8px;">Close</button>
        <h3>Meta details</h3>
        <pre id="metaContent" style="white-space:pre-wrap; font-family:monospace; background:#f7f7f7; padding:12px; border:1px solid #eee;"></pre>
    </div>
</div>

<script>
    document.addEventListener('click', function(e){
        if (e.target.matches('.view-meta')) {
            var meta = e.target.getAttribute('data-meta') || '';
            // Try to pretty-print JSON if possible
            try {
                var obj = JSON.parse(meta);
                document.getElementById('metaContent').textContent = JSON.stringify(obj, null, 2);
            } catch (err) {
                document.getElementById('metaContent').textContent = meta;
            }
            document.getElementById('metaModal').style.display = 'flex';
        }
    });
    document.getElementById('metaClose').addEventListener('click', function(){
        document.getElementById('metaModal').style.display = 'none';
    });
    // close modal when clicking outside content
    document.getElementById('metaModal').addEventListener('click', function(e){
        if (e.target === this) this.style.display = 'none';
    });
</script>

<!-- Anonymise modal -->
<div id="anonymiseModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; max-width:700px; width:90%; padding:16px; border-radius:6px; position:relative;">
        <button id="anonymiseClose" style="position:absolute; right:8px; top:8px;">Close</button>
        <h3>Anonymise audit events</h3>
        <p class="muted">This will remove personal identifiers (user_id, ip address, user agent, and common PII keys in meta) for matching audit rows. A dry-run will show how many rows will be affected.</p>

        <div style="margin-top:12px;">
            <label>Dry run: <input type="checkbox" id="dryRun" checked></label>
        </div>
        <div style="margin-top:12px;">
            <label>Mode</label>
            <select id="modeSelect">
                <option value="anonymise">Anonymise (recommended)</option>
                <option value="archive_delete">Archive (encrypted) then delete (irreversible)</option>
            </select>
        </div>
        <div style="margin-top:12px;">
            <label>Note (optional)</label>
            <input id="anote" type="text" style="width:100%" placeholder="Reason or ticket number (max 255)">
        </div>
        <div style="margin-top:12px;">
            <label><input type="checkbox" id="allowAll"> Allow all (dangerous) - operate on all audit rows matching no filter</label>
        </div>

        <div style="margin-top:12px; display:flex; gap:8px;">
            <button id="runDry" type="button">Run</button>
            <button id="confirmRun" type="button" disabled style="background:#c0392b;color:#fff;border:0;padding:8px;border-radius:4px;">Confirm</button>
        </div>
        <div style="margin-top:12px;">
            <label for="erase_code">6-digit Erase Code (required for irreversible archive+delete)</label>
            <input id="erase_code" type="text" inputmode="numeric" pattern="\d{6}" placeholder="123456" maxlength="6" style="width:100%; max-width:200px;" />
            <div class="muted">Each admin who can delete logs may set their personal 6-digit erase code in their profile. If not set, your admin password will be accepted as a fallback.</div>
        </div>
    <?php $csrf_token_an = generate_csrf_token(); ?>
    <input type="hidden" id="csrf_token_field" name="csrf_token" value="<?php echo htmlentities($csrf_token_an); ?>">
    <div id="modeWarning" style="margin-top:12px; color:#a33; display:none; font-weight:bold;">Archive+delete is irreversible: encrypted archive will be created, originals will be deleted. Ensure you have backups and the encryption key is securely stored.</div>

        <div id="anonymiseResult" style="margin-top:12px; font-family:monospace; white-space:pre-wrap;"></div>
    </div>
</div>

<!-- Delete All modal (strong guard) -->
<div id="deleteAllModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999;">
    <div style="background:#fff; max-width:700px; width:90%; padding:16px; border-radius:6px; position:relative;">
        <button id="deleteAllClose" style="position:absolute; right:8px; top:8px;">Close</button>
        <h3>Delete all audit logs</h3>
        <p class="muted">This will archive (encrypted) and then permanently delete audit log rows matching the current filters or all rows if no filter is set. This action is irreversible and will be recorded in `audit_admin_actions` with a signed proof. Use the dry-run first.</p>

        <div style="margin-top:12px;">
            <label>Dry run: <input type="checkbox" id="deleteDryRun" checked></label>
        </div>

        <div style="margin-top:12px;">
            <label>Note (required)</label>
            <input id="deleteNote" type="text" style="width:100%" placeholder="Reason or ticket number (required)" />
        </div>

        <div style="margin-top:12px;">
            <label for="confirmPhrase">Type DELETE to confirm:</label>
            <input id="confirmPhrase" type="text" placeholder="DELETE" style="width:100%; max-width:200px;" />
        </div>

        <div style="margin-top:12px;">
            <label><input type="checkbox" id="chk_backup"> I have a verified backup of the database (recommended)</label>
            <br>
            <label><input type="checkbox" id="chk_irrevocable"> I understand this action is irreversible and will permanently delete the selected audit logs</label>
            <br>
            <label><input type="checkbox" id="deleteAllowAll"> Allow all (dangerous) - operate on all audit rows matching no filter</label>
        </div>

        <div style="margin-top:12px;">
            <label for="erase_code_delete">6-digit Erase Code (required)</label>
            <input id="erase_code_delete" type="text" inputmode="numeric" pattern="\d{6}" placeholder="123456" maxlength="6" style="width:100%; max-width:200px;" />
            <div class="muted">If you don't have an erase code set, you may enter your admin password below as a fallback.</div>
        </div>

        <div style="margin-top:12px;">
            <label for="confirm_admin_password_delete">Admin password (fallback)</label>
            <input id="confirm_admin_password_delete" type="password" placeholder="Enter your admin password (fallback)" style="width:100%; max-width:420px;" />
        </div>

        <div style="margin-top:12px; display:flex; gap:8px;">
            <button id="runDeleteDry" type="button">Run dry-run</button>
            <button id="confirmDeleteAll" type="button" disabled style="background:#c0392b;color:#fff;border:0;padding:8px;border-radius:4px;">Confirm Delete All</button>
        </div>

        <div id="deleteAllResult" style="margin-top:12px; font-family:monospace; white-space:pre-wrap;"></div>
    <?php $csrf_token_del = generate_csrf_token(); ?>
    <input type="hidden" id="csrf_token_delete" name="csrf_token" value="<?php echo htmlentities($csrf_token_del); ?>">
    </div>
</div>

<script>
document.getElementById('deleteAllBtn').addEventListener('click', function(){
    document.getElementById('deleteAllModal').style.display = 'flex';
    document.getElementById('confirmDeleteAll').disabled = true;
    document.getElementById('deleteAllResult').textContent = '';
    document.getElementById('confirmPhrase').value = '';
    document.getElementById('deleteNote').value = '';
    document.getElementById('confirm_admin_password_delete').value = '';
});
document.getElementById('deleteAllClose').addEventListener('click', function(){ document.getElementById('deleteAllModal').style.display = 'none'; });

function buildCriteriaFromFilters(){
    var url = new URL(window.location.href);
    var params = url.searchParams;
    var c = {};
    var user = params.get('user');
    if (user) {
        if (/^\d+$/.test(user)) c.user_id = parseInt(user,10);
        else c.user = user;
    }
    if (params.get('action')) c.action = params.get('action');
    if (params.get('ip')) c.ip = params.get('ip');
    if (params.get('session_id')) c.session_id = params.get('session_id');
    if (params.get('date_to')) c.older_than = params.get('date_to');
    if (params.get('date_from')) c.date_from = params.get('date_from');
    return c;
}

document.getElementById('runDeleteDry').addEventListener('click', async function(){
    var dry = document.getElementById('deleteDryRun').checked;
    var note = document.getElementById('deleteNote').value || null;
    if (!note) { document.getElementById('deleteAllResult').textContent = 'Error: Note is required.'; return; }
    var criteria = buildCriteriaFromFilters();
    var allowAll = document.getElementById('deleteAllowAll') ? document.getElementById('deleteAllowAll').checked : false;
    var csrfToken = document.getElementById('csrf_token_delete').value || '';
    if (Object.keys(criteria).length === 0 && !allowAll) { document.getElementById('deleteAllResult').textContent = 'Error: No criteria provided. Set filters on the search page or check "Allow all" to operate on all rows.'; return; }
    try {
        var res = await fetch('../functions/erase_audit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ criteria: criteria, dry_run: dry, note: note, mode: 'archive_delete', allow_all: allowAll, csrf_token: csrfToken, erase_code: document.getElementById('erase_code_delete').value || '', admin_password: document.getElementById('confirm_admin_password_delete').value || '', acknowledged_backup: document.getElementById('chk_backup').checked ? 1 : 0, acknowledged_irrevocable: document.getElementById('chk_irrevocable').checked ? 1 : 0 })
        });
        var txt = await res.text();
        var data;
        try { data = JSON.parse(txt); } catch (err) { data = null; }
        if (!data) { document.getElementById('deleteAllResult').textContent = 'Server response: ' + txt; return; }
        if (!data.ok) { document.getElementById('deleteAllResult').textContent = 'Error: ' + (data.error || JSON.stringify(data)); return; }
        if (data.dry_run) {
            document.getElementById('deleteAllResult').textContent = 'Dry-run: ' + data.affected + ' rows would be archived+deleted.';
            // remember dry-run affected count and update confirm button based on checklist
            window.deleteDryAffected = data.affected || 0;
            updateDeleteConfirmState();
        } else {
            document.getElementById('deleteAllResult').textContent = 'Success: ' + data.affected + ' rows archived+deleted. AdminActionID: ' + (data.archive_admin_action_id || '');
            document.getElementById('confirmDeleteAll').disabled = true;
        }
    } catch (err) { document.getElementById('deleteAllResult').textContent = 'Fetch error: ' + err.message; }
});

document.getElementById('confirmDeleteAll').addEventListener('click', async function(){
    var note = document.getElementById('deleteNote').value || null;
    var confirmPhrase = document.getElementById('confirmPhrase').value || '';
    if (confirmPhrase.trim().toUpperCase() !== 'DELETE') { document.getElementById('deleteAllResult').textContent = 'Error: Type DELETE to confirm.'; return; }
    if (!note) { document.getElementById('deleteAllResult').textContent = 'Error: Note is required.'; return; }
    // enforce checklist
    var chkBackup = document.getElementById('chk_backup').checked;
    var chkIrrev = document.getElementById('chk_irrevocable').checked;
    if (!chkBackup || !chkIrrev) { document.getElementById('deleteAllResult').textContent = 'Error: Please confirm the checklist items before proceeding.'; return; }
    var criteria = buildCriteriaFromFilters();
    var allowAll = document.getElementById('deleteAllowAll') ? document.getElementById('deleteAllowAll').checked : false;
    // fetch fresh CSRF token to avoid using the single-use token consumed by the dry-run
    var csrfToken = document.getElementById('csrf_token_delete').value || '';
    if (Object.keys(criteria).length === 0 && !allowAll) { document.getElementById('deleteAllResult').textContent = 'Error: No criteria provided. Set filters on the search page or check "Allow all" to operate on all rows.'; return; }
    try {
        var tResp = await fetch('../functions/get_csrf.php', { credentials: 'same-origin' });
        if (tResp.ok) {
            var tj = await tResp.json();
            if (tj.ok && tj.token) csrfToken = tj.token;
        }
    } catch (e) { /* fall back to existing token */ }
    try {
        var res = await fetch('../functions/erase_audit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ criteria: criteria, dry_run: false, note: note, mode: 'archive_delete', allow_all: allowAll, csrf_token: csrfToken, erase_code: document.getElementById('erase_code_delete').value || '', admin_password: document.getElementById('confirm_admin_password_delete').value || '', acknowledged_backup: document.getElementById('chk_backup').checked ? 1 : 0, acknowledged_irrevocable: document.getElementById('chk_irrevocable').checked ? 1 : 0 })
        });
        var txt = await res.text();
        var data;
        try { data = JSON.parse(txt); } catch (err) { data = null; }
        if (!data) { document.getElementById('deleteAllResult').textContent = 'Server response: ' + txt; return; }
        if (!data.ok) { document.getElementById('deleteAllResult').textContent = 'Error: ' + (data.error || JSON.stringify(data)); return; }
        var outMsg = 'Success: ' + data.affected + ' rows archived+deleted. AdminActionID: ' + (data.archive_admin_action_id || '');
        document.getElementById('deleteAllResult').textContent = outMsg;
        // If archive metadata returned, provide a downloadable proof file for handover
        if (data.archive_admin_action_id) {
            var proof = {
                admin_action_id: data.archive_admin_action_id,
                archive_ids: data.archive_ids || [],
                archive_hashes: data.archive_hashes || [],
                affected: data.affected,
                note: note,
                criteria: criteria,
                timestamp: new Date().toISOString()
            };
            try {
                var blob = new Blob([JSON.stringify(proof, null, 2)], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'audit_archive_proof_' + (data.archive_admin_action_id) + '.json';
                link.textContent = 'Download proof (JSON)';
                link.className = 'admin-btn';
                link.style.marginLeft = '8px';
                document.getElementById('deleteAllResult').appendChild(document.createTextNode(' '));
                document.getElementById('deleteAllResult').appendChild(link);
            } catch (e) { /* ignore blob errors */ }
        }
        document.getElementById('confirmDeleteAll').disabled = true;
    } catch (err) { document.getElementById('deleteAllResult').textContent = 'Fetch error: ' + err.message; }
});

// Helper: enable confirm button only when dry-run indicated affected>0 and checklist satisfied
function updateDeleteConfirmState() {
    var affected = window.deleteDryAffected || 0;
    var chkBackup = document.getElementById('chk_backup').checked;
    var chkIrrev = document.getElementById('chk_irrevocable').checked;
    var confirmPhrase = (document.getElementById('confirmPhrase').value || '').trim().toUpperCase();
    var can = (affected > 0) && chkBackup && chkIrrev && (confirmPhrase === 'DELETE');
    document.getElementById('confirmDeleteAll').disabled = !can;
}

// wire checklist inputs to state
['chk_backup','chk_irrevocable','confirmPhrase'].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', updateDeleteConfirmState);
    el.addEventListener('change', updateDeleteConfirmState);
});

// reset dry-run flag when modal opened
document.getElementById('deleteAllBtn').addEventListener('click', function(){ window.deleteDryAffected = 0; updateDeleteConfirmState(); });
</script>

<script>
document.getElementById('anonymiseBtn').addEventListener('click', function(){
    document.getElementById('anonymiseModal').style.display = 'flex';
    document.getElementById('confirmRun').disabled = true;
    document.getElementById('anonymiseResult').textContent = '';
});
document.getElementById('anonymiseClose').addEventListener('click', function(){ document.getElementById('anonymiseModal').style.display = 'none'; });

document.getElementById('modeSelect').addEventListener('change', function(){
    var w = document.getElementById('modeWarning');
    if (this.value === 'archive_delete') w.style.display = 'block'; else w.style.display = 'none';
});

function buildCriteriaFromFilters(){
    // reuse current page filters: user, action, ip, session_id, date_from, date_to
    var url = new URL(window.location.href);
    var params = url.searchParams;
    var c = {};
    var user = params.get('user');
    if (user) {
        // if user looks like integer, send user_id, otherwise leave as username (not supported server-side yet)
        if (/^\d+$/.test(user)) c.user_id = parseInt(user,10);
        else c.user = user;
    }
    if (params.get('action')) c.action = params.get('action');
    if (params.get('ip')) c.ip = params.get('ip');
    if (params.get('session_id')) c.session_id = params.get('session_id');
    if (params.get('date_to')) c.older_than = params.get('date_to');
    if (params.get('date_from')) c.date_from = params.get('date_from');
    return c;
}

document.getElementById('runDry').addEventListener('click', async function(){
    var dry = document.getElementById('dryRun').checked;
    var note = document.getElementById('anote').value || null;
    var criteria = buildCriteriaFromFilters();
    var allowAll = document.getElementById('allowAll').checked;
    if (Object.keys(criteria).length === 0 && !allowAll) {
        document.getElementById('anonymiseResult').textContent = 'Error: No criteria provided. Set filters on the search page or check "Allow all" to operate on all rows.';
        return;
    }
        try {
        var csrfToken = document.getElementById('csrf_token_field').value || '';
        var res = await fetch('../functions/erase_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ criteria: criteria, dry_run: dry, note: note, mode: document.getElementById('modeSelect').value, allow_all: allowAll, csrf_token: csrfToken, erase_code: document.getElementById('erase_code').value || '', admin_password: document.getElementById('confirm_admin_password') ? document.getElementById('confirm_admin_password').value || '' : '' })
        });
        var txt = await res.text();
        var data;
        try { data = JSON.parse(txt); } catch (err) { data = null; }
        if (!data) {
            document.getElementById('anonymiseResult').textContent = 'Server response: ' + txt;
            return;
        }
        if (!data.ok) {
            document.getElementById('anonymiseResult').textContent = 'Error: ' + (data.error || JSON.stringify(data));
            return;
        }
        if (data.dry_run) {
            document.getElementById('anonymiseResult').textContent = 'Dry-run: ' + data.affected + ' rows would be affected.';
            if (data.affected > 0) document.getElementById('confirmRun').disabled = false;
        } else {
            if (data.archive_admin_action_id) {
                var out = 'Success: ' + data.affected + ' rows archived+deleted.\nAdmin action id: ' + data.archive_admin_action_id + '\nArchive ids: ' + JSON.stringify(data.archive_ids) + '\nArchive hashes: ' + JSON.stringify(data.archive_hashes);
                document.getElementById('anonymiseResult').textContent = out;
                // provide download proof link
                var proof = {
                    admin_action_id: data.archive_admin_action_id,
                    archive_ids: data.archive_ids || [],
                    archive_hashes: data.archive_hashes || [],
                    affected: data.affected,
                    note: note,
                    criteria: criteria,
                    timestamp: new Date().toISOString()
                };
                try {
                    var blob = new Blob([JSON.stringify(proof, null, 2)], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = 'audit_archive_proof_' + (data.archive_admin_action_id) + '.json';
                    link.textContent = 'Download proof (JSON)';
                    link.className = 'admin-btn';
                    link.style.marginLeft = '8px';
                    document.getElementById('anonymiseResult').appendChild(document.createTextNode(' '));
                    document.getElementById('anonymiseResult').appendChild(link);
                } catch (e) { }
            } else {
                document.getElementById('anonymiseResult').textContent = 'Success: ' + data.affected + ' rows anonymised.';
            }
            document.getElementById('confirmRun').disabled = true;
        }
    } catch (err) {
        document.getElementById('anonymiseResult').textContent = 'Fetch error: ' + err.message;
    }
});

document.getElementById('confirmRun').addEventListener('click', async function(){
    var note = document.getElementById('anote').value || null;
    var criteria = buildCriteriaFromFilters();
    var allowAll = document.getElementById('allowAll').checked;
    if (Object.keys(criteria).length === 0 && !allowAll) {
        document.getElementById('anonymiseResult').textContent = 'Error: No criteria provided. Set filters on the search page or check "Allow all" to operate on all rows.';
        return;
    }
        try {
        // fetch fresh CSRF token to avoid using the single-use token consumed by the dry-run
        var csrfToken = document.getElementById('csrf_token_field').value || '';
        try {
            var tResp = await fetch('../functions/get_csrf.php', { credentials: 'same-origin' });
            if (tResp.ok) {
                var tj = await tResp.json();
                if (tj.ok && tj.token) csrfToken = tj.token;
            }
        } catch (e) { /* fall back to existing token */ }
        var res = await fetch('../functions/erase_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ criteria: criteria, dry_run: false, note: note, mode: document.getElementById('modeSelect').value, allow_all: allowAll, csrf_token: csrfToken, erase_code: document.getElementById('erase_code').value || '', admin_password: document.getElementById('confirm_admin_password') ? document.getElementById('confirm_admin_password').value || '' : '' })
        });
        var txt = await res.text();
        var data;
        try { data = JSON.parse(txt); } catch (err) { data = null; }
        if (!data) {
            document.getElementById('anonymiseResult').textContent = 'Server response: ' + txt;
            return;
        }
        if (!data.ok) {
            document.getElementById('anonymiseResult').textContent = 'Error: ' + (data.error || JSON.stringify(data));
            return;
        }
        document.getElementById('anonymiseResult').textContent = 'Success: ' + data.affected + ' rows anonymised.';
        document.getElementById('confirmRun').disabled = true;
    } catch (err) {
        document.getElementById('anonymiseResult').textContent = 'Fetch error: ' + err.message;
    }
});
</script>
