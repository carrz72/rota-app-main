<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin(); // Only allow admin access

// Check if PhpSpreadsheet is installed
if (!file_exists('../vendor/autoload.php')) {
    $installation_message = "PhpSpreadsheet not installed. Please run:<br>
        <code>composer require phpoffice/phpspreadsheet</code><br>
        in the root directory of this application.";
}

$message = '';
$error = '';
$uploaded_shifts = 0;
$failed_shifts = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($installation_message)) {
    try {
        if (isset($_FILES['shift_file']) && $_FILES['shift_file']['error'] == 0) {
            $allowed = ['xls', 'xlsx', 'csv'];
            $filename = $_FILES['shift_file']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if (!in_array(strtolower($ext), $allowed)) {
                $error = "Please upload a valid Excel file (xls, xlsx) or CSV file.";
            } else {
                require '../vendor/autoload.php';

                if ($ext == 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                } elseif ($ext == 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                } else {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                }

                $spreadsheet = $reader->load($_FILES['shift_file']['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();

                // Get all users
                $stmt = $conn->query("SELECT id, username FROM users");
                $users = [];
                while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $users[$user['username']] = $user['id'];
                }

                // Get all roles
                $stmt = $conn->query("SELECT id, name FROM roles");
                $roles = [];
                while ($role = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $roles[$role['name']] = $role['id'];
                }

                $insert = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");

                $last_username = null;
                $default_role_name = null;

                foreach ($rows as $row) {
                    if (count(array_filter($row)) === 0) continue;
                    $row = array_map('trim', $row);

                    // Detect name + role row
                    if (count(array_filter($row)) === 1 && strpos($row[0], ',') !== false) {
                        $header_parts = explode('-', $row[0], 2);
                        $last_username = trim($header_parts[0]);
                        $default_role_name = isset($header_parts[1]) ? trim($header_parts[1]) : null;
                        continue;
                    }

                    // Skip if we don't have a valid context
                    if (!$last_username || count(array_filter($row)) < 4) {
                        $failed_shifts++;
                        continue;
                    }

                    $date = $row[0];
                    $start_time = $row[1];
                    $end_time = $row[2];
                    $role_name = !empty($row[3]) ? $row[3] : $default_role_name;
                    $location = $row[4] ?? '';

                    // Parse name: "A, Carrington"
                    $parts = array_map('trim', explode(',', $last_username));
                    if (count($parts) != 2) {
                        $failed_shifts++;
                        continue;
                    }

                    $last_initial = strtoupper($parts[0]);
                    $first_name = $parts[1];

                    // Match user
                    $matched_user_id = null;
                    foreach ($users as $db_username => $user_id) {
                        $name_parts = explode(' ', $db_username);
                        if (count($name_parts) >= 2) {
                            $db_first_name = $name_parts[0];
                            $db_last_initial = strtoupper(substr($name_parts[1], 0, 1));
                            if (
                                strcasecmp($db_first_name, $first_name) === 0 &&
                                $last_initial === $db_last_initial
                            ) {
                                $matched_user_id = $user_id;
                                break;
                            }
                        }
                    }

                    // Fuzzy match role
                    $matched_role_id = null;
                    if ($role_name) {
                        $role_name_lower = strtolower($role_name);
                        foreach ($roles as $known_role => $role_id) {
                            if (stripos($known_role, $role_name_lower) !== false || stripos($role_name_lower, $known_role) !== false) {
                                $matched_role_id = $role_id;
                                break;
                            }
                        }
                    }

                    if (!$matched_user_id || !$matched_role_id) {
                        $failed_shifts++;
                        continue;
                    }

                    try {
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            $date = date('Y-m-d', strtotime($date));
                        }
                    } catch (Exception $e) {
                        $failed_shifts++;
                        continue;
                    }

                    try {
                        $insert->execute([$matched_user_id, $date, $start_time, $end_time, $matched_role_id, $location]);
                        $uploaded_shifts++;

                        $formattedDate = date("D, M j, Y", strtotime($date));
                        $formattedStart = date("g:i A", strtotime($start_time));
                        $formattedEnd = date("g:i A", strtotime($end_time));
                        $notifMessage = "A new shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been added to your schedule by management.";
                        addNotification($conn, $matched_user_id, $notifMessage, "info");
                    } catch (PDOException $e) {
                        $failed_shifts++;
                    }
                }

                if ($uploaded_shifts > 0) {
                    $message = "Successfully uploaded $uploaded_shifts shifts.";
                    if ($failed_shifts > 0) {
                        $message .= " $failed_shifts shifts failed to upload.";
                    }
                } else {
                    $error = "No shifts were uploaded. Please check your file format.";
                }
            }
        } else {
            $error = "Please select a file to upload.";
        }
    } catch (Exception $e) {
        $error = "An error occurred: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Shifts</title>
    <link rel="stylesheet" href="../css/upload_shift.css">
</head>
<body>
<div class="container">
    <h1>Upload Shifts</h1>
    <a href="admin_dashboard.php" class="action-button">Back to Dashboard</a>

    <?php if (isset($installation_message)): ?>
        <div class="error-message"><?php echo $installation_message; ?></div>
    <?php else: ?>
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="success-message"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="upload-form">
            <h2>Upload Shifts File</h2>
            <p>Upload an Excel file (.xls, .xlsx) or CSV file structured like this:</p>
            <ul>
                <li><strong>Name row:</strong> e.g., "A, Carrington"</li>
                <li><strong>Shift rows:</strong> Date | Start | End | Role | Location</li>
                <li>This repeats for each user.</li>
            </ul>
            <form method="POST" enctype="multipart/form-data">
                <p>
                    <label for="shift_file">Select File:</label>
                    <input type="file" name="shift_file" id="shift_file" accept=".xls,.xlsx,.csv" required>
                </p>
                <p>
                    <button type="submit">Upload Shifts</button>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
