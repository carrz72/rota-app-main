<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin(); // Only allow admin access

// Check if composer autoload exists for PhpSpreadsheet
if (!file_exists('../vendor/autoload.php')) {
    $installation_message = "PhpSpreadsheet not installed. Please run:<br>
        <code>composer require phpoffice/phpspreadsheet</code><br>
        in the root directory of this application.";
}

$message = '';
$error = '';
$uploaded_shifts = 0;
$failed_shifts = 0;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($installation_message)) {
    try {
        // Check if file was uploaded without errors
        if (isset($_FILES['shift_file']) && $_FILES['shift_file']['error'] == 0) {
            $allowed = ['xls', 'xlsx', 'csv'];
            $filename = $_FILES['shift_file']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if (!in_array(strtolower($ext), $allowed)) {
                $error = "Please upload a valid Excel file (xls, xlsx) or CSV file.";
            } else {
                // Process the file
                require '../vendor/autoload.php'; // Load PhpSpreadsheet

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

                // Skip header row
                array_shift($rows);

                // Get all users for validation
                $stmt = $conn->query("SELECT id, username FROM users");
                $users = [];
                while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $users[$user['username']] = $user['id'];
                }

                // Get all roles for validation
                $stmt = $conn->query("SELECT id, name FROM roles");
                $roles = [];
                while ($role = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $roles[$role['name']] = $role['id'];
                }

                // Prepare insert statement
                $insert = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");

                // Process each row
                foreach ($rows as $row) {
                    // Expected columns: Username, Date, Start Time, End Time, Role, Location
                    if (count($row) < 6 || empty($row[0])) {
                        $failed_shifts++;
                        continue; // Skip incomplete rows
                    }

                    $uploaded_username = trim($row[0]); // e.g., "A, Carrington"
                    $date = trim($row[1]);
                    $start_time = trim($row[2]);
                    $end_time = trim($row[3]);
                    $role_name = trim($row[4]);
                    $location = trim($row[5]);
                    
                    // Split on comma: [initial of last name], [first name]
                    $parts = array_map('trim', explode(',', $uploaded_username));
                    
                    if (count($parts) != 2) {
                        $failed_shifts++;
                        continue; // Skip if the username format is invalid
                    }
                    
                    $last_initial = strtoupper($parts[0]);
                    $first_name = $parts[1];
                    
                    $matched_user_id = null;
                    foreach ($users as $db_username => $user_id) {
                        $name_parts = explode(' ', $db_username);
                        if (count($name_parts) >= 2) {
                            $db_first_name = $name_parts[0];
                            $db_last_name = $name_parts[1];
                            $db_last_initial = strtoupper(substr($db_last_name, 0, 1));
                    
                            if (
                                strcasecmp($db_first_name, $first_name) === 0 &&
                                $last_initial === $db_last_initial
                            ) {
                                $matched_user_id = $user_id;
                                break;
                            }
                        }
                    }

                    if (!$matched_user_id) {
                        $failed_shifts++;
                        continue; // User not found
                    }

                    if (!isset($roles[$role_name])) {
                        $failed_shifts++;
                        continue; // Role not found
                    }

                    // Format date if needed
                    try {
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            $date = date('Y-m-d', strtotime($date));
                        }
                    } catch (Exception $e) {
                        $failed_shifts++;
                        continue; // Invalid date
                    }

                    // Insert the shift
                    $role_id = $roles[$role_name];

                    try {
                        $insert->execute([$matched_user_id, $date, $start_time, $end_time, $role_id, $location]);
                        $uploaded_shifts++;

                        // Notify the user
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
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
                <h2>Upload Shifts Excel File</h2>
                <p>Upload an Excel file (.xls, .xlsx) or CSV file with the following columns:</p>
                <ul>
                    <li>Username (must match existing user)</li>
                    <li>Date (YYYY-MM-DD or any recognizable date format)</li>
                    <li>Start Time (HH:MM:SS or HH:MM)</li>
                    <li>End Time (HH:MM:SS or HH:MM)</li>
                    <li>Role (must match existing role name)</li>
                    <li>Location</li>
                </ul>

                <form method="POST" enctype="multipart/form-data">
                    <p>
                        <label for="shift_file">Select Excel File:</label>
                        <input type="file" name="shift_file" id="shift_file" accept=".xls,.xlsx,.csv" required>
                    </p>
                    <p>
                        <button type="submit">Upload Shifts</button>
                    </p>
                </form>

                <div class="template-download">
                    <h3>Need a template?</h3>
                    <p>Download a <a href="">sample template</a> to get started.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>