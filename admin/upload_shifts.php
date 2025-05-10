<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin();

// Check for PhpSpreadsheet installation
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
        // File upload validation
        if (!isset($_FILES['shift_file']) || $_FILES['shift_file']['error'] !== 0) {
            $error = "File upload error: " . ($_FILES['shift_file']['error'] ?? "No file selected");
        } else {
            $allowed = ['xls', 'xlsx'];
            $filename = $_FILES['shift_file']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            if (!in_array(strtolower($ext), $allowed)) {
                $error = "Please upload a valid Excel file (xls, xlsx).";
            } else {
                // Check file size
                if ($_FILES['shift_file']['size'] > 10000000) { // ~10MB limit
                    $error = "File is too large. Maximum size is 10MB.";
                } else {
                    require '../vendor/autoload.php';

                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['shift_file']['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();

                    // ================================================
                    // 1. Extract Week Start Date from Cell A1
                    // ================================================
                    $headerText = $worksheet->getCell('A1')->getValue() ?? '';
                    $debug[] = "Header text: " . $headerText;
                    
                    // More flexible date extraction - tries multiple formats
                    if (strpos($headerText, 'W/C') !== false) {
                        // Try to extract just the date part using regex
                        if (preg_match('/W\/C\s+(\d{1,2}\/\d{1,2}\/\d{4})/', $headerText, $matches)) {
                            $weekStartStr = trim($matches[1]);
                            $debug[] = "Extracted date string: " . $weekStartStr;
                        } else {
                            // Fallback to the original method but clean up the string
                            $weekStartStr = trim(explode('W/C', $headerText)[1]);
                            // Remove any text after the date format
                            if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $weekStartStr, $matches)) {
                                $weekStartStr = trim($matches[1]);
                                $debug[] = "Extracted date string (cleanup): " . $weekStartStr;
                            }
                        }
                        
                        // Try different date formats (d/m/Y, m/d/Y, Y-m-d)
                        $dateFormats = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y'];
                        $startDate = null;
                        
                        foreach ($dateFormats as $format) {
                            $date = DateTime::createFromFormat($format, $weekStartStr);
                            if ($date !== false) {
                                $startDate = $date;
                                $debug[] = "Date parsed with format: " . $format;
                                break;
                            }
                        }
                        
                        if ($startDate === null) {
                            throw new Exception("Could not parse date: $weekStartStr");
                        }
                    } else {
                        throw new Exception("Could not find 'W/C' in header text: $headerText");
                    }

                    // ================================================
                    // 2. Map Columns B-H to Actual Dates
                    // ================================================
                    $dateColumns = [];
                    foreach (range('B', 'H') as $col) {
                        $dayCell = $worksheet->getCell($col . '2')->getValue() ?? '';
                        $debug[] = "Column $col day: " . $dayCell;
                        
                        // More flexible day number extraction
                        if (preg_match('/(\d+)[a-z]*/', $dayCell, $dayMatch)) {
                            $dayNumber = (int)$dayMatch[1];
                            $date = (clone $startDate)->modify("+" . ($dayNumber - $startDate->format('d')) . " days");
                            $dateColumns[$col] = $date->format('Y-m-d');
                            $debug[] = "Column $col mapped to: " . $dateColumns[$col];
                        } else {
                            $debug[] = "Could not extract day number from: $dayCell";
                        }
                    }

                    if (empty($dateColumns)) {
                        throw new Exception("Could not extract any dates from row 2");
                    }

                    // ================================================
                    // 3. Process Employee Rows and Shifts
                    // ================================================
                    $currentUserId = null;
                    $currentRoleId = null;

                    foreach ($worksheet->getRowIterator(3) as $row) { // Start at row 3
                        $rowIndex = $row->getRowIndex();
                        $cellA = $worksheet->getCell('A' . $rowIndex)->getValue() ?? '';

                        // More flexible employee row detection
                        // Now handles formats like "B, Christine - Assistant" or "Christine B - Assistant" 
                        if (!empty($cellA) && (
                            preg_match('/^([A-Z]),\s+([A-Za-z]+)\s+-/', $cellA, $nameMatches) || 
                            preg_match('/^([A-Za-z]+)\s+([A-Z])\s+-/', $cellA, $reversedMatches)
                        )) {
                            // Set name parts based on the pattern that matched
                            if (isset($nameMatches[1])) {
                                $lastInitial = $nameMatches[1];
                                $firstName = $nameMatches[2];
                                $debug[] = "Matched pattern 1: $firstName $lastInitial";
                            } else {
                                $firstName = $reversedMatches[1];
                                $lastInitial = $reversedMatches[2];
                                $debug[] = "Matched pattern 2: $firstName $lastInitial";
                            }
                            
                            // Try both name formats in database lookup
                            $stmt = $conn->prepare("SELECT id FROM users WHERE username LIKE ? OR username LIKE ?");
                            $pattern1 = "$firstName $lastInitial%";
                            $pattern2 = "$lastInitial, $firstName%";
                            $stmt->execute([$pattern1, $pattern2]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);
                            $currentUserId = $user['id'] ?? null;
                            
                            if ($currentUserId) {
                                $debug[] = "Found user ID: $currentUserId for $firstName $lastInitial";
                            } else {
                                $debug[] = "User not found: Tried patterns '$pattern1' and '$pattern2'";
                            }

                            // Get role from column B with more flexible matching
                            $roleName = trim($worksheet->getCell('B' . $rowIndex)->getValue() ?? '');
                            
                            // We'll check for roles when processing individual cells instead
                            // Just track if we found a user
                            if (!$currentUserId) {
                                $debug[] = "Row $rowIndex skipped:";
                                $debug[] = "- User not found: $firstName $lastInitial";
                            }
                            continue;
                        }

                        // Process shifts for the current user
                        if ($currentUserId) {
                            foreach (range('B', 'H') as $col) {
                                if (!isset($dateColumns[$col])) continue;
                                
                                $shiftCell = trim($worksheet->getCell($col . $rowIndex)->getValue() ?? '');
                                $debug[] = "Row $rowIndex, Col $col: '$shiftCell'";

                                // Skip empty cells
                                if (empty($shiftCell)) {
                                    continue;
                                }
                                
                                // Skip known non-shift entries
                                $nonShiftEntries = ['day off', 'holiday', 'sick', 'available'];
                                if (in_array(strtolower($shiftCell), $nonShiftEntries)) {
                                    continue;
                                }

                                // Check if this cell contains a role name or try to get it from row header
                                $roleName = null;
                                $cellRole = null;
                                $currentRoleId = null;
                                
                                // Extract role from cell - handle multi-line cells with role on top line
                                if (strpos($shiftCell, "\n") !== false) {
                                    $cellLines = explode("\n", $shiftCell);
                                    // First line is likely the role name if it doesn't contain time format
                                    if (!preg_match('/\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/', $cellLines[0])) {
                                        $cellRole = trim($cellLines[0]);
                                        $debug[] = "Found role in cell first line: $cellRole";
                                        // Keep the remaining part for time processing
                                        $shiftCell = implode("\n", array_slice($cellLines, 1));
                                    }
                                }
                                
                                // Special handling for truncated role names and common abbreviations
                                $roleMapping = [
                                    'Relief Supe' => 'Relief Supervisor',
                                    'Assistant M' => 'Assistant Manager',
                                    'Venue Man' => 'Venue Manager',
                                    'Kwik Tan S' => 'Kwik Tan Supervisor',
                                    'CSA' => 'Customer Service Associate',
                                    'Relief Super' => 'Relief Supervisor'
                                ];
                                
                                // If we found a role in the cell
                                if ($cellRole) {
                                    // Apply role mapping for truncated names
                                    foreach ($roleMapping as $partial => $full) {
                                        if (strpos($cellRole, $partial) === 0 || $cellRole === $partial) {
                                            $debug[] = "Mapped role '$cellRole' to '$full'";
                                            $cellRole = $full;
                                            break;
                                        }
                                    }
                                    
                                    // Look up the role ID
                                    $stmt = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                                    $stmt->execute(["%$cellRole%"]);
                                    $role = $stmt->fetch(PDO::FETCH_ASSOC);
                                    $currentRoleId = $role['id'] ?? null;
                                    
                                    if ($currentRoleId) {
                                        $debug[] = "Found role ID: $currentRoleId for role: $cellRole";
                                        $roleName = $cellRole;
                                    } else {
                                        $debug[] = "Role not found in database: $cellRole - trying direct insert";
                                        
                                        // Try to insert this role if it doesn't exist
                                        try {
                                            $conn->beginTransaction();
                                            $stmt = $conn->prepare("INSERT INTO roles (name) VALUES (?)");
                                            $stmt->execute([$cellRole]);
                                            $currentRoleId = $conn->lastInsertId();
                                            $conn->commit();
                                            $debug[] = "Created new role ID: $currentRoleId for: $cellRole";
                                            $roleName = $cellRole;
                                        } catch (PDOException $e) {
                                            $conn->rollBack();
                                            $debug[] = "Failed to create role: " . $e->getMessage();
                                        }
                                    }
                                }
                                
                                // If we still don't have a role, try from the user's info in column A
                                $userCellA = $worksheet->getCell('A' . $rowIndex)->getValue() ?? '';
                                if (preg_match('/-\s*(.+)$/', $userCellA, $roleMatches)) {
                                    $extractedRole = trim($roleMatches[1]);
                                    
                                    // Apply role mapping
                                    foreach ($roleMapping as $partial => $full) {
                                        if (strpos($extractedRole, $partial) === 0) {
                                            $extractedRole = $full;
                                            break;
                                        }
                                    }
                                    
                                    $stmt = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                                    $stmt->execute(["%$extractedRole%"]);
                                    $role = $stmt->fetch(PDO::FETCH_ASSOC);
                                    $currentRoleId = $role['id'] ?? null;
                                    
                                    if ($currentRoleId) {
                                        $roleName = $extractedRole;
                                        $debug[] = "Found role ID: $currentRoleId from user info: $extractedRole";
                                    }
                                }
                                
                                // Try to find a default role for the user if no role was identified
                                if (!$currentRoleId) {
                                    // Try to get role_id directly from users table instead of user_roles
                                    $stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ? AND role_id IS NOT NULL LIMIT 1");
                                    $stmt->execute([$currentUserId]);
                                    $userRole = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($userRole && isset($userRole['role_id'])) {
                                        $currentRoleId = $userRole['role_id'];
                                        $debug[] = "Using default role ID: $currentRoleId from users table";
                                    } else {
                                        // Try to get any role as fallback
                                        $stmt = $conn->prepare("SELECT id FROM roles ORDER BY id LIMIT 1");
                                        $stmt->execute();
                                        $defaultRole = $stmt->fetch(PDO::FETCH_ASSOC);
                                        if ($defaultRole) {
                                            $currentRoleId = $defaultRole['id'];
                                            $debug[] = "Using fallback role ID: $currentRoleId as last resort";
                                        }
                                    }
                                }
                                
                                // If we still don't have a role, skip this shift
                                if (!$currentRoleId) {
                                    $debug[] = "No valid role found for shift in Row $rowIndex, Col $col - skipping";
                                    continue;
                                }

                                // More flexible time format parsing - handles multiple formats including multi-line entries
                                if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(?:\s*\((.*)\))?/', $shiftCell, $matches) ||
                                    preg_match('/(\d{1,2})(?::|\.)\d{2}\s*(?:am|pm)?\s*-\s*(\d{1,2})(?::|\.)\d{2}\s*(?:am|pm)?(?:\s*\((.*)\))?/i', $shiftCell, $matches) ||
                                    // New pattern for multi-line entries with role on first line, time on second line
                                    preg_match('/(?:.*\n)?(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(?:\s*\n\s*\((.*)\))?/m', $shiftCell, $matches)) {
                                    
                                    try {
                                        // Format times consistently to ensure 24-hour format (HH:MM)
                                        $startTime = $matches[1];
                                        $endTime = $matches[2];
                                        $location = isset($matches[3]) ? trim($matches[3]) : 'Default Location';
                                        
                                        // Check for location on its own line
                                        if (empty($location) && preg_match('/\((.*?)\)/m', $shiftCell, $locMatches)) {
                                            $location = trim($locMatches[1]);
                                        }
                                        
                                        $debug[] = "Parsed shift: $startTime - $endTime ($location)";
                                        
                                        $conn->beginTransaction();
                                        $stmt = $conn->prepare("INSERT INTO shifts 
                                            (user_id, shift_date, start_time, end_time, location, role_id) 
                                            VALUES (?, ?, ?, ?, ?, ?)");
                                        $stmt->execute([
                                            $currentUserId,
                                            $dateColumns[$col],
                                            $startTime,
                                            $endTime,
                                            $location,
                                            $currentRoleId
                                        ]);
                                        $uploaded_shifts++;

                                        // Add notification
                                        $formattedDate = date("M j, Y", strtotime($dateColumns[$col]));
                                        $notifMessage = "New shift: $startTime - $endTime on $formattedDate";
                                        addNotification($conn, $currentUserId, $notifMessage, "schedule");

                                        $conn->commit();
                                        $debug[] = "Successfully added shift to database";
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
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        $debug[] = "Exception: " . $e->getMessage();
        $debug[] = "Stack trace: " . $e->getTraceAsString();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            
            <div class="template-info">
                <h3>File Format Requirements</h3>
                <ul>
                    <li>Excel file (.xls or .xlsx) with the week start date in cell A1 (format: "W/C dd/mm/yyyy")</li>
                    <li>Days of the week in row 2, columns B-H</li>
                    <li>Employee names in column A in format "LastInitial, FirstName - Role" (e.g., "B, Christine - Manager")</li>
                    <li>Shifts in format "HH:MM - HH:MM (Location)" (e.g., "09:00 - 17:00 (Main Office)")</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>