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

                $header_row = $rows[1];
                $first_cell = $rows[0][0];
                preg_match('/W\/C\s*(\d{2}\/\d{2}\/\d{4})/', $first_cell, $date_match);
                $week_start = isset($date_match[1]) ? date('Y-m-d', strtotime(str_replace('/', '-', $date_match[1]))) : null;

                if (!$week_start) {
                    $error = "Unable to determine week start date from file.";
                } else {
                    $data_rows = array_slice($rows, 3);

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

                    for ($i = 0; $i < count($data_rows); $i += 2) {
                        $name_row = $data_rows[$i];
                        $shift_row = $data_rows[$i + 1] ?? [];

                        $raw_name = trim($name_row[0]);
                        if (empty($raw_name)) continue;

                        $parts = array_map('trim', explode(',', $raw_name));
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

                        if (!$matched_user_id) {
                            $failed_shifts++;
                            continue;
                        }

                        for ($col = 1; $col <= 7; $col++) {
                            $date_cell = $header_row[$col] ?? null;
                            $shift_cell = $shift_row[$col] ?? '';
                            $role_cell = $name_row[$col] ?? '';

                            if (empty($shift_cell) || stripos($shift_cell, 'Day Off') !== false || stripos($shift_cell, 'Holiday') !== false) continue;

                            $shift_date = date('Y-m-d', strtotime("$week_start +" . ($col - 1) . " days"));

                            if (!preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(?:\s*\((.*?)\))?/', $shift_cell, $matches)) {
                                $failed_shifts++;
                                continue;
                            }

                            $start_time = $matches[1];
                            $end_time = $matches[2];
                            $location = $matches[3] ?? '';

                            $role_name = trim(preg_replace('/\s*\(.*\)/', '', $role_cell));
                            if (!isset($roles[$role_name])) {
                                $failed_shifts++;
                                continue;
                            }
                            $role_id = $roles[$role_name];

                            try {
                                $insert->execute([$matched_user_id, $shift_date, $start_time, $end_time, $role_id, $location]);
                                $uploaded_shifts++;

                                $formattedDate = date("D, M j, Y", strtotime($shift_date));
                                $formattedStart = date("g:i A", strtotime($start_time));
                                $formattedEnd = date("g:i A", strtotime($end_time));
                                $notifMessage = "A new shift on {$formattedDate} from {$formattedStart} to {$formattedEnd} has been added to your schedule by management.";
                                addNotification($conn, $matched_user_id, $notifMessage, "info");
                            } catch (PDOException $e) {
                                $failed_shifts++;
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
                <p>Upload an Excel file (.xls, .xlsx) or CSV file with the Shopworks format.</p>

                <form method="POST" enctype="multipart/form-data">
                    <p>
                        <label for="shift_file">Select Excel File:</label>
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
