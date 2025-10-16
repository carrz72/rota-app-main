<!DOCTYPE html>
<html>

<head>
    <title>Notes Button Test</title>
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

        .info {
            background: #d1ecf1;
            color: #0c5460;
        }
    </style>
</head>

<body>
    <h1>🔍 Shift Notes Button Test</h1>

    <div class="test-section">
        <h2>Test 1: Button Styling</h2>
        <p>If you can see an orange "Notes" button below, the CSS is working:</p>
        <div class="shift-actions">
            <a href="#" class="btn-notes" onclick="alert('Notes button works!'); return false;">
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
        <h2>Test 2: Check Files</h2>
        <div class="status info">
            <strong>Files to check:</strong>
            <ul>
                <li>users/shifts.php - Should have the Notes button code</li>
                <li>users/shift_notes.php - The notes page</li>
                <li>functions/shift_notes_api.php - The API endpoint</li>
                <li>css/shift_notes.css - The styling</li>
            </ul>
        </div>
    </div>

    <div class="test-section">
        <h2>Test 3: File Existence Check</h2>
        <?php
        $files = [
            'shift_notes.php' => file_exists('shift_notes.php'),
            '../functions/shift_notes_api.php' => file_exists('../functions/shift_notes_api.php'),
            '../css/shift_notes.css' => file_exists('../css/shift_notes.css'),
            '../setup_shift_notes.sql' => file_exists('../setup_shift_notes.sql')
        ];

        foreach ($files as $file => $exists) {
            $status = $exists ? 'success' : 'warning';
            $icon = $exists ? '✅' : '❌';
            echo "<div class='status $status'>$icon <strong>$file</strong> - " . ($exists ? 'EXISTS' : 'NOT FOUND') . "</div>";
        }
        ?>
    </div>

    <div class="test-section">
        <h2>Test 4: Database Table Check</h2>
        <?php
        require_once '../includes/db.php';

        try {
            $stmt = $conn->query("SHOW TABLES LIKE 'shift_notes'");
            $tableExists = $stmt->rowCount() > 0;

            if ($tableExists) {
                echo "<div class='status success'>✅ <strong>shift_notes</strong> table EXISTS</div>";

                // Count notes
                $countStmt = $conn->query("SELECT COUNT(*) as count FROM shift_notes");
                $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "<div class='status info'>📊 Total notes in database: <strong>$count</strong></div>";
            } else {
                echo "<div class='status warning'>❌ <strong>shift_notes</strong> table NOT FOUND</div>";
                echo "<div class='status info'>Run this command: <code>mysql -u root -p rota_app < setup_shift_notes.sql</code></div>";
            }
        } catch (Exception $e) {
            echo "<div class='status warning'>⚠️ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
    </div>

    <div class="test-section">
        <h2>Test 5: Check Your Shifts</h2>
        <?php
        session_start();

        if (isset($_SESSION['user_id'])) {
            echo "<div class='status success'>✅ You are logged in as User ID: " . $_SESSION['user_id'] . "</div>";

            // Get user's shifts
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shifts WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $shiftCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            echo "<div class='status info'>📅 You have <strong>$shiftCount</strong> shifts</div>";

            if ($shiftCount > 0) {
                echo "<div class='status success'>✅ You should see the Notes button on shifts.php</div>";
                echo "<p><a href='shifts.php' style='display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px;'>Go to My Shifts →</a></p>";
            } else {
                echo "<div class='status warning'>⚠️ You don't have any shifts yet. Add a shift first, then the Notes button will appear.</div>";
            }
        } else {
            echo "<div class='status warning'>⚠️ You are not logged in</div>";
            echo "<p><a href='../functions/login.php'>Login here</a></p>";
        }
        ?>
    </div>

    <div class="test-section">
        <h2>🎯 Troubleshooting Steps</h2>
        <ol>
            <li><strong>Clear Browser Cache:</strong> Press Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)</li>
            <li><strong>Check if you're on the right page:</strong> http://localhost/rota-app-main/users/shifts.php</li>
            <li><strong>Make sure you have shifts:</strong> Add a test shift if needed</li>
            <li><strong>Check browser console:</strong> Press F12 and look for JavaScript errors</li>
            <li><strong>Verify table exists:</strong> See Test 4 above</li>
        </ol>
    </div>

</body>

</html>