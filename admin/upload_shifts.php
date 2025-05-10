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
$debug = [];

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

                if ($ext === 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                } elseif ($ext === 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                } else {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                }

                $spreadsheet = $reader->load($_FILES['shift_file']['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();

                $date_row = $rows[1];

                $stmt = $conn->query("SELECT id, username FROM users");
                $users = [];
                while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $users[strtolower($user['username'])] = $user['id'];
                }

                $stmt = $conn->query("SELECT id, name FROM roles");
                $roles = [];
                while ($role = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $roles[strtolower($role['name'])] = $role['id'];
                }

                $insert = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");

                for ($i = 3; $i < count($rows); $i += 2) {
                    $name_row = $rows[$i];
                    $details_row = $rows[$i + 1] ?? [];

                    $raw_name = trim($name_row[0] ?? '');
                    $raw_role = trim($name_row[1] ?? '');

                    if (empty($raw_name) || empty($raw_role)) {
                        $failed_shifts++;
                        $debug[] = "Row $i skipped: Missing name or role.";
                        continue;
                    }

                    if (strpos($raw_name, ',') === false) {
                        $failed_shifts++;
                        $debug[] = "Row $i skipped: Invalid name format '$raw_name'.";
                        continue;
                    }

                    $name_parts = array_map('trim', explode(',', $raw_name));
                    if (count($name_parts) >= 2) {
                        $last_initial = strtoupper($name_parts[0]);
                        $first_name = ucfirst(strtolower($name_parts[1]));
                    } else {
                        $failed_shifts++;
                        $debug[] = "Row $row_num skipped: Invalid name format '$raw_name'.";
                        continue;
                    }
                    
                    $last_initial = strtoupper($last_initial);
                    $first_name = ucfirst(strtolower($first_name));

                    $matched_user_id = null;
                    foreach ($users as $db_username => $user_id) {
                        $normalized_db_name = strtolower(preg_replace('/[^a-z]/i', '', $db_username));
                        $normalized_excel_name = strtolower(preg_replace('/[^a-z]/i', '', $first_name . $last_initial));
                        if (strpos($normalized_db_name, $normalized_excel_name) !== false) {
                            $matched_user_id = $user_id;
                            break;
                        }
                    }
                    

                    if (!$matched_user_id) {
                        $failed_shifts++;
                        $debug[] = "Row $i skipped: User '$raw_name' not found.";
                        continue;
                    }

                    for ($col = 2; $col < count($date_row); $col++) {
                        $date_raw = $date_row[$col];
                        $shift_label = trim($name_row[$col] ?? '');
                        $shift_info = trim($details_row[$col] ?? '');

                        $special_cases = ['day off', 'holiday', 'sick', 'available', 'off', 'vacation'];
                        if (in_array(strtolower($shift_label), $special_cases)) {
                            $debug[] = "Row $i Col $col skipped: Special case '$shift_label' ignored.";
                            $failed_shifts++;
                            continue;
                        }

                        if (!$shift_label || !$shift_info || !$date_raw) {
                            continue;
                        }

                        $matched_role = null;
                        foreach ($roles as $known_role => $role_id) {
                            if (stripos($known_role, $shift_label) !== false || stripos($shift_label, $known_role) !== false) {
                                $matched_role = $role_id;
                                break;
                            }
                        }

                        if (!$matched_role) {
                            $failed_shifts++;
                            $debug[] = "Row $i Col $col skipped: Role '$shift_label' not found.";
                            continue;
                        }

                        try {
                            $date = date('Y-m-d', strtotime($date_raw));
                        } catch (Exception $e) {
                            $failed_shifts++;
                            $debug[] = "Row $i Col $col skipped: Invalid date '$date_raw'.";
                            continue;
                        }

                        if (!preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $shift_info, $matches)) {
                            $failed_shifts++;
                            $debug[] = "Row $i Col $col skipped: Invalid time format '$shift_info'.";
                            continue;
                        }

                        $start_time = date('H:i:s', strtotime($matches[1]));
                        $end_time = date('H:i:s', strtotime($matches[2]));

                        $location = null; // Can enhance later if location is embedded

                        try {
                            $insert->execute([$matched_user_id, $date, $start_time, $end_time, $matched_role, $location]);
                            $uploaded_shifts++;

                            $formattedDate = date("D, M j, Y", strtotime($date));
                            $formattedStart = date("g:i A", strtotime($start_time));
                            $formattedEnd = date("g:i A", strtotime($end_time));
                            $notifMessage = "A new shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been added to your schedule by management.";
                            addNotification($conn, $matched_user_id, $notifMessage, "info");
                        } catch (PDOException $e) {
                            $failed_shifts++;
                            $debug[] = "Row $i Col $col skipped: DB error - " . $e->getMessage();
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

            <?php if (!empty($debug)): ?>
                <div class="debug-output">
                    <h3>Debug Output</h3>
                    <ul>
                        <?php foreach ($debug as $line): ?>
                            <li><?php echo htmlspecialchars($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <p>
                    <label for="shift_file">Select Excel File:</label>
                    <input type="file" name="shift_file" id="shift_file" accept=".xls,.xlsx,.csv" required>
                </p>
                <p>
                    <button type="submit">Upload Shifts</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
