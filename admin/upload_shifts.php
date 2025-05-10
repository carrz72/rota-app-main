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

// ... [Keep the initial includes and auth checks] ...

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($installation_message)) {
    try {
        if (isset($_FILES['shift_file']) && $_FILES['shift_file']['error'] == 0) {
            // ... [File validation remains the same] ...

            require '../vendor/autoload.php';
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($_FILES['shift_file']['tmp_name']);
            $spreadsheet = $reader->load($_FILES['shift_file']['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // ========== KEY FIXES START HERE ========== //
            
            // 1. Extract the week start date from cell A1
            $weekStartStr = trim(explode('W/C', $worksheet->getCell('A1')->getValue())[1]);
            $startDate = DateTime::createFromFormat('d/m/Y', $weekStartStr);

            // 2. Map columns B-H to dates (Sat 3rd, Sun 4th, etc.)
            $dateColumns = [];
            foreach (range('B', 'H') as $col) {
                $cellValue = $worksheet->getCell($col . '2')->getValue(); // Row 2 has days
                preg_match('/\b(\w{3}) (\d+)\w+/', $cellValue, $matches);
                $dayOffset = $matches[2] - $startDate->format('d'); // Calculate day offset
                $date = (clone $startDate)->modify("+$dayOffset days");
                $dateColumns[$col] = $date->format('Y-m-d');
            }

            // 3. Iterate through employee rows
            $currentUser = null;
            foreach ($rows as $rowIdx => $row) {
                $cellA = trim($row[0] ?? '');
                
                // Detect employee rows (e.g., "B, Christine - Assistant Man…")
                if (preg_match('/^([A-Z]),\s([A-Za-z]+)\s+-/', $cellA, $nameMatches)) {
                    $lastInitial = $nameMatches[1];
                    $firstName = $nameMatches[2];
                    
                    // Find user ID (example logic - adjust to your DB schema)
                    $stmt = $conn->prepare("SELECT id FROM users WHERE last_name LIKE ? AND first_name LIKE ?");
                    $stmt->execute([$lastInitial . '%', $firstName . '%']);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $currentUser = $user ? $user['id'] : null;
                    $currentRole = trim($row[1] ?? ''); // Role in column B
                    continue;
                }

                // Process shift rows for the current user
                if ($currentUser && $rowIdx > 2 && !empty(trim(implode('', $row)))) {
                    foreach (range('B', 'H') as $col) {
                        $shiftInfo = trim($row[array_search($col, range('A', 'Z'))] ?? '');
                        
                        // Skip empty or special cases
                        if (empty($shiftInfo) || in_array(strtolower($shiftInfo), ['day off', 'holiday'])) continue;

                        // Extract time and location (e.g., "08:00 - 12:00 (Bulwell - MS)")
                        preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})\s*(?:\((.*)\))?/', $shiftInfo, $matches);
                        if ($matches) {
                            try {
                                $conn->beginTransaction();
                                $stmt = $conn->prepare("INSERT INTO shifts 
                                    (user_id, shift_date, start_time, end_time, location) 
                                    VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $currentUser,
                                    $dateColumns[$col],
                                    $matches[1],
                                    $matches[2],
                                    $matches[3] ?? null
                                ]);
                                $uploaded_shifts++;
                                $conn->commit();
                            } catch (PDOException $e) {
                                $conn->rollBack();
                                $failed_shifts++;
                                $debug[] = "Error at row $rowIdx: " . $e->getMessage();
                            }
                        }
                    }
                }
            }

            // ========== KEY FIXES END HERE ========== //

            if ($uploaded_shifts > 0) {
                $message = "Successfully uploaded $uploaded_shifts shifts.";
                if ($failed_shifts > 0) $message .= " $failed_shifts failed.";
            } else {
                $error = "No shifts uploaded. Check file format.";
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

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
