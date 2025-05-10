<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin();

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
            $allowed = ['xls', 'xlsx'];
            $filename = $_FILES['shift_file']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if (!in_array(strtolower($ext), $allowed)) {
                $error = "Please upload a valid Excel file (xls, xlsx).";
            } else {
                require '../vendor/autoload.php';

                $reader = $ext == 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                $spreadsheet = $reader->load($_FILES['shift_file']['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray(null, true, true, true);

                $date_columns = [];
                foreach ($rows[1] as $col => $val) {
                    if (preg_match('/\\d{1,2}\\w{2}/', $val)) {
                        $date_columns[$col] = trim(explode("\\n", $val)[0]);
                    }
                }

                $stmt = $conn->query("SELECT id, username FROM users");
                $users = [];
                while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $users[$user['username']] = $user['id'];
                }

                $stmt = $conn->query("SELECT id, name FROM roles");
                $roles = [];
                while ($role = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $roles[$role['name']] = $role['id'];
                }

                $insert = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");

                $current_user = null;
                $current_role = null;

                foreach ($rows as $row) {
                    $first_cell = trim($row['A']);

                    if (preg_match('/^[A-Z],/', $first_cell)) {
                        $parts = explode('-', $first_cell);
                        $current_user = trim($parts[0]);
                        $current_role = isset($parts[1]) ? trim($parts[1]) : null;
                    } elseif ($current_user && $current_role) {
                        foreach ($date_columns as $col => $date_str) {
                            $cell_val = isset($row[$col]) ? trim($row[$col]) : '';

                            if (empty($cell_val) || stripos($cell_val, 'day off') !== false) {
                                continue;
                            }

                            // Extract time and location
                            if (preg_match('/(\\d{1,2}:\\d{2}).*?-(\\d{1,2}:\\d{2})/', $cell_val, $time_matches)) {
                                $start_time = $time_matches[1];
                                $end_time = $time_matches[2];

                                preg_match('/\\((.*?)\\)/', $cell_val, $location_matches);
                                $location = isset($location_matches[1]) ? $location_matches[1] : '';

                                // Match user
                                $parts = array_map('trim', explode(',', $current_user));
                                if (count($parts) != 2) {
                                    $failed_shifts++;
                                    continue;
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

                                        if (strcasecmp($db_first_name, $first_name) === 0 && $last_initial === $db_last_initial) {
                                            $matched_user_id = $user_id;
                                            break;
                                        }
                                    }
                                }

                                if (!$matched_user_id || !isset($roles[$current_role])) {
                                    $failed_shifts++;
                                    continue;
                                }

                                // Parse date (you might need to map week start + date_str here)
                                $week_start = ''; // Extract from sheet title if needed
                                $shift_date = ''; // TODO: calculate from week_start + date_str

                                try {
                                    $insert->execute([$matched_user_id, $shift_date, $start_time, $end_time, $roles[$current_role], $location]);
                                    $uploaded_shifts++;

                                    $notifMessage = "A new shift on {$shift_date} from {$start_time} to {$end_time} has been added to your schedule by management.";
                                    addNotification($conn, $matched_user_id, $notifMessage, "info");
                                } catch (PDOException $e) {
                                    $failed_shifts++;
                                }
                            }
                        }
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