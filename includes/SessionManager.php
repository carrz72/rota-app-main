<?php
/**
 * Session and Message Management
 * Centralized handling of session messages and user notifications
 */

class SessionManager
{

    /**
     * Set success message
     */
    public static function setSuccess($message)
    {
        $_SESSION['success_message'] = $message;
    }

    /**
     * Set error message
     */
    public static function setError($message)
    {
        $_SESSION['error_message'] = $message;
    }

    /**
     * Set warning message
     */
    public static function setWarning($message)
    {
        $_SESSION['warning_message'] = $message;
    }

    /**
     * Set info message
     */
    public static function setInfo($message)
    {
        $_SESSION['info_message'] = $message;
    }

    /**
     * Get and clear success message
     */
    public static function getSuccess()
    {
        $message = $_SESSION['success_message'] ?? null;
        unset($_SESSION['success_message']);
        return $message;
    }

    /**
     * Get and clear error message
     */
    public static function getError()
    {
        $message = $_SESSION['error_message'] ?? null;
        unset($_SESSION['error_message']);
        return $message;
    }

    /**
     * Get and clear warning message
     */
    public static function getWarning()
    {
        $message = $_SESSION['warning_message'] ?? null;
        unset($_SESSION['warning_message']);
        return $message;
    }

    /**
     * Get and clear info message
     */
    public static function getInfo()
    {
        $message = $_SESSION['info_message'] ?? null;
        unset($_SESSION['info_message']);
        return $message;
    }

    /**
     * Get all messages and clear them
     */
    public static function getAllMessages()
    {
        return [
            'success' => self::getSuccess(),
            'error' => self::getError(),
            'warning' => self::getWarning(),
            'info' => self::getInfo()
        ];
    }

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get current user ID
     */
    public static function getUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current username
     */
    public static function getUsername()
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Get current user role
     */
    public static function getUserRole()
    {
        return $_SESSION['role'] ?? null;
    }

    /**
     * Set user session data
     */
    public static function setUserSession($userId, $username, $role)
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = $role;
    }

    /**
     * Clear user session
     */
    public static function clearUserSession()
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        unset($_SESSION['role']);
    }

    /**
     * Redirect with message
     */
    public static function redirectWithSuccess($url, $message)
    {
        self::setSuccess($message);
        header("Location: $url");
        exit();
    }

    /**
     * Redirect with error
     */
    public static function redirectWithError($url, $message)
    {
        self::setError($message);
        header("Location: $url");
        exit();
    }

    /**
     * Require login
     */
    public static function requireLogin()
    {
        if (!self::isLoggedIn()) {
            self::setError("You must be logged in to access this page.");
            header("Location: ../index.php");
            exit();
        }
    }

    /**
     * Require admin role
     */
    public static function requireAdmin()
    {
        self::requireLogin();
        if (!in_array(self::getUserRole(), ['admin', 'super_admin'])) {
            self::setError("Administrator privileges required.");
            header("Location: ../users/dashboard.php");
            exit();
        }
    }

    /**
     * Display messages HTML
     */
    public static function displayMessages()
    {
        $messages = self::getAllMessages();
        $html = '';

        foreach ($messages as $type => $message) {
            if ($message) {
                $icon = self::getMessageIcon($type);
                $class = "alert alert-{$type}";
                $html .= "<div class=\"{$class}\"><i class=\"{$icon}\"></i> {$message}</div>";
            }
        }

        return $html;
    }

    /**
     * Get appropriate icon for message type
     */
    private static function getMessageIcon($type)
    {
        $icons = [
            'success' => 'fas fa-check-circle',
            'error' => 'fas fa-exclamation-circle',
            'warning' => 'fas fa-exclamation-triangle',
            'info' => 'fas fa-info-circle'
        ];

        return $icons[$type] ?? 'fas fa-info-circle';
    }
}
?>