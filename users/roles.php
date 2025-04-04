<?php
require_once '../includes/db.php';
require '../includes/auth.php';
include '../includes/header.php';

// Fetch all roles globally for every user
$stmt = $conn->query("SELECT * FROM roles ORDER BY name ASC");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Roles</title>
    <link rel="stylesheet" href="../css/role.css">
    <script>
        // Function to toggle night pay fields
        function toggleNightPayFields() {
            const checkbox = document.getElementById("has_night_pay");
            const container = document.getElementById("night_pay_container");
            container.style.display = checkbox.checked ? "block" : "none";
        }
        // Ensure the correct display on page load.
        document.addEventListener("DOMContentLoaded", function() {
            toggleNightPayFields();
        });
    </script>
</head>
<body>
    <div class="container">
        <h1>All Roles</h1>
        
        <!-- Display notifications -->
        <?php if(isset($_SESSION['success'])): ?>
            <div class="success-message">
                <?php 
                    echo $_SESSION['success']; 
                    unset($_SESSION['success']); 
                ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?php 
                    echo $_SESSION['error']; 
                    unset($_SESSION['error']); 
                ?>
            </div>
        <?php endif; ?>

        <!-- List of Existing Roles -->
        <?php if(count($roles) > 0): ?>
            <h2>Existing Roles</h2>
            <ul>
                <?php foreach($roles as $role): ?>
                    <li>
                        <?php echo htmlspecialchars($role['name']); ?> 
                        - Base Pay: <?php echo htmlspecialchars($role['base_pay']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No roles defined yet.</p>
        <?php endif; ?>

        <!-- Add New Role Form -->
        <h2>Add New Role</h2>
        <form action="../functions/create_role.php" method="POST">
            <p>
                <label for="name">Role Name:</label>
                <input type="text" id="name" name="name" required>
            </p>
            <p>
                <label for="base_pay">Base Pay:</label>
                <input type="number" step="0.01" id="base_pay" name="base_pay" required>
            </p>
            <p>
                <label for="has_night_pay">Has Night Pay:</label>
                <input type="checkbox" id="has_night_pay" name="has_night_pay" onclick="toggleNightPayFields()">
            </p>
            <div id="night_pay_container" style="display:none;">
                <p>
                    <label for="night_shift_pay">Night Shift Pay:</label>
                    <input type="number" step="0.01" id="night_shift_pay" name="night_shift_pay">
                </p>
                <p>
                    <label for="night_start_time">Night Start Time:</label>
                    <input type="time" id="night_start_time" name="night_start_time">
                </p>
                <p>
                    <label for="night_end_time">Night End Time:</label>
                    <input type="time" id="night_end_time" name="night_end_time">
                </p>
            </div>
            <button type="submit">Create Role</button>
        </form>
    </div>
</body>
</html>



