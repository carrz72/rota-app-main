<?php
require '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../includes/db.php';
require_once '../functions/branch_functions.php';

// Get user_id from query string if provided
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$return_url = isset($_GET['return']) ? $_GET['return'] : 'manage_shifts.php';

// Validate return URL to prevent open redirect
if (strpos($return_url, '../') === 0 || strpos($return_url, 'http') === 0) {
    $return_url = 'manage_shifts.php'; // Default if invalid
}

// Get all users for dropdown
// Determine admin's branch so we can limit the user list
$currentAdminId = $_SESSION['user_id'] ?? null;
$adminBranchId = null;
if ($currentAdminId) {
    $bstmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $bstmt->execute([$currentAdminId]);
    $adminBranchId = $bstmt->fetchColumn();
}

if ($adminBranchId) {
    // If a specific user_id is provided, include them as well so the preselected user appears
    if ($user_id) {
        $users_stmt = $conn->prepare("SELECT id, username FROM users WHERE branch_id = ? OR id = ? ORDER BY username");
        $users_stmt->execute([(int) $adminBranchId, $user_id]);
    } else {
        $users_stmt = $conn->prepare("SELECT id, username FROM users WHERE branch_id = ? ORDER BY username");
        $users_stmt->execute([(int) $adminBranchId]);
    }
} else {
    // Fallback: if admin has no branch, show all users
    $users_stmt = $conn->prepare("SELECT id, username FROM users ORDER BY username");
    $users_stmt->execute();
}
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all roles for dropdown
$roles_stmt = $conn->prepare("SELECT id, name FROM roles ORDER BY name");
$roles_stmt->execute();
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load user's saved templates
$templates = [];
try {
    $templates_stmt = $conn->prepare("SELECT * FROM shift_templates WHERE user_id = ? ORDER BY template_name");
    $templates_stmt->execute([$currentAdminId]);
    $templates = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Templates table might not exist yet - that's OK
}

// Default values
$default_date = date('Y-m-d');
$default_location = 'Main Office';
$default_branch_id = '';

// Load branches for branch picker
$all_branches = getAllBranches($conn);

// Prefer admin's own branch as the default for new shifts (location + branch)
if ($adminBranchId) {
    $bstmt = $conn->prepare("SELECT id, name FROM branches WHERE id = ? LIMIT 1");
    $bstmt->execute([(int) $adminBranchId]);
    $adminBranch = $bstmt->fetch(PDO::FETCH_ASSOC);
    if ($adminBranch) {
        $default_location = $adminBranch['name'];
        $default_branch_id = $adminBranch['id'];
    }
} elseif ($user_id) {
    // Fallback: if admin has no branch, use the user's home branch when provided
    $home = getUserHomeBranch($conn, $user_id);
    if ($home) {
        $default_location = $home['name'];
        $default_branch_id = $home['id'];
    }
}
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
    <title>Add Shift - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        /* Small form polish for add shift */
        .admin-panel {
            max-width: 920px;
            margin: 18px auto;
        }

        .admin-form {
            background: #fff;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .form-control {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e6e6e6;
        }

        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
        }

        .admin-btn.primary {
            background: #2b7cff;
            color: #fff;
            border-radius: 8px;
            padding: 10px 14px;
        }

        .admin-btn.secondary {
            background: #f4f6fa;
            color: #333;
            border-radius: 8px;
            padding: 10px 14px;
        }

        .quick-time-btn,
        .quick-day-btn {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #dee2e6;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
            transition: all 0.2s ease;
        }

        .quick-time-btn:hover,
        .quick-day-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        .form-help {
            color: #6c757d;
            font-size: 0.85em;
            margin-top: 4px;
            display: block;
        }

        .form-options {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }

        .form-options input[type="radio"] {
            margin-right: 5px;
            accent-color: #007bff;
        }

        @media (max-width:900px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width:600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-plus-circle"></i> Add New Shift</h1>
            </div>
            <div class="admin-actions">
                <a href="<?php echo htmlspecialchars($return_url); ?>" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <div class="admin-panel">
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <form action="../functions/add_shift.php" method="POST" class="admin-form">
                <input type="hidden" name="admin_mode" value="1">
                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">

                <!-- Enhanced Form with Templates and Bulk Options -->
                <div class="form-options"
                    style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="radio" name="shift_mode" value="single" checked onchange="toggleShiftMode()">
                            <i class="fas fa-calendar-day"></i> Single Shift
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="radio" name="shift_mode" value="multiple" onchange="toggleShiftMode()">
                            <i class="fas fa-calendar-week"></i> Multiple Days
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="radio" name="shift_mode" value="template" onchange="toggleShiftMode()">
                            <i class="fas fa-save"></i> Save as Template
                        </label>
                    </div>
                </div>

                <!-- Quick Template Loader -->
                <?php if (!empty($templates)): ?>
                    <div class="template-loader"
                        style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; border: 1px solid #bbdefb;">
                        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600;">
                                <i class="fas fa-lightning-bolt"></i> Quick Load Template:
                            </label>
                            <select id="template-selector" class="form-control" style="max-width: 250px;">
                                <option value="">-- Select a template --</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo htmlspecialchars(json_encode($template)); ?>">
                                        <?php echo htmlspecialchars($template['template_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="loadTemplate()" class="quick-time-btn">
                                <i class="fas fa-download"></i> Load
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="user_id">
                            <i class="fas fa-user"></i>
                            Assign To User:
                        </label>
                        <select name="user_id" id="user_id" class="form-control" required>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo (isset($user_id) && $user_id == $user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="role_id">
                            <i class="fas fa-user-tag"></i>
                            Role:
                        </label>
                        <select name="role_id" id="role_id" class="form-control" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Single Date Input -->
                    <div class="form-group" id="single-date-group">
                        <label for="shift_date">
                            <i class="fas fa-calendar-alt"></i>
                            Date:
                        </label>
                        <input type="date" name="shift_date" id="shift_date" class="form-control"
                            value="<?php echo $default_date; ?>" required>
                    </div>

                    <!-- Multiple Dates Input -->
                    <div class="form-group" id="multiple-dates-group" style="display: none;">
                        <label>
                            <i class="fas fa-calendar-week"></i>
                            Multiple Dates:
                        </label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <label for="start_date" style="font-size: 0.9em; margin-bottom: 5px;">From:</label>
                                <input type="date" name="start_date" id="start_date" class="form-control"
                                    value="<?php echo $default_date; ?>">
                            </div>
                            <div>
                                <label for="end_date" style="font-size: 0.9em; margin-bottom: 5px;">To:</label>
                                <input type="date" name="end_date" id="end_date" class="form-control"
                                    value="<?php echo date('Y-m-d', strtotime('+6 days')); ?>">
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 0.9em; margin-bottom: 5px;">Days of Week:</label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="1" checked> Mon</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="2" checked> Tue</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="3" checked> Wed</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="4" checked> Thu</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="5" checked> Fri</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="6"> Sat</label>
                                <label style="font-size: 0.85em; cursor: pointer;"><input type="checkbox" name="days[]"
                                        value="0"> Sun</label>
                            </div>
                        </div>
                    </div>

                    <!-- Template Name Input -->
                    <div class="form-group" id="template-name-group" style="display: none;">
                        <label for="template_name">
                            <i class="fas fa-tag"></i>
                            Template Name:
                        </label>
                        <input type="text" name="template_name" id="template_name" class="form-control"
                            placeholder="e.g., Morning Shift, Manager Coverage">
                        <small class="form-help">Save this shift configuration for quick reuse</small>
                    </div>

                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'super_admin'): ?>
                        <div class="form-group">
                            <label>
                                <i class="fas fa-map-marker-alt"></i>
                                Location / Branch:
                            </label>
                            <div
                                style="padding:10px 12px; background:#f7f9fb; border-radius:8px; border:1px solid #e6e6e6;">
                                <?php echo htmlspecialchars($default_location); ?>
                            </div>
                            <input type="hidden" name="location" value="<?php echo htmlspecialchars($default_location); ?>">
                            <input type="hidden" name="branch_id"
                                value="<?php echo htmlspecialchars($default_branch_id); ?>">
                            <small class="hint">Shifts are restricted to your branch.</small>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label for="location">
                                <i class="fas fa-map-marker-alt"></i>
                                Location:
                            </label>
                            <input type="text" name="location" id="location" class="form-control"
                                value="<?php echo htmlspecialchars($default_location); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="branch_id">
                                <i class="fas fa-code-branch"></i>
                                Branch (optional):
                            </label>
                            <select name="branch_id" id="branch_id" class="form-control">
                                <option value="">-- Keep as typed location --</option>
                                <?php foreach ($all_branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" <?php echo (isset($default_branch_id) && $default_branch_id == $b['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="hint">Select a branch to prefill the location (admin only).</small>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="start_time">
                            <i class="fas fa-clock"></i>
                            Start Time:
                        </label>
                        <input type="time" name="start_time" id="start_time" class="form-control" required>
                        <div style="margin-top: 5px; display: flex; gap: 5px; flex-wrap: wrap;">
                            <button type="button" class="quick-time-btn"
                                onclick="setQuickTime('09:00', '17:00')">9-5</button>
                            <button type="button" class="quick-time-btn"
                                onclick="setQuickTime('08:00', '16:00')">8-4</button>
                            <button type="button" class="quick-time-btn"
                                onclick="setQuickTime('10:00', '18:00')">10-6</button>
                            <button type="button" class="quick-time-btn"
                                onclick="setQuickTime('22:00', '06:00')">Night</button>
                            <button type="button" class="quick-time-btn" onclick="setQuickTime('18:30', '06:30')">Night
                                GXO</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="end_time">
                            <i class="fas fa-clock"></i>
                            End Time:
                        </label>
                        <input type="time" name="end_time" id="end_time" class="form-control" required>
                        <div style="margin-top: 5px; display: flex; gap: 5px;">
                            <button type="button" class="quick-day-btn" onclick="toggleWeekdays(true)">Weekdays</button>
                            <button type="button" class="quick-day-btn" onclick="toggleWeekdays(false)">Clear
                                All</button>
                        </div>
                    </div>
                </div>

                <div class="form-buttons">
                    <button type="button" class="admin-btn secondary"
                        onclick="location.href='<?php echo htmlspecialchars($return_url); ?>'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="admin-btn primary">
                        <i class="fas fa-plus"></i> Add Shift
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
    <script>
        // Branch selection logic
        (function () {
            const branchSelect = document.getElementById('branch_id');
            const locationInput = document.getElementById('location');
            if (!branchSelect) return;

            branchSelect.addEventListener('change', function () {
                const id = this.value;
                if (!id) return;
                // Try to find the option text and set as location
                const opt = this.options[this.selectedIndex];
                if (opt && opt.text) {
                    locationInput.value = opt.text;
                }
            });
        })();

        // Shift mode switching
        function toggleShiftMode() {
            const mode = document.querySelector('input[name="shift_mode"]:checked').value;
            const singleGroup = document.getElementById('single-date-group');
            const multipleGroup = document.getElementById('multiple-dates-group');
            const templateGroup = document.getElementById('template-name-group');
            const submitBtn = document.querySelector('button[type="submit"]');

            // Hide all groups first
            singleGroup.style.display = 'none';
            multipleGroup.style.display = 'none';
            templateGroup.style.display = 'none';

            // Show relevant group based on mode
            switch (mode) {
                case 'single':
                    singleGroup.style.display = 'block';
                    submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Shift';
                    break;
                case 'multiple':
                    multipleGroup.style.display = 'block';
                    submitBtn.innerHTML = '<i class="fas fa-calendar-week"></i> Add Multiple Shifts';
                    break;
                case 'template':
                    singleGroup.style.display = 'block';
                    templateGroup.style.display = 'block';
                    submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Template';
                    break;
            }
        }

        // Quick fill times
        function setQuickTime(start, end) {
            document.getElementById('start_time').value = start;
            document.getElementById('end_time').value = end;
        }

        // Select/deselect all weekdays
        function toggleWeekdays(selectAll) {
            const checkboxes = document.querySelectorAll('input[name="days[]"]');
            checkboxes.forEach(cb => {
                if (selectAll) {
                    cb.checked = (cb.value >= 1 && cb.value <= 5); // Mon-Fri only
                } else {
                    cb.checked = false;
                }
            });
        }

        // Load template data into form
        function loadTemplate() {
            const selector = document.getElementById('template-selector');
            const templateData = selector.value;

            if (!templateData) return;

            try {
                const template = JSON.parse(templateData);

                // Fill form fields
                document.getElementById('role_id').value = template.role_id;
                document.getElementById('start_time').value = template.start_time;
                document.getElementById('end_time').value = template.end_time;
                document.getElementById('location').value = template.location;

                if (template.branch_id && document.getElementById('branch_id')) {
                    document.getElementById('branch_id').value = template.branch_id;
                }

                // Clear template selector
                selector.value = '';

                // Show success message
                const msg = document.createElement('div');
                msg.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 10px 20px; border-radius: 4px; z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.2)';
                msg.textContent = 'Template loaded successfully!';
                document.body.appendChild(msg);
                setTimeout(() => msg.remove(), 3000);

            } catch (e) {
                alert('Error loading template: ' + e.message);
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function () {
            toggleShiftMode();
        });
    </script>
</body>

</html>