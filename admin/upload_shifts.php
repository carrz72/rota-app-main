<?php
require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../functions/addNotification.php';
requireAdmin();

// Determine the branch of the admin performing the upload. We'll use this as the default
// branch_id for any shifts created/updated by this upload process.
$uploaderBranchId = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $uploaderBranchId = isset($u['branch_id']) ? $u['branch_id'] : null;
}

// Helper function to check if a string is a truncated version of another string
function isPartialMatch($partial, $full)
{
    $partial = strtolower(trim($partial));
    $full = strtolower(trim($full));
    return (strpos($full, $partial) === 0) && strlen($partial) >= 3;
}

// Role mapping for truncated role names and common abbreviations
$roleMapping = [
    'Relief Sup' => 'Relief Supervisor',
    'Relief Super' => 'Relief Supervisor',
    'Relief Supervi' => 'Relief Supervisor',
    'Assistant' => 'Assistant Manager',
    'Assistant M' => 'Assistant Manager',
    'Venue' => 'Venue Manager',
    'Venue Man' => 'Venue Manager',
    'Kwik' => 'Kwik Tan Supervisor',
    'Kwik Tan' => 'Kwik Tan Supervisor',
    'Kwik Tan S' => 'Kwik Tan Supervisor',
    'CSA' => 'Customer Service Associate',
];

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

// Load branches into a lookup map (lowercased name => id) to map locations like '(Mansfield)'
$branchMap = [];
try {
    $rows = $conn->query("SELECT id, name FROM branches")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $branchMap[strtolower(trim($r['name']))] = $r['id'];
    }
} catch (Exception $e) {
    // non-fatal — if branches table doesn't exist yet, we'll continue without mapping
}

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
                            $dayNumber = (int) $dayMatch[1];
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
                        if (
                            !empty($cellA) && (
                                preg_match('/^([A-Z]),\s+([A-Za-z]+)\s+-\s*(.*)/', $cellA, $nameMatches) ||
                                preg_match('/^([A-Za-z]+)\s+([A-Z])\s+-\s*(.*)/', $cellA, $reversedMatches)
                            )
                        ) {
                            // Set name parts based on the pattern that matched
                            if (isset($nameMatches[1])) {
                                $lastInitial = $nameMatches[1];
                                $firstName = $nameMatches[2];
                                $rowRoleName = !empty($nameMatches[3]) ? trim($nameMatches[3]) : '';
                                $debug[] = "Matched pattern 1: $firstName $lastInitial" . ($rowRoleName ? " - $rowRoleName" : "");
                            } else {
                                $firstName = $reversedMatches[1];
                                $lastInitial = $reversedMatches[2];
                                $rowRoleName = !empty($reversedMatches[3]) ? trim($reversedMatches[3]) : '';
                                $debug[] = "Matched pattern 2: $firstName $lastInitial" . ($rowRoleName ? " - $rowRoleName" : "");
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

                            // Just track if we found a user
                            if (!$currentUserId) {
                                $debug[] = "Row $rowIndex skipped:";
                                $debug[] = "- User not found: $firstName $lastInitial";
                            }
                            continue;
                        }

                        // Process shifts for the current user
                        if ($currentUserId) {
                            // If we have a role name from the employee row, try to find its ID
                            $currentRowRoleId = null;

                            if (!empty($rowRoleName)) {
                                // Check for CSA specifically first
                                if (strcasecmp(trim($rowRoleName), "CSA") == 0) {
                                    $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name = ? OR name LIKE ?");
                                    $stmtRole->execute(['CSA', '%Customer Service%']);
                                    $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
                                    if ($role) {
                                        $currentRowRoleId = $role['id'];
                                        $debug[] = "Found role ID: $currentRowRoleId for employee row role: $rowRoleName";
                                    }
                                } else {
                                    $mappedRoleName = $rowRoleName;
                                    // Apply standard role mapping
                                    foreach ($roleMapping as $partial => $full) {
                                        if (strpos($rowRoleName, $partial) === 0 || $rowRoleName === $partial) {
                                            $mappedRoleName = $full;
                                            break;
                                        }
                                    }
                                    $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                                    $stmtRole->execute(["%$mappedRoleName%"]);
                                    $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
                                    if ($role) {
                                        $currentRowRoleId = $role['id'];
                                        $debug[] = "Found role ID: $currentRowRoleId for employee row role: $rowRoleName";
                                    }
                                }
                            }

                            foreach (range('B', 'H') as $col) {
                                if (!isset($dateColumns[$col]))
                                    continue;
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

                                // First, check if there's a role in a cell above this one
                                $roleName = null;
                                $cellRole = null;

                                // Look for role in cells above (check up to 3 cells up)
                                for ($i = 1; $i <= 3; $i++) {
                                    $checkRow = $rowIndex - $i;
                                    if ($checkRow < 3)
                                        break; // Don't go above row 3
                                    $roleCell = trim($worksheet->getCell($col . $checkRow)->getValue() ?? '');
                                    if (!empty($roleCell) && !preg_match('/\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}/', $roleCell)) {
                                        // This cell might contain a role name
                                        $debug[] = "Found potential role cell above: '$roleCell' at row $checkRow, col $col";

                                        // Special handling for CSA
                                        if ($roleCell == "CSA" || strcasecmp(trim($roleCell), "CSA") == 0) {
                                            // Try to find the CSA role in the database
                                            $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name = ? OR name LIKE ?");
                                            $stmtRole->execute(['CSA', '%CSA%']);
                                            $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
                                            if ($role) {
                                                $currentRoleId = $role['id'];
                                                $roleName = 'CSA';
                                                $debug[] = "Found CSA role ID: $currentRoleId from cell above";
                                                break; // Role found, stop looking
                                            }
                                        } else {
                                            // Apply role mapping for truncated names
                                            foreach ($roleMapping as $partial => $full) {
                                                if (strpos($roleCell, $partial) === 0 || $roleCell === $partial) {
                                                    $debug[] = "Mapped role '$roleCell' to '$full'";
                                                    $roleCell = $full;
                                                    break;
                                                }
                                            }
                                            // Look up the role ID
                                            $stmtRole = $conn->prepare("SELECT id, name FROM roles WHERE name LIKE ?");
                                            $stmtRole->execute(["%$roleCell%"]);
                                            $role = $stmtRole->fetch(PDO::FETCH_ASSOC);
                                            if ($role) {
                                                $currentRoleId = $role['id'];
                                                $roleName = $role['name'];
                                                $debug[] = "Found role ID: $currentRoleId ($roleName) from cell above";
                                                break; // Role found, stop looking
                                            }
                                        }

                                        // If we found text but couldn't match to a role, save it for future checks
                                        $cellRole = $roleCell;
                                        break;
                                    }
                                }

                                // Continue with existing role detection if we didn't find a role above
                                // Check if this cell contains a role name or try to get it from row header
                                $roleName = null;
                                $cellRole = null;

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

                                // If we found a role in the cell
                                if ($cellRole) {
                                    // First, check direct mapping
                                    $mapped = false;
                                    foreach ($roleMapping as $partial => $full) {
                                        if (
                                            strcasecmp(trim($cellRole), $partial) === 0 ||
                                            strpos(strtolower(trim($cellRole)), strtolower($partial)) === 0
                                        ) {
                                            $debug[] = "Mapped role '$cellRole' to '$full'";
                                            $cellRole = $full;
                                            $mapped = true;
                                            break;
                                        }
                                    }

                                    // If no direct mapping found, try to get all roles and check for partial matches
                                    if (!$mapped) {
                                        $allRoles = $conn->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($allRoles as $role) {
                                            if (isPartialMatch($cellRole, $role['name'])) {
                                                $debug[] = "Partial match: '$cellRole' to '{$role['name']}'";
                                                $currentRoleId = $role['id'];
                                                $roleName = $role['name'];
                                                $mapped = true;
                                                break;
                                            }
                                        }
                                    }

                                    // Now lookup the role in the database if we haven't already found an ID
                                    if (!$mapped || !$currentRoleId) {
                                        // Look up the role ID
                                        $stmt = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                                        $stmt->execute(["%$cellRole%"]);
                                        $role = $stmt->fetch(PDO::FETCH_ASSOC);
                                        $currentRoleId = $role['id'] ?? null;
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

                                // Check if the cell value itself contains role information
                                if (!$currentRoleId && !empty($shiftCell)) {
                                    // Add debug to see exactly what we're dealing with
                                    $cellCharCodes = '';
                                    for ($i = 0; $i < strlen($shiftCell); $i++) {
                                        $cellCharCodes .= ord($shiftCell[$i]) . ' ';
                                    }
                                    $debug[] = "Cell content character codes: $cellCharCodes";

                                    // First, scan the entire row to find role information
                                    $rowCells = [];
                                    foreach (range('A', 'H') as $scanCol) {
                                        $rowCells[$scanCol] = trim($worksheet->getCell($scanCol . $rowIndex)->getValue() ?? '');
                                    }
                                    $debug[] = "Scanning row for roles: " . implode(" | ", $rowCells);

                                    // Check if CSA appears anywhere in the row
                                    $foundCSA = false;
                                    foreach ($rowCells as $colLetter => $cellContent) {
                                        if (stripos($cellContent, 'CSA') !== false) {
                                            $foundCSA = true;
                                            $debug[] = "Found CSA in row cell $colLetter: '$cellContent'";
                                            break;
                                        }
                                    }
                                    // Pre-load all roles from database for direct comparison
                                    $stmtRoles = $conn->query("SELECT id, name FROM roles");
                                    $allDbRoles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
                                    $debug[] = "Loaded " . count($allDbRoles) . " roles from database";

                                    // Special handling for CSA - prioritize this check first
                                    if (
                                        $foundCSA || $shiftCell == "CSA" || strcasecmp(trim($shiftCell), "CSA") == 0 ||
                                        preg_match('/^CSA$/i', trim($shiftCell))
                                    ) {
                                        $debug[] = $foundCSA ?
                                            "MATCH: Found CSA in row for cell: '$shiftCell'" :
                                            "EXACT MATCH: Found CSA text in cell: '$shiftCell'";
                                        // Find CSA role in our pre-loaded roles - try exact CSA first
                                        foreach ($allDbRoles as $dbRole) {
                                            if ($dbRole['name'] === 'CSA' || strcasecmp($dbRole['name'], 'CSA') === 0) {
                                                $currentRoleId = $dbRole['id'];
                                                $roleName = $dbRole['name'];
                                                $debug[] = "Direct CSA database match: $currentRoleId ($roleName)";
                                                break;
                                            }
                                        }

                                        // If didn't find exact CSA, try Customer Service Associate
                                        if (!$currentRoleId) {
                                            foreach ($allDbRoles as $dbRole) {
                                                if (
                                                    stripos($dbRole['name'], 'customer service') !== false ||
                                                    stripos($dbRole['name'], 'csa') !== false
                                                ) {
                                                    $currentRoleId = $dbRole['id'];
                                                    $roleName = $dbRole['name'];
                                                    $debug[] = "Expanded CSA match: $currentRoleId ($roleName)";
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    // If we still don't have a role match, try exact cell value against all roles
                                    if (!$currentRoleId) {
                                        $normalizedCell = strtolower(trim($shiftCell));
                                        $debug[] = "Looking for exact role match for: '$normalizedCell'";
                                        foreach ($allDbRoles as $dbRole) {
                                            $roleShortName = strtolower(trim(substr($dbRole['name'], 0, 10)));
                                            $roleInitials = '';
                                            // Create abbreviation from first letter of each word
                                            $words = explode(' ', $dbRole['name']);
                                            foreach ($words as $word) {
                                                $roleInitials .= strtolower(substr($word, 0, 1));
                                            }
                                            // Check for matches against full role name, short name, or abbreviation
                                            if (
                                                $normalizedCell == strtolower($dbRole['name']) ||
                                                ($normalizedCell == $roleShortName && strlen($normalizedCell) >= 3) ||
                                                ($normalizedCell == $roleInitials && strlen($roleInitials) > 1)
                                            ) {
                                                $currentRoleId = $dbRole['id'];
                                                $roleName = $dbRole['name'];
                                                $debug[] = "Name-based match: $currentRoleId ($roleName)";
                                                break;
                                            }
                                        }
                                    }
                                    // If still no match, continue with expanded mapping approach
                                    if (!$currentRoleId) {
                                        // Extended role mapping with more variants
                                        $expandedMapping = $roleMapping;
                                        // Add more mappings as needed
                                        $expandedMapping['csa'] = 'Customer Service Associate';  // Lowercase variant
                                        $expandedMapping['C.S.A'] = 'Customer Service Associate'; // Variant with periods
                                        $expandedMapping['C.S.A.'] = 'Customer Service Associate'; // Another variant

                                        foreach ($expandedMapping as $partial => $full) {
                                            if (strpos(strtolower($shiftCell), strtolower($partial)) !== false) {
                                                $debug[] = "Mapped role '$shiftCell' to '$full'";
                                                // Look up the role ID
                                                $stmt = $conn->prepare("SELECT id FROM roles WHERE name LIKE ?");
                                                $stmt->execute(["%$full%"]);
                                                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                                                $currentRoleId = $role['id'] ?? null;
                                                if ($currentRoleId) {
                                                    $roleName = $full;
                                                    $debug[] = "Found role ID: $currentRoleId for role: $full";
                                                    break;
                                                }
                                            }
                                        }
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
                                if (
                                    preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(?:\s*\((.*)\))?/', $shiftCell, $matches) ||
                                    preg_match('/(\d{1,2})(?::|\.)\d{2}\s*(?:am|pm)?\s*-\s*(\d{1,2})(?::|\.)\d{2}\s*(?:am|pm)?(?:\s*\((.*)\))?/i', $shiftCell, $matches) ||
                                    // New pattern for multi-line entries with role on first line, time on second line
                                    preg_match('/(?:.*\n)?(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})(?:\s*\n\s*\((.*)\))?/m', $shiftCell, $matches)
                                ) {
                                    try {
                                        // Format times consistently to ensure 24-hour format (HH:MM)
                                        $startTime = $matches[1];
                                        $endTime = $matches[2];
                                        $location = isset($matches[3]) ? trim($matches[3]) : 'The Lion'; // Changed default location
                                        // Check for location on its own line
                                        if (empty($location) && preg_match('/\((.*?)\)/m', $shiftCell, $locMatches)) {
                                            $location = trim($locMatches[1]);
                                        }
                                        $debug[] = "Parsed shift: $startTime - $endTime ($location)";

                                        // Check if a shift already exists for this user on this date
                                        $stmt = $conn->prepare("SELECT id, start_time, end_time, location, role_id, branch_id 
                                                               FROM shifts 
                                                               WHERE user_id = ? AND shift_date = ?");
                                        $stmt->execute([$currentUserId, $dateColumns[$col]]);
                                        $existingShift = $stmt->fetch(PDO::FETCH_ASSOC);

                                        $conn->beginTransaction();
                                        if ($existingShift) {
                                            // Check if there are any changes to the shift
                                            // Determine branch: default to uploader's branch, override if parsed location maps to a different branch
                                            $calculatedBranchId = $uploaderBranchId;
                                            $locKey = strtolower(trim($location));
                                            if (isset($branchMap[$locKey])) {
                                                $calculatedBranchId = $branchMap[$locKey];
                                            }
                                            $debug[] = "Calculated branch_id for shift: " . var_export($calculatedBranchId, true);

                                            if (
                                                $existingShift['start_time'] != $startTime ||
                                                $existingShift['end_time'] != $endTime ||
                                                $existingShift['location'] != $location ||
                                                $existingShift['role_id'] != $currentRoleId ||
                                                (($existingShift['branch_id'] ?? null) != $calculatedBranchId)
                                            ) {
                                                // Update the existing shift
                                                $stmt = $conn->prepare("UPDATE shifts 
                                                                      SET start_time = ?, end_time = ?, location = ?, role_id = ?, branch_id = ?
                                                                      WHERE id = ?");
                                                $stmt->execute([
                                                    $startTime,
                                                    $endTime,
                                                    $location,
                                                    $currentRoleId,
                                                    $calculatedBranchId,
                                                    $existingShift['id']
                                                ]);
                                                $uploaded_shifts++;
                                                $debug[] = "Updated existing shift ID: " . $existingShift['id'];
                                                // Add notification about the update
                                                $formattedDate = date("M j, Y", strtotime($dateColumns[$col]));
                                                $notifMessage = "Shift updated: $startTime - $endTime on $formattedDate";
                                                addNotification($conn, $currentUserId, $notifMessage, "schedule");
                                            } else {
                                                $debug[] = "Skipped unchanged shift for date: " . $dateColumns[$col];
                                            }
                                        } else {
                                            // Insert new shift, include calculated branch id
                                            $calculatedBranchId = $uploaderBranchId;
                                            $locKey = strtolower(trim($location));
                                            if (isset($branchMap[$locKey])) {
                                                $calculatedBranchId = $branchMap[$locKey];
                                            }

                                            $stmt = $conn->prepare("INSERT INTO shifts 
                                                (user_id, shift_date, start_time, end_time, location, role_id, branch_id) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?)");
                                            $stmt->execute([
                                                $currentUserId,
                                                $dateColumns[$col],
                                                $startTime,
                                                $endTime,
                                                $location,
                                                $currentRoleId,
                                                $calculatedBranchId
                                            ]);
                                            $uploaded_shifts++;
                                            $debug[] = "Added new shift to database";

                                            // Add notification
                                            $formattedDate = date("M j, Y", strtotime($dateColumns[$col]));
                                            $notifMessage = "New shift: $startTime - $endTime on $formattedDate";
                                            addNotification($conn, $currentUserId, $notifMessage, "schedule");
                                        }
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
                        if ($failed_shifts > 0)
                            $message .= " Failed: $failed_shifts shifts.";
                        // Audit: upload summary
                        try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'upload_shifts', ['uploaded' => $uploaded_shifts, 'failed' => $failed_shifts], null, 'shift_import', session_id()); } catch (Exception $e) {}
                    } else {
                        $error = "No shifts uploaded. Check file format or user/role matching.";
                        try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'upload_shifts_empty', ['failed' => $failed_shifts], null, 'shift_import', session_id()); } catch (Exception $e) {}
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        $debug[] = "Exception: " . $e->getMessage();
        $debug[] = "Stack trace: " . $e->getTraceAsString();
    // Audit: exception during upload
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'upload_shifts_error', ['error' => $e->getMessage()], null, 'shift_import', session_id()); } catch (Exception $ex) {}
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
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <title>Upload Shifts - Admin</title>
    <style>
        @font-face {
            font-family: 'newFont';
            src: url('../fonts/CooperHewitt-Book.otf');
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: 'newFont', Arial, sans-serif;
            background: url('../images/backg3.jpg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #f4f4f4;
            padding-bottom: 15px;
        }

        h1 {
            color: #fd2b2b;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #555;
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s, transform 0.2s;
        }

        .action-button:hover {
            background-color: #444;
            transform: translateY(-2px);
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        form {
            max-width: 600px;
            margin: 30px auto;
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #444;
        }

        input[type="file"] {
            width: 100%;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }

        button {
            background-color: #fd2b2b;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s, transform 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        button:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .template-info {
            margin-top: 40px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #17a2b8;
        }

        .template-info h3 {
            color: #17a2b8;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .template-info ul {
            margin: 15px 0 0 0;
            padding-left: 20px;
        }

        .template-info li {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .debug-output {
            margin-top: 30px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #ccc;
            max-height: 300px;
            overflow-y: auto;
        }

        .debug-output h3 {
            color: #6c757d;
            margin-top: 0;
            font-size: 1rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .debug-output ul {
            margin: 0;
            padding-left: 15px;
            font-family: monospace;
            font-size: 0.85rem;
        }

        .debug-output li {
            margin-bottom: 3px;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            form {
                padding: 15px;
                margin: 20px auto;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-upload"></i> Upload Shifts</h1>
            <a href="admin_dashboard.php" class="action-button">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if (isset($installation_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $installation_message; ?>
            </div>
        <?php else: ?>
            <?php if (!empty($message)): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <p><?php echo htmlspecialchars($message); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="shift_file"><i class="fas fa-file-excel"></i> Select Excel File:</label>
                    <input type="file" name="shift_file" id="shift_file" accept=".xls,.xlsx" required>
                </div>
                <button type="submit">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Shifts
                </button>
            </form>

            <div class="template-info">
                <h3><i class="fas fa-info-circle"></i> File Format Requirements</h3>
                <ul>
                    <li><strong>File Type:</strong> Excel file (.xls or .xlsx)</li>
                    <li><strong>Header Format:</strong> Cell A1 must contain the week start date in format "W/C dd/mm/yyyy"
                    </li>
                    <li><strong>Days:</strong> Row 2, columns B-H should contain the days of the week</li>
                    <li><strong>Employee Format:</strong> Column A should list employees as "LastInitial, FirstName - Role"
                        (e.g., "B, Christine - Manager")</li>
                    <li><strong>Shift Format:</strong> Shifts should be listed as "HH:MM - HH:MM (Location)" (e.g., "09:00 -
                        17:00 (Main Office)")</li>
                </ul>
            </div>

            <?php if (!empty($debug)): ?>
                <div class="debug-output">
                    <h3><i class="fas fa-bug"></i> Debug Details</h3>
                    <ul>
                        <?php foreach ($debug as $line): ?>
                            <li><?php echo htmlspecialchars($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>