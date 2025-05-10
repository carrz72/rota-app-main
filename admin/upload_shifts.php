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


// ... [Keep includes and initial setup] ...

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($installation_message)) {
    try {
        if (isset($_FILES['shift_file']) && $_FILES['shift_file']['error'] == 0) {
            // ... [File validation remains the same] ...

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
            $currentRole = null;

            foreach ($worksheet->getRowIterator(3) as $row) { // Start at row 3
                $cellA = $worksheet->getCell('A' . $row->getRowIndex())->getValue();
                
                // Detect employee rows (e.g., "B, Christine - Assistant Man…")
                if (preg_match('/^([A-Z]),\s+([A-Za-z]+)\s+-/', $cellA, $nameMatches)) {
                    $lastInitial = $nameMatches[1];
                    $firstName = $nameMatches[2];
                    
                    // Reconstruct username (e.g., "Christine B")
                    $usernameToFind = "$firstName $lastInitial";
                    
                    // Find user in database using username
                    $stmt = $conn->prepare("SELECT id FROM users 
                        WHERE username LIKE ?");
                    $stmt->execute(["%$usernameToFind%"]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $currentUserId = $user['id'] ?? null;
                    
                    if (!$currentUserId) {
                        $debug[] = "User not found: $usernameToFind (Row {$row->getRowIndex()})";
                        continue;
                    }
                    
                    // Get role from column B
                    $currentRole = $worksheet->getCell('B' . $row->getRowIndex())->getValue();
                    continue;
                }

                // Process shifts for the current user
                if ($currentUserId) {
                    foreach (range('B', 'H') as $col) {
                        $shiftCell = $worksheet->getCell($col . $row->getRowIndex())->getValue();
                        $shiftCell = trim($shiftCell);

                        // Skip empty or non-shift cells
                        if (empty($shiftCell) || 
                            in_array(strtolower($shiftCell), ['day off', 'holiday', 'sick', 'available'])) {
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
                                    $currentRole // Ensure role_id exists in roles table
                                ]);
                                $uploaded_shifts++;
                                
                                // Add user notification
                                $formattedDate = date("M j, Y", strtotime($dateColumns[$col]));
                                $notifMessage = "New shift: {$matches[1]} - {$matches[2]} on $formattedDate";
                                addNotification($conn, $currentUserId, $notifMessage, "schedule");
                                
                                $conn->commit();
                            } catch (PDOException $e) {
                                $conn->rollBack();
                                $failed_shifts++;
                                $debug[] = "DB Error: " . $e->getMessage();
                            }
                        } else {
                            $failed_shifts++;
                            $debug[] = "Invalid shift format: '$shiftCell' (Row {$row->getRowIndex()}, Col $col)";
                        }
                    }
                }
            }

            // ... [Rest of success/error handling] ...
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!-- Keep HTML form unchanged -->

<!-- Keep the HTML form unchanged -->

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
