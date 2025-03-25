<?php
require '../includes/auth.php';
requireAdmin();
require '../includes/db.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $role = $_POST['role'];

    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
    $stmt->execute([$username, $email, $role, $id]);

    header("Location: admin.php");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit User</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1>Edit User</h1>
    <form method="POST">
        <input type="text" name="username" value="<?php echo $user['username']; ?>" required>
        <input type="email" name="email" value="<?php echo $user['email']; ?>" required>
        <select name="role">
            <option value="user" <?php if ($user['role'] === 'user') echo 'selected'; ?>>User</option>
            <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>>Admin</option>
        </select>
        <button type="submit">Update</button>
    </form>
</body>
</html>
