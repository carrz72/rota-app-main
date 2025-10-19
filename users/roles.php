<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/notifications.php';
require_once '../includes/PermissionManager.php';

$user_id = $_SESSION['user_id'];
$permissions = new PermissionManager($conn, $user_id);

// Fetch all roles globally for every user
$stmt = $conn->query("SELECT * FROM roles ORDER BY name ASC");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user-specific data for header
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        try {
            if (!document.documentElement.getAttribute('data-theme')) {
                var saved = localStorage.getItem('rota_theme');
                if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) { }
    </script>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <title>Manage Roles - Open Rota</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/role.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <style>
        [data-theme="dark"] .page-header,
        [data-theme="dark"] .current-branch-info {
            background: transparent !important;
            color: var(--text) !important;
        }
    </style>
    <style>
        /* Page-specific dark mode fixes for Roles page */
        html[data-theme='dark'] body {

            color: var(--text) !important;
        }

        html[data-theme='dark'] .container {
            background: var(--panel) !important;
            color: var(--text) !important;
            box-shadow: var(--card-shadow) !important;
            background-image: none !important;
        }

        html[data-theme='dark'] form,
        html[data-theme='dark'] .form-card,
        html[data-theme='dark'] .role-card,
        html[data-theme='dark'] table,
        html[data-theme='dark'] table thead,
        html[data-theme='dark'] table tbody,
        html[data-theme='dark'] table td,
        html[data-theme='dark'] table th {
            background: var(--panel) !important;
            color: var(--text) !important;
            border-color: rgba(255, 255, 255, 0.03) !important;
            box-shadow: var(--card-shadow) !important;
        }

        /* Remove light hover and keep rows neutral */
        html[data-theme='dark'] table tbody tr:hover,
        html[data-theme='dark'] table tr:hover {
            background: transparent !important;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Ensure headings, labels and inputs are readable */
        html[data-theme='dark'] h1,
        html[data-theme='dark'] h2,
        html[data-theme='dark'] label,
        html[data-theme='dark'] .role-name {
            color: var(--text) !important;
        }

        html[data-theme='dark'] input[type="text"],
        html[data-theme='dark'] input[type="number"],
        html[data-theme='dark'] input[type="time"],
        html[data-theme='dark'] select {
            background: #08101a !important;
            color: var(--text) !important;
            border-color: #17232b !important;
        }

        /* Buttons and action controls */
        html[data-theme='dark'] button,
        html[data-theme='dark'] .action-btn,
        html[data-theme='dark'] a {
            background: linear-gradient(135deg, var(--accent), #ff3b3b) !important;
            color: #fff !important;
            border: none !important;
        }

        html[data-theme='dark'] button:hover,
        html[data-theme='dark'] .action-btn:hover,
        html[data-theme='dark'] a:hover {
            background: #ff3b3b !important;
            transform: none !important;
        }

        /* Header, nav and icons */
        html[data-theme='dark'] header,
        html[data-theme='dark'] header * {
            background: transparent !important;
            color: var(--text) !important;
        }

        html[data-theme='dark'] .notification-icon {
            color: var(--text) !important;
        }

        /* Catch inline white backgrounds */
        html[data-theme='dark'] [style*="background:#fff"],
        html[data-theme='dark'] [style*="background: #fff"],
        html[data-theme='dark'] [style*="background:#ffffff"],
        html[data-theme='dark'] [style*="background: #ffffff"],
        html[data-theme='dark'] [style*="background: white"] {
            background: var(--panel) !important;
            color: var(--text) !important;
        }

        html[data-theme='dark'] .toggle-container,
        #night_pay_fields {
            background: var(--panel) !important;
            color: var(--text) !important;
        }

        html[data-theme='dark'] #night_pay_fields h3 {
            color: var(--text) !important;
        }
    </style>
    <?php
    if (isset($_SESSION['user_id'])) {
        try {
            $stmtTheme = $conn->prepare('SELECT theme FROM users WHERE id = ? LIMIT 1');
            $stmtTheme->execute([$_SESSION['user_id']]);
            $row = $stmtTheme->fetch(PDO::FETCH_ASSOC);
            $userTheme = $row && !empty($row['theme']) ? $row['theme'] : null;
            if ($userTheme === 'dark') {
                echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
            }
        } catch (Exception $e) {
        }
    }
    ?>
</head>

<body>
    <!-- Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        <div class="nav-group">
            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if ($notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>

                <!-- Notifications Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?php if ($notification['type'] === 'shift-invite' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>"
                                    href="/functions/pending_shift_invitations.php?invitation_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php elseif ($notification['type'] === 'shift-swap' && !empty($notification['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>"
                                    href="/functions/pending_shift_swaps.php?swap_id=<?php echo $notification['related_id']; ?>&notif_id=<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </a>
                            <?php else: ?>
                                <div class="notification-item notification-<?php echo $notification['type']; ?>"
                                    data-id="<?php echo $notification['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notification['message']); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <p>No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="menu-toggle" id="menu-toggle">
                ☰
            </div>
        </div>

        <nav class="nav-links" id="nav-links">
            <ul>
                <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                <li><a href="roles.php"><i class="fa fa-users"></i> Roles</a></li>
                <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                <li><a href="chat.php"><i class="fa fa-comments"></i> Team Chat</a></li>
                <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                <?php if (isset($_SESSION['role']) && (($_SESSION['role'] === 'admin') || ($_SESSION['role'] === 'super_admin'))): ?>
                    <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                <?php endif; ?>
                <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
            </ul>
        </nav>
    </header>

    <div class="container">
        <h1>Role Management</h1>

        <!-- Display notifications -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message">
                <?php
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?php
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <!-- List of Existing Roles -->
        <section class="roles-list">
            <h2><i class="fa fa-briefcase"></i> Your Role Library</h2>

            <?php if (count($roles) > 0): ?>
                <?php foreach ($roles as $role): ?>
                    <div class="role-card">
                        <div class="role-details">
                            <div class="role-name"><?php echo htmlspecialchars($role['name']); ?></div>

                            <!-- Pay Details Section -->
                            <div class="pay-details-container">
                                <!-- Employment Type -->
                                <div class="pay-detail">
                                    <div class="pay-label"><i class="fa fa-user"></i> Type:</div>
                                    <div class="pay-value">
                                        <?php echo ucfirst($role['employment_type'] ?? 'hourly'); ?> Staff
                                    </div>
                                </div>

                                <?php if (($role['employment_type'] ?? 'hourly') === 'hourly'): ?>
                                    <!-- Hourly Pay -->
                                    <div class="pay-detail">
                                        <div class="pay-label"><i class="fa fa-money"></i> Hourly Rate:</div>
                                        <div class="pay-value">£<?php echo number_format($role['base_pay'], 2); ?> per hour</div>
                                    </div>
                                <?php endif; ?>

                                <!-- Night Shift Pay (if applicable and hourly) -->
                                <?php if ($role['has_night_pay'] && ($role['employment_type'] ?? 'hourly') === 'hourly'): ?>
                                    <div class="pay-detail">
                                        <div class="pay-label"><i class="fa fa-moon-o"></i> Night Pay:</div>
                                        <div class="pay-value">£<?php echo number_format($role['night_shift_pay'], 2); ?> per hour
                                        </div>
                                    </div>
                                    <div class="pay-detail time-range">
                                        <div class="pay-label"><i class="fa fa-clock-o"></i> Night Hours:</div>
                                        <div class="pay-value">
                                            <?php echo date("g:i A", strtotime($role['night_start_time'])); ?> -
                                            <?php echo date("g:i A", strtotime($role['night_end_time'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($permissions->canManageRoles()): ?>
                            <div class="role-actions">
                                <button class="action-btn edit-btn" onclick="editRole(<?php echo $role['id']; ?>)">
                                    <i class="fa fa-pencil"></i> Edit
                                </button>
                                <button class="action-btn delete-btn"
                                    onclick="confirmDelete(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['name']); ?>')">
                                    <i class="fa fa-trash"></i> Delete
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No roles defined yet. Create your first role below.</p>
            <?php endif; ?>
        </section>

        <!-- Add New Role Form -->
        <?php if ($permissions->canManageRoles()): ?>
            <section class="form-card">
                <h2><i class="fa fa-plus-circle"></i> Add New Role</h2>
                <form action="/functions/create_role.php" method="POST" id="roleForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Role Name:</label>
                            <input type="text" id="name" name="name" required placeholder="e.g. Manager, Server, Barista">
                        </div>

                        <div class="form-group">
                            <label for="employment_type">Employment Type:</label>
                            <select id="employment_type" name="employment_type" onchange="togglePayFields()" required>
                                <option value="hourly">Hourly Staff</option>
                                <option value="salaried">Salaried Staff</option>
                            </select>
                        </div>

                        <div class="form-group" id="hourly_pay_group">
                            <label for="base_pay">Hourly Rate (£):</label>
                            <input type="number" step="0.01" min="0" id="base_pay" name="base_pay" placeholder="e.g. 10.50">
                        </div>

                        <div class="form-group" id="salary_pay_group" style="display: none;">
                            <label for="monthly_salary">Monthly Salary (£):</label>
                            <input type="number" step="0.01" min="0" id="monthly_salary" name="monthly_salary"
                                placeholder="e.g. 2500.00">
                        </div>

                        <div class="form-full-width" id="night_pay_toggle">
                            <div class="toggle-container">
                                <label for="has_night_pay">Enable Night Shift Pay (Hourly Only):</label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="has_night_pay" name="has_night_pay"
                                        onclick="toggleNightPayFields()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div id="night_pay_fields" style="display: none; margin-top: 20px;">
                        <h3>Night Shift Settings</h3>
                        <div class="night-pay-grid">
                            <div class="form-group">
                                <label for="night_shift_pay">Night Shift Pay Rate (£):</label>
                                <input type="number" step="0.01" min="0" id="night_shift_pay" name="night_shift_pay"
                                    placeholder="e.g. 12.50">
                            </div>

                            <div class="form-group">
                                <label for="night_start_time">Night Shift Starts At:</label>
                                <input type="time" id="night_start_time" name="night_start_time">
                            </div>

                            <div class="form-group">
                                <label for="night_end_time">Night Shift Ends At:</label>
                                <input type="time" id="night_end_time" name="night_end_time">
                            </div>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="button" class="action-btn" onclick="resetForm()">Reset</button>
                        <button type="submit" class="action-btn">Create Role</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <div style="margin-top: 20px; text-align: center;">
            <a href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div id="editRoleModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2><i class="fa fa-edit"></i> Edit Role</h2>
            <form id="editRoleForm">
                <input type="hidden" id="edit_role_id" name="edit_role_id">

                <div class="form-group">
                    <label for="edit_name">Role Name:</label>
                    <input type="text" id="edit_name" name="edit_name" required>
                </div>

                <div class="form-group">
                    <label for="edit_employment_type">Employment Type:</label>
                    <select id="edit_employment_type" name="edit_employment_type" onchange="toggleEditPayFields()"
                        required>
                        <option value="hourly">Hourly Paid</option>
                        <option value="salaried">Salaried</option>
                    </select>
                </div>

                <div id="edit_hourly_pay_group" class="form-group">
                    <label for="edit_base_pay">Hourly Pay Rate (£):</label>
                    <input type="number" step="0.01" min="0" id="edit_base_pay" name="edit_base_pay">
                </div>

                <div id="edit_salary_pay_group" class="form-group" style="display: none;">
                    <label for="edit_annual_salary">Annual Salary (£):</label>
                    <input type="number" step="0.01" min="0" id="edit_annual_salary" name="edit_annual_salary">
                </div>

                <div id="edit_night_pay_toggle" class="form-group">
                    <div class="toggle-container">
                        <label for="edit_has_night_pay">Enable Night Shift Pay:</label>
                        <label class="toggle-switch">
                            <input type="checkbox" id="edit_has_night_pay" name="edit_has_night_pay"
                                onclick="toggleEditNightPayFields()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <div id="edit_night_pay_fields" style="display: none; margin-top: 20px;">
                    <h3>Night Shift Settings</h3>
                    <div class="night-pay-grid">
                        <div class="form-group">
                            <label for="edit_night_shift_pay">Night Shift Pay Rate (£):</label>
                            <input type="number" step="0.01" min="0" id="edit_night_shift_pay"
                                name="edit_night_shift_pay">
                        </div>

                        <div class="form-group">
                            <label for="edit_night_start_time">Night Shift Starts At:</label>
                            <input type="time" id="edit_night_start_time" name="edit_night_start_time">
                        </div>

                        <div class="form-group">
                            <label for="edit_night_end_time">Night Shift Ends At:</label>
                            <input type="time" id="edit_night_end_time" name="edit_night_end_time">
                        </div>
                    </div>
                </div>

                <div class="form-footer">
                    <button type="button" class="action-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="action-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Notification functionality
        function markAsRead(element) {
            const notificationId = element.getAttribute('data-id');
            fetch('/functions/mark_notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: notificationId })
            })
                .then(response => response.json())
                .then(data => {
                    console.log('Response data:', data); // Debug log
                    if (data.success) {
                        element.style.display = 'none';

                        // Count remaining visible notifications more reliably
                        const allNotifications = document.querySelectorAll('.notification-item[data-id]');
                        let visibleCount = 0;

                        allNotifications.forEach(notification => {
                            const computedStyle = window.getComputedStyle(notification);
                            if (computedStyle.display !== 'none') {
                                visibleCount++;
                            }
                        });

                        console.log('Total notifications with data-id:', allNotifications.length); // Debug log
                        console.log('Visible notifications count:', visibleCount); // Debug log

                        if (visibleCount === 0) {
                            document.getElementById('notification-dropdown').innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.style.display = 'none';
                                console.log('Badge hidden - no notifications left'); // Debug log
                            }
                        } else {
                            const badge = document.querySelector('.notification-badge');
                            if (badge) {
                                badge.textContent = visibleCount;
                                badge.style.display = 'flex'; // Ensure badge is visible
                                console.log('Badge updated to:', visibleCount); // Debug log
                            }
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data.error);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Function to toggle pay fields based on employment type
        function togglePayFields() {
            const employmentType = document.getElementById("employment_type").value;
            const hourlyGroup = document.getElementById("hourly_pay_group");
            const salaryGroup = document.getElementById("salary_pay_group");
            const nightPayToggle = document.getElementById("night_pay_toggle");
            const nightPayFields = document.getElementById("night_pay_fields");
            const hasNightPay = document.getElementById("has_night_pay");

            if (employmentType === 'salaried') {
                hourlyGroup.style.display = 'none';
                salaryGroup.style.display = 'block';
                nightPayToggle.style.display = 'none';
                nightPayFields.style.display = 'none';
                hasNightPay.checked = false;

                // Make annual salary required, hourly not required
                const annual = document.getElementById("annual_salary");
                if (annual) annual.required = true;
                document.getElementById("base_pay").required = false;
            } else {
                hourlyGroup.style.display = 'block';
                salaryGroup.style.display = 'none';
                nightPayToggle.style.display = 'block';

                // Make hourly required, annual salary not required
                document.getElementById("base_pay").required = true;
                const annual = document.getElementById("annual_salary");
                if (annual) annual.required = false;
            }
        }

        // Function to toggle night pay fields in add form
        function toggleNightPayFields() {
            const checkbox = document.getElementById("has_night_pay");
            const container = document.getElementById("night_pay_fields");
            container.style.display = checkbox.checked ? "block" : "none";

            // Make fields required or not based on checkbox
            const nightFields = ['night_shift_pay', 'night_start_time', 'night_end_time'];
            nightFields.forEach(id => {
                const field = document.getElementById(id);
                if (field) {
                    field.required = checkbox.checked;
                }
            });
        }

        // Function to toggle night pay fields in edit form
        function toggleEditNightPayFields() {
            const checkbox = document.getElementById("edit_has_night_pay");
            const container = document.getElementById("edit_night_pay_fields");
            container.style.display = checkbox.checked ? "block" : "none";

            // Make fields required or not based on checkbox
            const nightFields = ['edit_night_shift_pay', 'edit_night_start_time', 'edit_night_end_time'];
            nightFields.forEach(id => {
                document.getElementById(id).required = checkbox.checked;
            });
        }

        // Function to toggle pay fields based on employment type in edit form
        function toggleEditPayFields() {
            const employmentType = document.getElementById("edit_employment_type").value;
            const hourlyGroup = document.getElementById("edit_hourly_pay_group");
            const salaryGroup = document.getElementById("edit_salary_pay_group");
            const nightPayToggle = document.getElementById("edit_night_pay_toggle");
            const nightPayFields = document.getElementById("edit_night_pay_fields");
            const hasNightPay = document.getElementById("edit_has_night_pay");

            if (employmentType === 'salaried') {
                hourlyGroup.style.display = 'none';
                salaryGroup.style.display = 'block';
                nightPayToggle.style.display = 'none';
                nightPayFields.style.display = 'none';
                hasNightPay.checked = false;
                // Make annual salary required, hourly not required
                const editAnnual = document.getElementById("edit_annual_salary");
                if (editAnnual) editAnnual.required = true;
                document.getElementById("edit_base_pay").required = false;
            } else {
                hourlyGroup.style.display = 'block';
                salaryGroup.style.display = 'none';
                nightPayToggle.style.display = 'block';

                // Make hourly required, annual salary not required
                document.getElementById("edit_base_pay").required = true;
                const editAnnual = document.getElementById("edit_annual_salary");
                if (editAnnual) editAnnual.required = false;
            }
        }

        // Reset form function
        function resetForm() {
            document.getElementById("roleForm").reset();
            toggleNightPayFields();
        }

        // Modal functions
        function openModal() {
            document.getElementById("editRoleModal").style.display = "block";
        }

        function closeModal() {
            document.getElementById("editRoleModal").style.display = "none";
            // Reset the form to clear any data
            document.getElementById("editRoleForm").reset();
        }

        // When the user clicks anywhere outside of the modal, close it
        window.onclick = function (event) {
            const modal = document.getElementById("editRoleModal");
            if (event.target === modal) {
                closeModal();
            }
        };

        // Edit role function
        function editRole(roleId) {
            // Get role data from server (include credentials so cookies are sent in PWA/standalone)
            fetch(`/functions/get_role.php?id=${roleId}`, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(role => {
                    // Populate the edit form
                    document.getElementById("edit_role_id").value = role.id;
                    document.getElementById("edit_name").value = role.name;

                    // Set employment type and related fields
                    const employmentType = role.employment_type || 'hourly';
                    document.getElementById("edit_employment_type").value = employmentType;

                    if (employmentType === 'hourly') {
                        document.getElementById("edit_base_pay").value = role.base_pay;
                    } else {
                        // Convert stored monthly salary to annual for editing
                        const annual = role.monthly_salary ? (parseFloat(role.monthly_salary) * 12).toFixed(2) : '';
                        document.getElementById("edit_annual_salary").value = annual;
                    }

                    // Handle night shift settings (only for hourly employees)
                    document.getElementById("edit_has_night_pay").checked = role.has_night_pay == 1;
                    if (role.has_night_pay == 1) {
                        document.getElementById("edit_night_shift_pay").value = role.night_shift_pay;
                        document.getElementById("edit_night_start_time").value = role.night_start_time;
                        document.getElementById("edit_night_end_time").value = role.night_end_time;
                    }

                    // Toggle display of pay and night fields
                    toggleEditPayFields();
                    toggleEditNightPayFields();

                    // Show the modal
                    openModal();
                })
                .catch(error => {
                    console.error('Error fetching role:', error);
                    alert("Error loading role data. Please try again.");
                });
        }

        // Handle edit form submission
        document.getElementById("editRoleForm").addEventListener("submit", function (event) {
            event.preventDefault();

            const employmentType = document.getElementById("edit_employment_type").value;

            // Create data object from form
            const roleData = {
                id: document.getElementById("edit_role_id").value,
                name: document.getElementById("edit_name").value,
                employment_type: employmentType,
                has_night_pay: document.getElementById("edit_has_night_pay").checked ? 1 : 0
            };

            // Add pay data based on employment type
            if (employmentType === 'hourly') {
                roleData.base_pay = document.getElementById("edit_base_pay").value;
            } else {
                // Convert annual salary input to monthly before sending to server
                const annualVal = document.getElementById("edit_annual_salary").value;
                roleData.monthly_salary = annualVal !== '' ? (parseFloat(annualVal) / 12).toFixed(2) : '';
            }

            // Add night shift data if enabled (only for hourly employees)
            if (roleData.has_night_pay && employmentType === 'hourly') {
                roleData.night_shift_pay = document.getElementById("edit_night_shift_pay").value;
                roleData.night_start_time = document.getElementById("edit_night_start_time").value;
                roleData.night_end_time = document.getElementById("edit_night_end_time").value;
            }

            // Send update to server
            fetch('/functions/edit_role.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(roleData),
            })
                .then(async response => {
                    const text = await response.text();
                    // Try to parse JSON even if content-type is wrong
                    try {
                        const data = JSON.parse(text);
                        return { ok: response.ok, data };
                    } catch (e) {
                        console.error('Invalid JSON response from /functions/edit_role.php:', text);
                        throw new Error('Invalid server response. See console for details.');
                    }
                })
                .then(({ ok, data }) => {
                    if (!ok) {
                        console.error('Server returned non-OK status for edit_role:', data);
                        alert('Error updating role: ' + (data.error || 'Server error'));
                        return;
                    }
                    if (data.error) {
                        console.error('Server error:', data.error);
                        alert('Error updating role: ' + data.error);
                        return;
                    }
                    // success
                    closeModal();
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error updating role:', error);
                    alert("Error updating role. Please try again. See console for details.");
                });
        });

        // Delete confirmation
        function confirmDelete(roleId, roleName) {
            if (!confirm(`Are you sure you want to delete the role "${roleName}"?`)) return;

            fetch('/functions/delete_role.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: roleId })
            })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        window.location.reload();
                    } else {
                        alert('Error deleting role: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error deleting role. See console for details.');
                });
        }

        // Ensure the correct display on page load
        document.addEventListener("DOMContentLoaded", function () {
            // Notification functionality
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

            if (notificationIcon && dropdown) {
                notificationIcon.addEventListener('click', function (e) {
                    e.stopPropagation();
                    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
                });
            }

            document.addEventListener('click', function (e) {
                if (dropdown && !dropdown.contains(e.target) && !notificationIcon.contains(e.target)) {
                    dropdown.style.display = "none";
                }
            });

            togglePayFields();
            toggleNightPayFields();

            // Set up close button functionality
            document.querySelector(".close-modal").addEventListener("click", closeModal);
        });
    </script>

    <script src="../js/darkmode.js"></script>
    <script src="../js/menu.js"></script>
    <script src="../js/pwa-debug.js"></script>
    <script src="../js/links.js"></script>
</body>

</html>