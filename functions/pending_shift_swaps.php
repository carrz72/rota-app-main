<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../functions/login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// If swap_id provided, show that swap; otherwise list pending swaps for this user
$swap_id = isset($_GET['swap_id']) ? (int)$_GET['swap_id'] : 0;

if ($swap_id) {
    $stmt = $conn->prepare('SELECT s.*, fs.shift_date as from_date, fs.start_time as from_start, fs.end_time as from_end, fs.location as from_location, rs.shift_date as to_date, rs.start_time as to_start, rs.end_time as to_end, rs.location as to_location FROM shift_swaps s LEFT JOIN shifts fs ON s.from_shift_id = fs.id LEFT JOIN shifts rs ON s.to_shift_id = rs.id WHERE s.id = ? AND s.to_user_id = ?');
    $stmt->execute([$swap_id, $user_id]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$swap) {
        echo "Swap not found or not permitted.";
        exit;
    }
    // Fetch recipient's own shifts so they can choose which of their shifts to offer in exchange
    $stmtMy = $conn->prepare('SELECT id, shift_date, start_time, end_time, location FROM shifts WHERE user_id = ? ORDER BY shift_date ASC, start_time ASC');
    $stmtMy->execute([$user_id]);
    $user_shifts = $stmtMy->fetchAll(PDO::FETCH_ASSOC);

    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Pending Swap</title>
        <link rel="stylesheet" href="../css/rota.css">
        <style>
            .error { color: #b71c1c; margin-top:8px; }
            .success { color: #1b5e20; margin-top:8px; }
            .shift-select { margin: 8px 0; }
        </style>
    </head>
    <body>
        <h1>Swap Proposal</h1>
        <div class="invitation">
            <p><strong>From shift:</strong> <?php echo htmlspecialchars($swap['from_date'] ?? '') . ' ' . htmlspecialchars($swap['from_start'] ?? '') . ' - ' . htmlspecialchars($swap['from_end'] ?? ''); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($swap['from_location'] ?? ''); ?></p>
            <?php if (empty($user_shifts)): ?>
                <p>You have no shifts available to offer in exchange. Please contact your manager.</p>
            <?php else: ?>
                <form id="accept-form" method="POST" action="./shift_swap.php">
                    <input type="hidden" name="action" value="accept">
                    <input type="hidden" name="swap_id" value="<?php echo $swap_id; ?>">
                    <?php if (isset($_GET['notif_id'])): ?>
                        <input type="hidden" name="notif_id" value="<?php echo htmlspecialchars($_GET['notif_id']); ?>">
                    <?php endif; ?>

                    <label for="to_shift_id">Choose one of your shifts to give in exchange:</label>
                    <div class="shift-select">
                        <select name="to_shift_id" id="to_shift_id">
                            <option value="">-- Select your shift --</option>
                            <?php foreach ($user_shifts as $us): ?>
                                <option value="<?php echo (int)$us['id']; ?>" data-date="<?php echo htmlspecialchars($us['shift_date']); ?>"><?php echo htmlspecialchars($us['shift_date'] . ' ' . $us['start_time'] . ' - ' . $us['end_time'] . ' @ ' . ($us['location'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit">Accept</button>
                    <div id="swap-msg" role="status" aria-live="polite"></div>
                </form>
            <?php endif; ?>

            <form method="POST" action="./shift_swap.php" style="margin-top:8px;" id="decline-form">
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="swap_id" value="<?php echo $swap_id; ?>">
                <?php if (isset($_GET['notif_id'])): ?>
                    <input type="hidden" name="notif_id" value="<?php echo htmlspecialchars($_GET['notif_id']); ?>">
                <?php endif; ?>
                <button type="submit">Decline</button>
            </form>
        </div>
        <p><a href="../users/dashboard.php">Back</a></p>

        <script>
            (function(){
            var form = document.getElementById('accept-form');
            if (!form) return;
            var msg = document.getElementById('swap-msg');
            var select = document.getElementById('to_shift_id');
            var submitBtn = form.querySelector('button[type=submit]');
            var fromDate = '<?php echo htmlspecialchars($swap['from_date']); ?>';

            // Prevent selecting a shift on the same date (immediate UX feedback)
            select.addEventListener('change', function(){
                msg.textContent = '';
                msg.className = '';
                var sel = select.options[select.selectedIndex];
                if (!sel || !sel.dataset) return;
                var date = sel.dataset.date || '';
                if (date === fromDate && sel.value !== '') {
                    msg.className = 'error';
                    msg.textContent = 'Selected shift is on the same date as the incoming shift and will conflict. Please choose a different shift.';
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });

            form.addEventListener('submit', function(e){
                e.preventDefault();
                msg.textContent = '';
                msg.className = '';
                var fd = new FormData(form);
                fetch('./shift_swap.php', { method: 'POST', body: fd }).then(function(res){ return res.text(); }).then(function(text){
                    text = text.trim();
                    if (text === 'accepted') {
                        msg.className = 'success';
                        msg.textContent = 'Swap accepted. Redirecting...';
                        setTimeout(function(){ window.location = '../users/dashboard.php'; }, 900);
                    } else if (text === 'conflict') {
                        msg.className = 'error';
                        msg.textContent = 'Selected shift conflicts with an existing shift on that date/time. Please choose a different shift.';
                    } else if (text === 'shift_missing') {
                        msg.className = 'error';
                        msg.textContent = 'Selected shift not found. Please choose another.';
                    } else if (text === 'not_owner_to_shift') {
                        msg.className = 'error';
                        msg.textContent = 'You do not own the selected shift. Please choose one of your shifts.';
                    } else {
                        msg.className = 'error';
                        msg.textContent = 'Server error: ' + text;
                    }
                }).catch(function(err){
                    msg.className = 'error';
                    msg.textContent = 'Network error. Please try again.';
                });
            });
        })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// List pending swaps for user
$stmt = $conn->prepare('SELECT s.id, s.from_user_id, s.from_shift_id, s.created_at, fs.shift_date, fs.start_time, fs.end_time, fs.location FROM shift_swaps s JOIN shifts fs ON s.from_shift_id = fs.id WHERE s.to_user_id = ? AND s.status = "proposed" ORDER BY s.created_at DESC');
$stmt->execute([$user_id]);
$swaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Pending Swap Proposals</title>
<link rel="stylesheet" href="../css/rota.css">
</head>
<body>
    <h1>Pending Swap Proposals</h1>
    <?php if (empty($swaps)): ?>
        <p>No pending swaps.</p>
    <?php else: ?>
        <?php foreach ($swaps as $s): ?>
            <div class="invitation">
                <p><strong>From:</strong> <?php echo htmlspecialchars($s['shift_date']) . ' ' . htmlspecialchars($s['start_time']) . ' - ' . htmlspecialchars($s['end_time']); ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($s['location']); ?></p>
                <p><a href="?swap_id=<?php echo (int)$s['id']; ?>">View & Respond</a></p>
            </div>
            <hr>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="../users/dashboard.php">Back</a></p>
</body>
</html>
