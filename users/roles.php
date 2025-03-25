<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Manage Roles</title>
    <link rel="stylesheet" href="../css/role.css">
    <?php include '../includes/header.php'; ?>
    <script>
    function toggleNightPayFields() {
        var checkbox = document.getElementById('has_night_pay');
        var nightFields = document.getElementById('night_pay_fields');
        nightFields.style.display = checkbox.checked ? 'block' : 'none';
    }

    function editRole(id, currentName, currentPay) {
        let name = prompt("Enter new role name:", currentName);
        if (name === null) return;
        let base_pay = prompt("Enter new base pay:", currentPay);
        if (base_pay === null) return;

        let includeNightPay = confirm("Does this role include night pay details?");
        let data = { id: id, name: name, base_pay: base_pay };

        if (includeNightPay) {
            let night_shift_pay = prompt("Enter new night shift pay:");
            if (night_shift_pay === null) return;
            let night_start_time = prompt("Enter night start time (HH:MM):");
            if (night_start_time === null) night_start_time = "";
            let night_end_time = prompt("Enter night end time (HH:MM):");
            if (night_end_time === null) return;
            data.night_shift_pay = night_shift_pay;
            data.night_start_time = night_start_time;
            data.night_end_time = night_end_time;
        }

        fetch('../functions/edit_role.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(res => res.text())
        .then(msg => {
            alert(msg);
            location.reload();
        });
    }

    function deleteRole(id) {
        if (confirm("Are you sure you want to delete this role?")) {
            fetch('../functions/delete_role.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(res => res.text())
            .then(msg => {
                alert(msg);
                location.reload();
            });
        }
    }
    </script>
</head>
<body>
    <div class="container">
    <h1>Create a New Role</h1>
    <form method="post" action="../functions/create_role.php">
        <label for="name">Role Name:</label>
        <input type="text" id="name" name="name" required>
        
        <label for="base_pay">Base Pay:</label>
        <input type="number" step="0.01" id="base_pay" name="base_pay" required>
        
        <label>
            <input type="checkbox" id="has_night_pay" name="has_night_pay" onclick="toggleNightPayFields()">
            Has Night Pay
        </label>
        
        <div id="night_pay_fields">
            <label for="night_shift_pay">Night Shift Pay:</label>
            <input type="number" step="0.01" id="night_shift_pay" name="night_shift_pay">
            
            <label for="night_start_time">Night Start Time:</label>
            <input type="time" id="night_start_time" name="night_start_time">
            
            <label for="night_end_time">Night End Time:</label>
            <input type="time" id="night_end_time" name="night_end_time">
        </div>
        
        <button type="submit">Create Role</button>
    </form>

    <h2>Existing Roles</h2>
    <?php
    require_once __DIR__ . '/../includes/db.php';
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id, name, base_pay, night_shift_pay FROM roles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <table>
        <thead>
            <tr>
                <th>Role Name</th>
                <th>Base Pay</th>
                <th>Night Pay</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($roles): ?>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($role['name']); ?></td>
                        <td id="base-pay"><?php echo htmlspecialchars($role['base_pay']); ?></td>
                        <td id="night-pay"><?php echo htmlspecialchars($role['night_shift_pay']); ?></td>
                        <td id="actions">
                            <button class="action-btn" onclick="editRole(<?php echo $role['id']; ?>, '<?php echo addslashes(htmlspecialchars($role['name'])); ?>', '<?php echo htmlspecialchars($role['base_pay']); ?>')">Edit</button>
                            <button class="action-btn" onclick="deleteRole(<?php echo $role['id']; ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3">No roles found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</body>
</html>
