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
            $allowed = ['xls', 'xlsx'];
            $filename = $_FILES['shift_file']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if (!in_array(strtolower($ext), $allowed)) {
                $error = "Please upload a valid Excel file (xls, xlsx).";
            } else {
                require '../vendor/autoload.php';

                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['shift_file']['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();

                // ================================================
                // 1. Extract Week Start Date from Cell A1
                // ================================================
                $headerText = $worksheet->getCell('A1')->getValue();
                $weekStartStr = trim(explode('W/C', $headerText)[1]);
                $startDate = DateTime::createFromFormat('d/m/Y', $weekStartStr);

                // ================================================
                // 2. Map Columns B-H to Actual Dates
                // ================================================
                $dateColumns = [];
                foreach (range('B', 'H') as $col) {
                    $dayCell = $worksheet->getCell($col . '2')->getValue();
                    preg_match('/\b(\d+)\w+/', $dayCell, $dayMatch);
                    $dayNumber = (int)$dayMatch[1];
                    $date = (clone $startDate)->modify("+" . ($dayNumber - $startDate->format('d')) . " days");
                    $dateColumns[$col] = $date->format('Y-m-d');
                }

                // ================================================
                // 3. Process Employee Rows and Shifts
                // ================================================
                $currentUserId = null;
                $currentRoleId = null;

                foreach ($worksheet->getRowIterator(3) as $row) { // Start at row 3
                    $rowIndex = $row->getRowIndex();
                    $cellA = $worksheet->getCell('A' . $rowIndex)->getValue();

                    // Detect employee rows (e.g., "B, Christine - Assistant Man…")
                    if (preg_match('/^([A-Z]),\s+([A-Za-z]+)\s+-/', $cellA, $nameMatches)) {
                        $lastInitial = $nameMatches[1];
                        $firstName = $nameMatches[2];

                        // Reconstruct username pattern (e.g., "Christine B%")
                        $usernamePattern = "$firstName $lastInitial%";

                        // Find user by username (without changing existing data)
                        $stmt = $conn->prepare("SELECT id FROM users WHERE username LIKE ?");
                        $stmt->execute([$usernamePattern]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        $currentUserId = $user['id'] ?? null;

                        // Get role from column B
                        $roleName = trim($worksheet->getCell('B' . $rowIndex)->getValue());
                        $stmt = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                        $stmt->execute(["%$roleName%"]);
                        $role = $stmt->fetch(PDO::FETCH_ASSOC);
                        $currentRoleId = $role['id'] ?? null;

                        if (!$currentUserId || !$currentRoleId) {
                            $debug[] = "Row $rowIndex skipped:";
                            if (!$currentUserId) $debug[] = "- User not found: $usernamePattern";
                            if (!$currentRoleId) $debug[] = "- Role not found: $roleName";
                            $currentUserId = null;
                            $currentRoleId = null;
                            continue;
                        }
                        continue;
                    }

                    // Process shifts for the current user
                    if ($currentUserId && $currentRoleId) {
                        foreach (range('B', 'H') as $col) {
                            $shiftCell = $worksheet->getCell($col . $rowIndex)->getValue();
                            $shiftCell = trim($shiftCell);

                            // Skip non-shift entries
                            if (empty($shiftCell) || in_array(strtolower($shiftCell), ['day off', 'holiday', 'sick', 'available'])) {
                                continue;
                            }

                            // Parse time and location
                            preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})(?:\s*\((.*)\))?/', $shiftCell, $matches);
                            if ($matches) {
                                try {
                                    $conn->beginTransaction();
                                    $stmt = $conn->prepare("INSERT INTO shifts 
                                        (user_id, shift_date, start_time, end_time, location, role_id) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt->execute([
                                        $currentUserId,
                                        $dateColumns[$col],
                                        $matches[1],
                                        $matches[2],
                                        $matches[3] ?? null,
                                        $currentRoleId
                                    ]);
                                    $uploaded_shifts++;

                                    // Add notification
                                    $formattedDate = date("M j, Y", strtotime($dateColumns[$col]));
                                    $notifMessage = "New shift: {$matches[1]} - {$matches[2]} on $formattedDate";
                                    addNotification($conn, $currentUserId, $notifMessage, "schedule");

                                    $conn->commit();
                                } catch (PDOException $e) {
                                    $conn->rollBack();
                                    $failed_shifts++;
                                    $debug[] = "DB Error (Row $rowIndex): " . $e->getMessage();
                                }
                            } else {
                                $failed_shifts++;
                                $debug[] = "Invalid shift format: '$shiftCell' (Row $rowIndex, Col $col)";
                            }
                        }
                    }
                }

                // ================================================
                // 4. Final Output
                // ================================================
                if ($uploaded_shifts > 0) {
                    $message = "Successfully uploaded $uploaded_shifts shifts.";
                    if ($failed_shifts > 0) $message .= " Failed: $failed_shifts shifts.";
                } else {
                    $error = "No shifts uploaded. Check file format or user/role matching.";
                }
            }
        } else {
            $error = "Please select a valid Excel file.";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
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
            <?php if (!empty($message)): ?>
                <div class="success-message">
                    <p><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($debug)): ?>
                <div class="debug-output">
                    <h3>Debug Details</h3>
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
                    <input type="file" name="shift_file" id="shift_file" accept=".xls,.xlsx" required>
                </p>
                <p>
                    <button type="submit">Upload Shifts</button>
                </p>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>