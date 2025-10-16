<!DOCTYPE html>
<html>

<head>
    <title>Shifts Button Debug</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }

        .test-section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .shift-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-notes,
        .editBtn,
        .deleteBtn,
        .swapBtn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-notes {
            background-color: #ff9800;
            color: white;
        }

        .btn-notes:hover {
            background-color: #f57c00;
            transform: translateY(-1px);
        }

        .editBtn {
            background-color: #007bff;
            color: white;
        }

        .deleteBtn {
            background-color: #dc3545;
            color: white;
        }

        .swapBtn {
            background-color: #6c757d;
            color: white;
        }

        .status {
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .warning {
            background: #fff3cd;
            color: #856404;
        }

        .info {
            background: #d1ecf1;
            color: #0c5460;
        }
    </style>
</head>

<body>
    <h1>🔍 Shifts Page Button Debug</h1>

    <div class="test-section">
        <h2>Test 1: Button Rendering (Standalone)</h2>
        <p>This is how the buttons SHOULD look:</p>
        <div class="shift-actions">
            <a href="#" class="btn-notes" onclick="alert('Notes button clicked!'); return false;">
                <i class="fa fa-sticky-note"></i> Notes
            </a>
            <button class="editBtn">
                <i class="fa fa-pencil"></i> Edit
            </button>
            <button class="deleteBtn">
                <i class="fa fa-trash"></i> Delete
            </button>
            <button class="swapBtn">
                <i class="fa fa-exchange"></i> Swap
            </button>
        </div>
    </div>

    <div class="test-section">
        <h2>Test 2: Button Inside Table</h2>
        <p>This is how it appears in the actual shifts table:</p>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Oct 16, 2025</td>
                    <td>9:00 AM - 5:00 PM</td>
                    <td>Manager</td>
                    <td>
                        <div class="shift-actions">
                            <a href="shift_notes.php?shift_id=1" class="btn-notes" title="View shift notes">
                                <i class="fa fa-sticky-note"></i> Notes
                            </a>
                            <button class="editBtn" data-id="1">
                                <i class="fa fa-pencil"></i> Edit
                            </button>
                            <button class="deleteBtn" data-id="1">
                                <i class="fa fa-trash"></i> Delete
                            </button>
                            <button class="swapBtn" data-id="1">
                                <i class="fa fa-exchange"></i> Swap
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="test-section">
        <h2>Test 3: Check Actual Shifts Page</h2>
        <?php
        session_start();
        require_once '../includes/db.php';

        if (!isset($_SESSION['user_id'])) {
            echo "<div class='status warning'>⚠️ You need to be logged in. <a href='../functions/login.php'>Login here</a></div>";
        } else {
            $user_id = $_SESSION['user_id'];

            // Get user's shifts
            $stmt = $conn->prepare("
                SELECT s.*, r.name as role_name 
                FROM shifts s
                JOIN roles r ON s.role_id = r.id
                WHERE s.user_id = ?
                ORDER BY s.shift_date DESC, s.start_time DESC
                LIMIT 5
            ");
            $stmt->execute([$user_id]);
            $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($shifts) > 0) {
                echo "<div class='status success'>✅ Found " . count($shifts) . " shifts</div>";
                echo "<p>Here's what the buttons should look like for your actual shifts:</p>";
                echo "<table>";
                echo "<thead><tr><th>Date</th><th>Time</th><th>Role</th><th>Actions</th></tr></thead>";
                echo "<tbody>";

                foreach ($shifts as $shift) {
                    $date = date('M j, Y', strtotime($shift['shift_date']));
                    $time = date('g:i A', strtotime($shift['start_time'])) . ' - ' . date('g:i A', strtotime($shift['end_time']));

                    echo "<tr>";
                    echo "<td>{$date}</td>";
                    echo "<td>{$time}</td>";
                    echo "<td>" . htmlspecialchars($shift['role_name']) . "</td>";
                    echo "<td>";
                    echo '<div class="shift-actions">';
                    echo '<a href="shift_notes.php?shift_id=' . $shift['id'] . '" class="btn-notes" title="View shift notes">';
                    echo '<i class="fa fa-sticky-note"></i> Notes';
                    echo '</a>';
                    echo '<button class="editBtn" data-id="' . $shift['id'] . '">';
                    echo '<i class="fa fa-pencil"></i> Edit';
                    echo '</button>';
                    echo '<button class="deleteBtn" data-id="' . $shift['id'] . '">';
                    echo '<i class="fa fa-trash"></i> Delete';
                    echo '</button>';
                    echo '<button class="swapBtn" data-id="' . $shift['id'] . '">';
                    echo '<i class="fa fa-exchange"></i> Swap';
                    echo '</button>';
                    echo '</div>';
                    echo "</td>";
                    echo "</tr>";
                }

                echo "</tbody></table>";

                echo "<div class='status info' style='margin-top: 20px;'>";
                echo "ℹ️ <strong>Can you see the orange Notes button above?</strong><br>";
                echo "If YES but it's missing on shifts.php, try:<br>";
                echo "1. Hard refresh shifts.php (Ctrl+Shift+R)<br>";
                echo "2. Clear browser cache completely<br>";
                echo "3. Check browser console (F12) for errors<br>";
                echo "4. Try a different browser";
                echo "</div>";

            } else {
                echo "<div class='status warning'>⚠️ You don't have any shifts. Add a shift first to see the buttons.</div>";
            }
        }
        ?>
    </div>

    <div class="test-section">
        <h2>Test 4: Browser Cache Check</h2>
        <div class="status info">
            <strong>Cache Busting Test:</strong><br>
            Random number: <strong><?php echo time(); ?></strong><br><br>
            If you see the same number after refreshing, your browser is caching the page.<br><br>
            <strong>Solutions:</strong>
            <ol>
                <li>Hard refresh: <strong>Ctrl + Shift + R</strong></li>
                <li>Clear all cache: Ctrl + Shift + Delete → Clear browsing data</li>
                <li>Incognito mode: Ctrl + Shift + N (Chrome/Edge)</li>
                <li>Disable cache: F12 → Network tab → Check "Disable cache"</li>
            </ol>
        </div>
    </div>

    <div class="test-section">
        <h2>🎯 Go to Actual Shifts Page</h2>
        <p>
            <a href="shifts.php"
                style="display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Open shifts.php →
            </a>
        </p>
        <p style="color: #666; margin-top: 10px;">
            After clicking, press <strong>Ctrl+Shift+R</strong> to force a fresh load
        </p>
    </div>

</body>

</html>