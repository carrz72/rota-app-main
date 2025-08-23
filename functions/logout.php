<?php
// Logout with audit logging
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

// Capture details including session id so we can correlate records
$userId = $_SESSION['user_id'] ?? null;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
$sid = session_id();

// Attempt to log logout event; don't block logout on failure
try {
	require_once __DIR__ . '/../includes/db.php';
	require_once __DIR__ . '/../includes/audit_log.php';
	try {
	$ok = log_audit($conn, $userId, 'logout', ['ip' => $ip], $userId, 'auth', $sid);
		if (!$ok) {
			error_log('audit_log insert returned false for logout user_id=' . $userId);
		}
	} catch (Exception $e) {
		error_log('audit_log exception on logout: ' . $e->getMessage());
	}
} catch (Exception $e) {
	// ignore DB/audit errors during logout
}

// Clear session data and cookies
$_SESSION = [];
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000,
		$params['path'], $params['domain'], $params['secure'], $params['httponly']
	);
}
session_destroy();

// Provide a simple page that cleans up service workers and redirects to login
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title>Logging out - Open Rota</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
	<p>Signing out...</p>
	<script>
		// Unregister service workers and clear caches to prevent serving cached authenticated pages
		(async function(){
			try {
				if ('serviceWorker' in navigator) {
					const regs = await navigator.serviceWorker.getRegistrations();
					for (const reg of regs) {
						try { await reg.unregister(); } catch(e){}
					}
				}
				if ('caches' in window) {
					const keys = await caches.keys();
					for (const k of keys) {
						try { await caches.delete(k); } catch(e){}
					}
				}
			} catch (e) {
				console.error('Service worker unregister error', e);
			}
			// Redirect to login page after cleanup
			window.location.href = '../functions/login.php';
		})();
	</script>
</body>
</html>