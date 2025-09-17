<?php
// Small privacy footer include
?>
<footer style="width:100%;padding:12px 0;text-align:center;margin-top:24px;color:#666;font-size:0.95rem;">
    <div style="max-width:1000px;margin:0 auto;display:flex;justify-content:center;gap:12px;">
        <?php
        // Determine a resilient web path to privacy.php. Try root and project subfolder if present.
        $privacyHref = '/privacy.php';
        if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $privacyHref)) {
            // Try common subfolder used in some deployments
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/rota-app-main/privacy.php')) {
                $privacyHref = '/rota-app-main/privacy.php';
            } else {
                // Fallback to relative path (best-effort)
                $privacyHref = 'privacy.php';
            }
        }
        ?>
        <a href="<?php echo htmlspecialchars($privacyHref); ?>" style="color:#444;text-decoration:none;">Privacy Policy</a>
        <span style="color:#bbb">|</span>
        <span style="color:#777">Internal Use Only</span>
    </div>
</footer>
