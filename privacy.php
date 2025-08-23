<?php
// Minimal privacy policy page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - Open Rota</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
    body { font-family: Arial, Helvetica, sans-serif; background: url("images/backg3.jpg") no-repeat center center fixed; background-size: cover; margin:0; padding:20px; }
        .privacy-container { max-width:920px; margin:40px auto; padding:28px; background:#fff; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,0.06); }
        .privacy-container h1 { color:#222; margin-bottom:8px; }
        .privacy-container h2 { color:#333; margin-top:18px; }
        .privacy-container p, .privacy-container li { color:#444; line-height:1.5; }
        .privacy-actions { margin-top:22px; display:flex; gap:12px; align-items:center; }
        .btn-ghost { display:inline-block; padding:10px 14px; border-radius:8px; background:transparent; color:#fd2b2b; border:1px solid #fd2b2b; text-decoration:none; font-weight:600; }
        .btn-primary { display:inline-block; padding:10px 14px; border-radius:8px; background:#fd2b2b; color:#fff; text-decoration:none; font-weight:700; }
    </style>
</head>
<body>
    <?php
    // Include session so we can show user-aware controls if logged in
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $csrf_token = '';
    if (isset($_SESSION['user_id'])) {
        // generate a token for forms
        require_once __DIR__ . '/includes/csrf.php';
        $csrf_token = generate_csrf_token();
    }
    ?>
    <div class="privacy-container">
        <h1>Privacy Policy</h1>
        <p>Open Rota is an internal application used by the company. This page summarises how we handle personal data.</p>
        <h2>Data we collect</h2>
        <ul>
            <li>Basic account details (name, email, role)</li>
            <li>Authentication data (password hashes)</li>
            <li>Shift and payroll information necessary for operations</li>
        </ul>
        <h2>Why we collect it</h2>
        <p>We process personal data to manage employee schedules, payroll, and internal administration. Processing is necessary for contract performance and legitimate interests of the company.</p>
    <h2>Cookies</h2>
    <p>We use only essential cookies necessary for authentication and session management (for example, session cookies). No analytics or tracking cookies are used by this application.</p>
        <h2>Your rights</h2>
        <p>You can request access, rectification, erasure or portability of your personal data. To make a request, contact: carringtonattebilasoftware@gmail.com</p>
        <p>For more details, contact the data controller at the address above.</p>

        <h2>Audit logs and administrative access</h2>
        <p>We maintain an internal audit log that records administrative and system events, such as user logins, role changes, data exports, and other administrative actions. Audit entries typically include:</p>
        <ul>
            <li>User identifier (id and username when available)</li>
            <li>Action performed and any relevant metadata</li>
            <li>IP address, user agent and timestamp</li>
        </ul>
        <p>Access to the audit log is strictly limited to super administrators and authorized staff for security and compliance purposes. Audit logs may be exported by authorized administrators for investigation or record-keeping; these exports are treated as internal confidential records.</p>

        <h2>Retention and access</h2>
        <p>Audit logs and system records are retained for operational and compliance reasons. Retention periods may vary depending on legal, regulatory, or business needs. If you require a specific retention or deletion request beyond the standard policy, contact the data controller at the email above.</p>
        <div class="privacy-actions">
            <a href="functions/login.php" class="btn-ghost">Back to Login</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" action="functions/export_data.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlentities($csrf_token); ?>">
                    <button class="btn-ghost" type="submit">Export my data (JSON)</button>
                </form>

                <form method="POST" action="functions/erase_account.php" style="display:inline; margin-left:8px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlentities($csrf_token); ?>">
                    <input type="hidden" name="confirm" value="ERASE">
                    <button class="btn-primary" type="submit" onclick="return confirm('This will permanently erase your account and data. Type ERASE to confirm in the field provided.');">Erase my account</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
