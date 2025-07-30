<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/notifications.php';

// Fetch all roles globally for every user
$stmt = $conn->query("SELECT * FROM roles ORDER BY name ASC");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user-specific data for header
$user_id = $_SESSION['user_id'];
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
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <title>Manage Roles - Open Rota</title>
    <link rel="stylesheet" href="../css/role.css">
</head>

<body>
    <?php include '../includes/header.php'; ?>

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
                                <?php else: ?>
                                    <!-- Monthly Salary -->
                                    <div class="pay-detail">
                                        <div class="pay-label"><i class="fa fa-money"></i> Monthly Salary:</div>
                                        <div class="pay-value">£<?php echo number_format($role['monthly_salary'], 2); ?> per month
                                        </div>
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
                        <div class="role-actions">
                            <button class="action-btn edit-btn" onclick="editRole(<?php echo $role['id']; ?>)">
                                <i class="fa fa-pencil"></i> Edit
                            </button>
                            <button class="action-btn delete-btn"
                                onclick="confirmDelete(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['name']); ?>')">
                                <i class="fa fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No roles defined yet. Create your first role below.</p>
            <?php endif; ?>
        </section>

        <!-- Add New Role Form -->
        <section class="form-card">
            <h2><i class="fa fa-plus-circle"></i> Add New Role</h2>
            <form action="../functions/create_role.php" method="POST" id="roleForm">
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
                    <label for="edit_monthly_salary">Monthly Salary (£):</label>
                    <input type="number" step="0.01" min="0" id="edit_monthly_salary" name="edit_monthly_salary">
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

                // Make salary required, hourly not required
                document.getElementById("monthly_salary").required = true;
                document.getElementById("base_pay").required = false;
            } else {
                hourlyGroup.style.display = 'block';
                salaryGroup.style.display = 'none';
                nightPayToggle.style.display = 'block';

                // Make hourly required, salary not required
                document.getElementById("base_pay").required = true;
                document.getElementById("monthly_salary").required = false;
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

                // Make salary required, hourly not required
                document.getElementById("edit_monthly_salary").required = true;
                document.getElementById("edit_base_pay").required = false;
            } else {
                hourlyGroup.style.display = 'block';
                salaryGroup.style.display = 'none';
                nightPayToggle.style.display = 'block';

                // Make hourly required, salary not required
                document.getElementById("edit_base_pay").required = true;
                document.getElementById("edit_monthly_salary").required = false;
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
            // Get role data from server
            fetch(`../functions/get_role.php?id=${roleId}`)
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
                        document.getElementById("edit_monthly_salary").value = role.monthly_salary;
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
                roleData.monthly_salary = document.getElementById("edit_monthly_salary").value;
            }

            // Add night shift data if enabled (only for hourly employees)
            if (roleData.has_night_pay && employmentType === 'hourly') {
                roleData.night_shift_pay = document.getElementById("edit_night_shift_pay").value;
                roleData.night_start_time = document.getElementById("edit_night_start_time").value;
                roleData.night_end_time = document.getElementById("edit_night_end_time").value;
            }

            // Send update to server
            fetch('../functions/edit_role.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(roleData),
            })
                .then(response => response.text())
                .then(result => {
                    closeModal();
                    // Reload page to show updated data
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error updating role:', error);
                    alert("Error updating role. Please try again.");
                });
        });

        // Delete confirmation
        function confirmDelete(roleId, roleName) {
            if (confirm(`Are you sure you want to delete the role "${roleName}"?`)) {
                // Submit to delete endpoint
                window.location.href = `../functions/delete_role.php?id=${roleId}`;
            }
        }

        // Ensure the correct display on page load
        document.addEventListener("DOMContentLoaded", function () {
            togglePayFields();
            toggleNightPayFields();

            // Set up close button functionality
            document.querySelector(".close-modal").addEventListener("click", closeModal);
        });
    </script>

    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>