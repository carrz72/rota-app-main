<?php
// Debug: Turn on error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../functions/branch_functions.php';

$user_id = $_SESSION['user_id'];
$user_branch = getUserHomeBranch($conn, $user_id);

// Get admin's branch - only allow them to see their branch requests
$stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
$adminBranchId = $adminUser['branch_id'];

if (!$adminBranchId || !$user_branch) {
    $_SESSION['error_message'] = "You must be assigned to a branch to manage cross-branch requests.";
    header("Location: admin_dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_request'])) {
        // Expecting comma-separated branch ids from the multi-select hidden input
        $raw_target_ids = $_POST['target_branch_ids'] ?? '';
        $target_branch_ids = array_filter(array_map('intval', explode(',', $raw_target_ids)));
        // Limit to 5
        $target_branch_ids = array_slice($target_branch_ids, 0, 5);
        if (empty($target_branch_ids)) {
            $_SESSION['error_message'] = 'Please select at least one target branch (up to 5).';
            header("Location: cross_branch_requests.php");
            exit();
        }
        $shift_date = $_POST['shift_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
    $role_id = isset($_POST['role_required']) && $_POST['role_required'] !== '' ? (int)$_POST['role_required'] : null; // role id or null for Any
        $urgency_level = $_POST['urgency_level'];
        $description = $_POST['description'];
        $expires_hours = $_POST['expires_hours'];

        // Calculate expiration time
        $expires_at = date('Y-m-d H:i:s', strtotime("+$expires_hours hours"));

        $successCount = 0;
        $errors = [];
        foreach ($target_branch_ids as $target_branch_id) {
            $request_data = [
                'requesting_branch_id' => $user_branch['id'],
                'target_branch_id' => $target_branch_id,
                'shift_date' => $shift_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'role_id' => $role_id,
                'urgency_level' => $urgency_level,
                'description' => $description,
                'requested_by_user_id' => $user_id,
                'expires_at' => $expires_at
            ];

            try {
                if (createCrossBranchRequest($conn, $request_data)) {
                    $successCount++;
                } else {
                    $errors[] = "Failed to create request for branch ID {$target_branch_id}";
                }
            } catch (Exception $e) {
                $errors[] = "Error for branch ID {$target_branch_id}: " . $e->getMessage();
            }
        }

        if ($successCount > 0) {
            $_SESSION['success_message'] = "Cross-branch coverage request sent to {$successCount} branch(es).";
            if (!empty($errors)) {
                $_SESSION['error_message'] = implode('\n', $errors);
            }
        } else {
            $_SESSION['error_message'] = "No requests created. " . implode('\n', $errors);
        }

        header("Location: cross_branch_requests.php");
        exit();
    }

    if (isset($_POST['fulfill_request'])) {
        $request_id = $_POST['request_id'];
        $fulfilling_user_id = $_POST['fulfilling_user_id'];

        try {
            fulfillCrossBranchRequest($conn, $request_id, $fulfilling_user_id, $user_id);
            $_SESSION['success_message'] = "Request fulfilled successfully!";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error fulfilling request: " . $e->getMessage();
        }

        header("Location: cross_branch_requests.php");
        exit();
    }

    if (isset($_POST['decline_request'])) {
        $request_id = $_POST['request_id'];
        $notes = trim($_POST['decline_notes'] ?? '');

        $sql = "UPDATE cross_branch_shift_requests SET status = 'declined', notes = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$notes, $request_id]);

        // Notify the requester that their request was declined
        try {
            require_once __DIR__ . '/../functions/addNotification.php';
            $rstmt = $conn->prepare("SELECT requested_by_user_id, shift_date, start_time, end_time FROM cross_branch_shift_requests WHERE id = ? LIMIT 1");
            $rstmt->execute([$request_id]);
            $r = $rstmt->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['requested_by_user_id'])) {
                $msg = "Your coverage request for {$r['shift_date']} {$r['start_time']}-{$r['end_time']} was declined.";
                addNotification($conn, (int)$r['requested_by_user_id'], $msg, 'error', $request_id);
            }
        } catch (Exception $e) {
            error_log('Failed to notify requester about decline: ' . $e->getMessage());
        }

        $_SESSION['success_message'] = "Request declined.";
        header("Location: cross_branch_requests.php");
        exit();
    }
}

// Get all branches for dropdown
$all_branches = getAllBranches($conn);

// Get roles for dropdown
$rolesStmt = $conn->prepare("SELECT id, name FROM roles ORDER BY name");
$rolesStmt->execute();
$all_roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending requests for user's branch
$pending_requests = getPendingRequestsForBranch($conn, $user_branch['id']);

// Get user's own requests (show pending and fulfilled, include fulfiller info)
$sql = "SELECT cbr.*, tb.name as target_branch_name, uf.username AS fulfilled_by_username, fb.name AS accepted_by_branch
    FROM cross_branch_shift_requests cbr
    JOIN branches tb ON cbr.target_branch_id = tb.id
    LEFT JOIN users uf ON cbr.fulfilled_by_user_id = uf.id
    LEFT JOIN branches fb ON uf.branch_id = fb.id
    WHERE cbr.requested_by_user_id = ? 
    AND cbr.status IN ('pending','fulfilled')
    ORDER BY cbr.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check for messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Cross-Branch Coverage Requests - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Multi-select styles */
        .multi-select { position: relative; border: 1px solid #ddd; padding: 8px; border-radius: 6px; }
        .multi-select input#branch-search { width: 100%; padding: 6px; border: 1px solid #eee; border-radius: 4px; }
    .options-list { max-height: 150px; overflow: auto; margin-top: 8px; border-top: 1px dashed #f0f0f0; padding-top: 8px; display: none; position: absolute; left: 8px; right: 8px; background: #fff; z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .options-list .option { padding: 6px; cursor: pointer; border-radius: 4px; }
        .options-list .option:hover { background: #f4f4f4; }
        .selected-list { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .chip { background: #eee; padding: 4px 8px; border-radius: 16px; display: inline-flex; align-items: center; gap: 6px; }
        .chip-remove { cursor: pointer; padding-left: 6px; color: #777; }
        .hint { display:block; font-size: 12px; color: #666; margin-top:4px; }
    </style>
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-exchange-alt"></i> Cross-Branch Coverage Requests</h1>
                <span class="breadcrumb">Your branch:
                    <strong><?php echo htmlspecialchars($user_branch['name']); ?></strong></span>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-exchange-alt"></i> Cross-Branch Coverage Management</h2>
            </div>
        </div>

        <!-- Create Request Section -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-plus"></i> Request Coverage from Another Branch</h2>
            </div>
            <div class="admin-panel-body">
                <form method="POST" class="admin-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="target_branch_ids">
                                <i class="fas fa-building"></i> Request Coverage From (select up to 5):
                            </label>
                            <div id="branch-multi-select" class="multi-select">
                                <input type="text" id="branch-search" placeholder="Search branches..." autocomplete="off">
                                <div id="branch-selected" class="selected-list"></div>
                                <div id="branch-options" class="options-list">
                                    <?php foreach ($all_branches as $branch): ?>
                                        <?php if ($branch['id'] != $user_branch['id']): ?>
                                            <div class="option" data-id="<?php echo $branch['id']; ?>">
                                                <?php echo htmlspecialchars($branch['name']); ?>
                                                <span class="muted">(<?php echo htmlspecialchars($branch['code']); ?>)</span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="target_branch_ids" name="target_branch_ids" value="">
                                <small class="hint">Type to search, click to select. Max 5 branches.</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="urgency_level">
                                <i class="fas fa-exclamation-triangle"></i> Urgency Level:
                            </label>
                            <select id="urgency_level" name="urgency_level" required>
                                <option value="low">Low - Not urgent</option>
                                <option value="medium" selected>Medium - Preferred coverage</option>
                                <option value="high">High - Important to fill</option>
                                <option value="critical">Critical - Must be filled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shift_date">
                                <i class="fas fa-calendar"></i> Shift Date:
                            </label>
                            <input type="date" id="shift_date" name="shift_date" required
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="expires_hours">
                                <i class="fas fa-clock"></i> Request Expires In:
                            </label>
                            <select id="expires_hours" name="expires_hours" required>
                                <option value="6">6 hours</option>
                                <option value="12">12 hours</option>
                                <option value="24" selected>24 hours</option>
                                <option value="48">48 hours</option>
                                <option value="72">72 hours</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="start_time">
                                <i class="fas fa-clock"></i> Start Time:
                            </label>
                            <input type="time" id="start_time" name="start_time" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">
                                <i class="fas fa-clock"></i> End Time:
                            </label>
                            <input type="time" id="end_time" name="end_time" required>
                        </div>
                        <div class="form-group">
                            <label for="role_required">
                                <i class="fas fa-user-tag"></i> Role/Position Required:
                            </label>
                            <select id="role_required" name="role_required">
                                <option value="">Any role</option>
                                <?php foreach ($all_roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="description">
                                <i class="fas fa-comment"></i> Additional Details:
                            </label>
                            <textarea id="description" name="description" rows="3"
                                placeholder="Any specific requirements or information..."></textarea>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="create_request" class="admin-btn">
                            <i class="fas fa-paper-plane"></i> Send Coverage Request
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Incoming Requests Section -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-inbox"></i> Incoming Coverage Requests
                    <span class="badge"><?php echo count($pending_requests); ?></span>
                </h2>
            </div>
            <div class="admin-panel-body">
                <?php if (empty($pending_requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No pending coverage requests at this time.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_requests as $request): ?>
                        <?php if ($request['target_branch_id'] == $user_branch['id']): // Only show requests TO this branch ?>
                            <div class="admin-panel urgency-<?php echo $request['urgency_level']; ?>" style="margin-bottom: 20px;">
                                <div class="admin-panel-header">
                                    <h3>
                                        <i class="fas fa-building"></i>
                                        Coverage Request from
                                        <?php echo htmlspecialchars($request['requesting_branch_name']); ?>
                                        <span class="badge <?php echo $request['urgency_level']; ?>">
                                            <?php echo ucfirst($request['urgency_level']); ?> Priority
                                        </span>
                                    </h3>
                                </div>
                                <div class="admin-panel-body">
                                    <div class="form-grid">
                                        <div class="info-card">
                                            <h4><i class="fas fa-calendar"></i> Date & Time</h4>
                                            <p>
                                                <?php echo date('M j, Y', strtotime($request['shift_date'])); ?><br>
                                                <?php echo date('g:i A', strtotime($request['start_time'])); ?>
                                                -
                                                <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                            </p>
                                        </div>
                                        <div class="info-card">
                                            <h4><i class="fas fa-user-tag"></i> Role Required</h4>
                                            <p><?php echo htmlspecialchars($request['role_required'] ?: 'Any'); ?>
                                            </p>
                                        </div>
                                        <div class="info-card">
                                            <h4><i class="fas fa-user"></i> Requested By</h4>
                                            <p><?php echo htmlspecialchars($request['requested_by_username']); ?></p>
                                        </div>
                                        <div class="info-card">
                                            <h4><i class="fas fa-clock"></i> Expires</h4>
                                            <p><?php echo date('M j, g:i A', strtotime($request['expires_at'])); ?></p>
                                        </div>
                                    </div>

                                    <?php if ($request['description']): ?>
                                        <div class="info-message" style="margin: 15px 0;">
                                            <strong><i class="fas fa-info-circle"></i> Additional Details:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="form-actions" style="margin-top: 20px;">
                                        <button onclick="showAvailableEmployees(<?php echo $request['id']; ?>)" class="admin-btn">
                                            <i class="fas fa-users"></i> View Available Employees
                                        </button>
                                        <button onclick="showDeclineForm(<?php echo $request['id']; ?>)"
                                            class="admin-btn delete-btn">
                                            <i class="fas fa-times"></i> Decline Request
                                        </button>
                                    </div>

                                    <!-- Available Employees (Hidden by default) -->
                                    <div id="employees-<?php echo $request['id']; ?>" class="admin-panel"
                                        style="display: none; margin-top: 15px;">
                                        <!-- This will be populated by JavaScript -->
                                    </div>

                                    <!-- Decline Form (Hidden by default) -->
                                    <div id="decline-<?php echo $request['id']; ?>" class="admin-panel"
                                        style="display: none; margin-top: 15px;">
                                        <div class="admin-panel-header">
                                            <h4><i class="fas fa-times"></i> Decline Request</h4>
                                        </div>
                                        <div class="admin-panel-body">
                                            <form method="POST" class="admin-form">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                <div class="form-group">
                                                    <label for="decline_notes_<?php echo $request['id']; ?>">
                                                        <i class="fas fa-comment"></i> Reason for declining (optional):
                                                    </label>
                                                    <textarea id="decline_notes_<?php echo $request['id']; ?>" name="decline_notes"
                                                        rows="3"
                                                        placeholder="e.g., No available staff, scheduling conflict..."></textarea>
                                                </div>
                                                <div class="form-actions">
                                                    <button type="submit" name="decline_request" class="admin-btn delete-btn">
                                                        <i class="fas fa-times"></i> Decline Request
                                                    </button>
                                                    <button type="button" onclick="hideDeclineForm(<?php echo $request['id']; ?>)"
                                                        class="admin-btn secondary">
                                                        <i class="fas fa-arrow-left"></i> Cancel
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- My Requests Section -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2><i class="fas fa-paper-plane"></i> My Coverage Requests
                    <span class="badge"><?php echo count($my_requests); ?></span>
                </h2>
            </div>
            <div class="admin-panel-body">
                <?php if (empty($my_requests)): ?>
                    <div class="empty-state">
                        <i class="fas fa-paper-plane"></i>
                        <p>You haven't made any coverage requests yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($my_requests as $request): ?>
                        <div class="admin-panel urgency-<?php echo $request['urgency_level']; ?>" style="margin-bottom: 20px;">
                            <div class="admin-panel-header">
                                <h3>
                                    <i class="fas fa-building"></i>
                                    Request to <?php echo htmlspecialchars($request['target_branch_name']); ?>
                                    <span class="badge <?php echo $request['urgency_level']; ?>">
                                        <?php echo ucfirst($request['urgency_level']); ?> Priority
                                    </span>
                                </h3>
                                <?php if ($request['status'] === 'fulfilled'): ?>
                                    <span class="badge success">Accepted</span>
                                <?php elseif ($request['status'] === 'declined'): ?>
                                    <span class="badge error">Declined</span>
                                <?php else: ?>
                                    <span class="badge info">Pending Response</span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-panel-body">
                                <div class="form-grid">
                                    <div class="info-card">
                                        <h4><i class="fas fa-calendar"></i> Date & Time</h4>
                                        <p>
                                            <?php echo date('M j, Y', strtotime($request['shift_date'])); ?><br>
                                            <?php echo date('g:i A', strtotime($request['start_time'])); ?> -
                                            <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                        </p>
                                    </div>
                                    <div class="info-card">
                                        <h4><i class="fas fa-user-tag"></i> Role Required</h4>
                                        <p><?php echo htmlspecialchars($request['role_required'] ?: 'Any'); ?></p>
                                    </div>
                                    <div class="info-card">
                                        <h4><i class="fas fa-clock"></i> Expires</h4>
                                        <p><?php echo date('M j, g:i A', strtotime($request['expires_at'])); ?></p>
                                    </div>
                                </div>

                                <?php if ($request['description']): ?>
                                    <div class="info-message" style="margin-top: 15px;">
                                        <strong><i class="fas fa-info-circle"></i> Additional Details:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($request['status'] === 'fulfilled'): ?>
                                    <div class="info-message" style="margin-top: 15px; background:#f4fff4; padding:8px; border-radius:6px;">
                                        <strong><i class="fas fa-check-circle"></i> Accepted</strong>
                                        <p>Accepted by: <strong><?php echo htmlspecialchars($request['fulfilled_by_username'] ?? 'a user'); ?></strong>
                                        <?php if (!empty($request['accepted_by_branch'])): ?>
                                            (<?php echo htmlspecialchars($request['accepted_by_branch']); ?>)
                                        <?php endif; ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Branch multi-select logic
        (function() {
            const search = document.getElementById('branch-search');
            const optionsList = document.getElementById('branch-options');
            const options = document.querySelectorAll('#branch-options .option');
            const selectedContainer = document.getElementById('branch-selected');
            const hiddenInput = document.getElementById('target_branch_ids');
            const maxSelect = 5;
            let selected = [];

            function renderSelected() {
                selectedContainer.innerHTML = '';
                selected.forEach(id => {
                    const opt = Array.from(options).find(o => o.dataset.id === String(id));
                    if (!opt) return;
                    const chip = document.createElement('div');
                    chip.className = 'chip';
                    chip.innerText = opt.textContent.trim();
                    const remove = document.createElement('span');
                    remove.className = 'chip-remove';
                    remove.innerText = '×';
                    remove.onclick = () => { selected = selected.filter(x => x !== id); renderSelected(); };
                    chip.appendChild(remove);
                    selectedContainer.appendChild(chip);
                });
                hiddenInput.value = selected.join(',');
            }

            function filterOptions(q) {
                const term = q.trim().toLowerCase();
                Array.from(options).forEach(o => {
                    const txt = o.textContent.toLowerCase();
                    o.style.display = txt.indexOf(term) === -1 ? 'none' : 'block';
                });
            }

            // Show options when search focused
            function positionOptions() {
                const container = document.getElementById('branch-multi-select');
                const selectedRect = selectedContainer.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                // place options-list immediately under selectedContainer inside the multi-select
                optionsList.style.top = (selectedContainer.offsetTop + selectedContainer.offsetHeight + 6) + 'px';
                optionsList.style.left = '8px';
                optionsList.style.right = '8px';
            }

            function showOptions() { positionOptions(); optionsList.style.display = 'block'; }
            function hideOptions() { optionsList.style.display = 'none'; }

            // Hide options when clicking outside
            document.addEventListener('click', function(e) {
                const container = document.getElementById('branch-multi-select');
                if (!container) return;
                if (!container.contains(e.target)) {
                    hideOptions();
                }
            });

            // Hide options on Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') hideOptions();
            });

            Array.from(options).forEach(o => {
                o.addEventListener('click', () => {
                    const id = parseInt(o.dataset.id, 10);
                    if (selected.includes(id)) return;
                    if (selected.length >= maxSelect) {
                        alert('You can select up to ' + maxSelect + ' branches.');
                        return;
                    }
                    selected.push(id);
                    renderSelected();
                    // reposition and keep options visible for further selection
                    positionOptions();
                    showOptions();
                });
            });

            if (search) {
                search.addEventListener('input', (e) => {
                    filterOptions(e.target.value);
                });
                search.addEventListener('focus', () => { filterOptions(search.value); showOptions(); });
            }
        })();

    
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(tab => {
                tab.classList.remove('active');
                tab.classList.add('secondary');
            });

            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked tab button
            event.target.classList.add('active');
            event.target.classList.remove('secondary');
        }

        function showAvailableEmployees(requestId) {
            const container = document.getElementById('employees-' + requestId);
            container.innerHTML = '<div class="admin-panel-header"><h4><i class="fas fa-users"></i> Available Employees</h4></div><div class="admin-panel-body"><p><i class="fas fa-spinner fa-spin"></i> Loading available employees...</p></div>';
            container.style.display = 'block';

            fetch('get_available_employees.php?request_id=' + requestId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div class="admin-panel-header"><h4><i class="fas fa-users"></i> Available Employees</h4></div>';
                        html += '<div class="admin-panel-body">';

                        if (data.employees.length === 0) {
                            html += '<div class="empty-state"><i class="fas fa-user-times"></i><p>No available employees found for this time slot.</p></div>';
                        } else {
                            html += '<form method="POST" class="admin-form">';
                            html += '<input type="hidden" name="request_id" value="' + requestId + '">';
                            html += '<div class="form-group">';
                            html += '<label for="fulfilling_user_' + requestId + '"><i class="fas fa-user"></i> Select Employee:</label>';
                            html += '<select id="fulfilling_user_' + requestId + '" name="fulfilling_user_id" required>';
                            html += '<option value="">Select employee...</option>';

                            data.employees.forEach(employee => {
                                html += '<option value="' + employee.id + '">';
                                html += employee.username + ' (' + (employee.role_name || 'No role assigned') + ')';
                                if (employee.hourly_rate > 0) {
                                    html += ' - $' + parseFloat(employee.hourly_rate).toFixed(2) + '/hr';
                                }
                                html += '</option>';
                            });

                            html += '</select></div>';
                            html += '<div class="form-actions">';
                            html += '<button type="submit" name="fulfill_request" class="admin-btn">';
                            html += '<i class="fas fa-check"></i> Assign Employee</button>';
                            html += '</div></form>';
                        }

                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="admin-panel-header"><h4><i class="fas fa-exclamation-triangle"></i> Error</h4></div><div class="admin-panel-body"><div class="error-message"><i class="fas fa-exclamation-triangle"></i> ' + data.error + '</div></div>';
                    }
                })
                .catch(error => {
                    container.innerHTML = '<div class="admin-panel-header"><h4><i class="fas fa-exclamation-triangle"></i> Error</h4></div><div class="admin-panel-body"><div class="error-message"><i class="fas fa-exclamation-triangle"></i> Error loading employees: ' + error.message + '</div></div>';
                });
        }

        function showDeclineForm(requestId) {
            document.getElementById('decline-' + requestId).style.display = 'block';
        }

        function hideDeclineForm(requestId) {
            document.getElementById('decline-' + requestId).style.display = 'none';
        }
    </script>
</body>

</html>