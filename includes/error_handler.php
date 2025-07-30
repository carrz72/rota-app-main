<?php
/**
 * Global Error Handler for Rota Application
 * Provides better error pages and handles session-related 404s
 */

require_once __DIR__ . '/session_manager.php';

if (!function_exists('handleApplicationError')) {
    /**
     * Handle application errors gracefully
     */
    function handleApplicationError($error_type = '404', $message = null, $details = null)
    {
        // Determine appropriate error message
        $error_messages = [
            '404' => 'Page Not Found',
            '403' => 'Access Forbidden',
            '401' => 'Authentication Required',
            '500' => 'Server Error',
            'session_expired' => 'Session Expired'
        ];

        $title = $error_messages[$error_type] ?? 'Application Error';

        if (!$message) {
            switch ($error_type) {
                case '404':
                    $message = "The page you're looking for doesn't exist or may have been moved.";
                    break;
                case '403':
                    $message = "You don't have permission to access this resource.";
                    break;
                case '401':
                    $message = "You need to log in to access this page.";
                    break;
                case 'session_expired':
                    $message = "Your session has expired. Please log in again to continue.";
                    break;
                default:
                    $message = "An unexpected error occurred. Please try again.";
            }
        }

        // Set appropriate HTTP status code
        $status_codes = [
            '404' => 404,
            '403' => 403,
            '401' => 401,
            '500' => 500,
            'session_expired' => 401
        ];

        http_response_code($status_codes[$error_type] ?? 500);

        // Show error page
        showErrorPage($title, $message, $error_type, $details);
        exit;
    }
}

if (!function_exists('showErrorPage')) {
    /**
     * Display a user-friendly error page
     */
    function showErrorPage($title, $message, $error_type, $details = null)
    {
        $current_path = $_SERVER['REQUEST_URI'] ?? '';
        $is_admin_area = strpos($current_path, '/admin/') !== false;

        // Determine appropriate actions based on error type
        $show_login_button = in_array($error_type, ['401', 'session_expired']);
        $show_home_button = true;
        $show_back_button = !$show_login_button;

        // Determine home URL
        $home_url = $is_admin_area ? '../admin/admin_dashboard.php' : '../users/dashboard.php';
        $login_url = '../functions/login.php';

        if ($show_login_button && $error_type === 'session_expired') {
            $login_url .= '?expired=1&return=' . urlencode($current_path);
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($title); ?> - Open Rota</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
            <style>
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                body {
                    font-family: Arial, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    color: #333;
                }

                .error-container {
                    background: white;
                    border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                    padding: 40px;
                    text-align: center;
                    max-width: 500px;
                    width: 100%;
                }

                .error-icon {
                    font-size: 4rem;
                    margin-bottom: 20px;
                    color: #fd2b2b;
                }

                .error-title {
                    font-size: 2rem;
                    margin-bottom: 15px;
                    color: #333;
                }

                .error-message {
                    font-size: 1.1rem;
                    line-height: 1.6;
                    margin-bottom: 30px;
                    color: #666;
                }

                .error-details {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 30px;
                    font-size: 0.9rem;
                    color: #666;
                    border-left: 4px solid #fd2b2b;
                }

                .error-actions {
                    display: flex;
                    gap: 15px;
                    justify-content: center;
                    flex-wrap: wrap;
                }

                .btn {
                    padding: 12px 24px;
                    border: none;
                    border-radius: 8px;
                    font-size: 1rem;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    transition: all 0.3s ease;
                    min-width: 120px;
                    justify-content: center;
                }

                .btn-primary {
                    background: #fd2b2b;
                    color: white;
                }

                .btn-primary:hover {
                    background: #e61919;
                    transform: translateY(-2px);
                }

                .btn-secondary {
                    background: #6c757d;
                    color: white;
                }

                .btn-secondary:hover {
                    background: #5a6268;
                    transform: translateY(-2px);
                }

                .btn-outline {
                    background: transparent;
                    color: #fd2b2b;
                    border: 2px solid #fd2b2b;
                }

                .btn-outline:hover {
                    background: #fd2b2b;
                    color: white;
                    transform: translateY(-2px);
                }

                @media (max-width: 480px) {
                    .error-container {
                        padding: 30px 20px;
                    }

                    .error-title {
                        font-size: 1.5rem;
                    }

                    .error-actions {
                        flex-direction: column;
                    }

                    .btn {
                        width: 100%;
                    }
                }
            </style>
        </head>

        <body>
            <div class="error-container">
                <div class="error-icon">
                    <?php
                    $icons = [
                        '404' => 'fas fa-search',
                        '403' => 'fas fa-ban',
                        '401' => 'fas fa-lock',
                        '500' => 'fas fa-exclamation-triangle',
                        'session_expired' => 'fas fa-clock'
                    ];
                    echo '<i class="' . ($icons[$error_type] ?? 'fas fa-exclamation-circle') . '"></i>';
                    ?>
                </div>

                <h1 class="error-title"><?php echo htmlspecialchars($title); ?></h1>
                <p class="error-message"><?php echo htmlspecialchars($message); ?></p>

                <?php if ($details): ?>
                    <div class="error-details">
                        <?php echo htmlspecialchars($details); ?>
                    </div>
                <?php endif; ?>

                <div class="error-actions">
                    <?php if ($show_login_button): ?>
                        <a href="<?php echo htmlspecialchars($login_url); ?>" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                    <?php endif; ?>

                    <?php if ($show_home_button): ?>
                        <a href="<?php echo htmlspecialchars($home_url); ?>" class="btn btn-secondary">
                            <i class="fas fa-home"></i> Go Home
                        </a>
                    <?php endif; ?>

                    <?php if ($show_back_button): ?>
                        <button onclick="history.back()" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Go Back
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                // Auto-redirect for session expired after a delay
                <?php if ($error_type === 'session_expired'): ?>
                    setTimeout(function () {
                        if (confirm('Redirecting to login page. Click OK to continue or Cancel to stay.')) {
                            window.location.href = '<?php echo htmlspecialchars($login_url); ?>';
                        }
                    }, 5000);
                <?php endif; ?>
            </script>
        </body>

        </html>
        <?php
    }
}

if (!function_exists('checkFileExists')) {
    /**
     * Check if a file exists and handle 404 appropriately
     */
    function checkFileExists($file_path, $redirect_on_missing = true)
    {
        if (!file_exists($file_path)) {
            if ($redirect_on_missing) {
                handleApplicationError('404', "The requested file could not be found.");
            }
            return false;
        }
        return true;
    }
}

if (!function_exists('handleDatabaseError')) {
    /**
     * Handle database connection errors
     */
    function handleDatabaseError($error_message = null)
    {
        error_log("Database error: " . ($error_message ?? 'Unknown database error'));
        handleApplicationError('500', 'Database connection failed. Please try again later.', $error_message);
    }
}

// Set up global error handling for uncaught exceptions
if (!function_exists('globalErrorHandler')) {
    function globalErrorHandler($errno, $errstr, $errfile, $errline)
    {
        // Don't handle suppressed errors
        if (!(error_reporting() & $errno)) {
            return false;
        }

        // Log the error
        error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");

        // For fatal errors, show error page
        if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            handleApplicationError('500', 'A system error occurred. Please try again.');
        }

        return true;
    }

    set_error_handler('globalErrorHandler');
}
?>