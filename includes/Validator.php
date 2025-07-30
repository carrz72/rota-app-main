<?php
/**
 * Input Validation and Sanitization
 * Centralized validation logic for form inputs
 */

class Validator
{
    private $errors = [];
    private $data = [];

    public function __construct($data = [])
    {
        $this->data = $data;
    }

    /**
     * Validate required field
     */
    public function required($field, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (empty(trim($value))) {
            $this->errors[$field] = $message ?? ucfirst($field) . " is required.";
        }
        return $this;
    }

    /**
     * Validate email format
     */
    public function email($field, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $message ?? "Please enter a valid email address.";
        }
        return $this;
    }

    /**
     * Validate minimum length
     */
    public function minLength($field, $length, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && strlen($value) < $length) {
            $this->errors[$field] = $message ?? ucfirst($field) . " must be at least {$length} characters.";
        }
        return $this;
    }

    /**
     * Validate maximum length
     */
    public function maxLength($field, $length, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (strlen($value) > $length) {
            $this->errors[$field] = $message ?? ucfirst($field) . " must not exceed {$length} characters.";
        }
        return $this;
    }

    /**
     * Validate numeric value
     */
    public function numeric($field, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !is_numeric($value)) {
            $this->errors[$field] = $message ?? ucfirst($field) . " must be a number.";
        }
        return $this;
    }

    /**
     * Validate date format
     */
    public function date($field, $format = 'Y-m-d', $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value)) {
            $date = DateTime::createFromFormat($format, $value);
            if (!$date || $date->format($format) !== $value) {
                $this->errors[$field] = $message ?? ucfirst($field) . " must be a valid date.";
            }
        }
        return $this;
    }

    /**
     * Validate time format
     */
    public function time($field, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
            $this->errors[$field] = $message ?? ucfirst($field) . " must be a valid time (HH:MM).";
        }
        return $this;
    }

    /**
     * Validate that field is in array of allowed values
     */
    public function in($field, $allowedValues, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !in_array($value, $allowedValues)) {
            $this->errors[$field] = $message ?? ucfirst($field) . " contains an invalid value.";
        }
        return $this;
    }

    /**
     * Validate phone number format
     */
    public function phone($field, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !preg_match('/^[\+]?[0-9\s\-\(\)]{10,}$/', $value)) {
            $this->errors[$field] = $message ?? "Please enter a valid phone number.";
        }
        return $this;
    }

    /**
     * Custom validation with callback
     */
    public function custom($field, $callback, $message = null)
    {
        $value = $this->data[$field] ?? '';
        if (!$callback($value)) {
            $this->errors[$field] = $message ?? ucfirst($field) . " is invalid.";
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function passes()
    {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails()
    {
        return !$this->passes();
    }

    /**
     * Get all errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get first error
     */
    public function getFirstError()
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * Get sanitized data
     */
    public function getSanitizedData()
    {
        $sanitized = [];
        foreach ($this->data as $key => $value) {
            $sanitized[$key] = $this->sanitize($value);
        }
        return $sanitized;
    }

    /**
     * Sanitize individual value
     */
    private function sanitize($value)
    {
        if (is_string($value)) {
            return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
        return $value;
    }

    /**
     * Static method for quick validation
     */
    public static function make($data)
    {
        return new self($data);
    }

    /**
     * Validate branch data
     */
    public static function validateBranch($data)
    {
        return self::make($data)
            ->required('name')
            ->maxLength('name', 100)
            ->required('code')
            ->maxLength('code', 10)
            ->email('email')
            ->phone('phone')
            ->maxLength('address', 255);
    }

    /**
     * Validate user data
     */
    public static function validateUser($data, $isUpdate = false)
    {
        $validator = self::make($data)
            ->required('username')
            ->minLength('username', 3)
            ->maxLength('username', 50)
            ->required('email')
            ->email('email')
            ->in('role', ['user', 'admin', 'super_admin']);

        if (!$isUpdate) {
            $validator->required('password')->minLength('password', 6);
        }

        return $validator;
    }

    /**
     * Validate shift data
     */
    public static function validateShift($data)
    {
        return self::make($data)
            ->required('user_id')
            ->numeric('user_id')
            ->required('shift_date')
            ->date('shift_date')
            ->required('start_time')
            ->time('start_time')
            ->required('end_time')
            ->time('end_time')
            ->custom('end_time', function ($endTime) use ($data) {
                $startTime = $data['start_time'] ?? '';
                return empty($startTime) || empty($endTime) || $endTime > $startTime;
            }, 'End time must be after start time');
    }

    /**
     * Validate cross-branch request data
     */
    public static function validateCrossBranchRequest($data)
    {
        return self::make($data)
            ->required('target_branch_id')
            ->numeric('target_branch_id')
            ->required('shift_date')
            ->date('shift_date')
            ->required('start_time')
            ->time('start_time')
            ->required('end_time')
            ->time('end_time')
            ->required('role_required')
            ->maxLength('role_required', 100)
            ->required('urgency_level')
            ->in('urgency_level', ['low', 'medium', 'high', 'urgent'])
            ->maxLength('description', 500)
            ->required('expires_hours')
            ->numeric('expires_hours')
            ->custom('end_time', function ($endTime) use ($data) {
                $startTime = $data['start_time'] ?? '';
                return empty($startTime) || empty($endTime) || $endTime > $startTime;
            }, 'End time must be after start time')
            ->custom('shift_date', function ($shiftDate) {
                return empty($shiftDate) || strtotime($shiftDate) >= strtotime('today');
            }, 'Shift date must be today or in the future')
            ->custom('expires_hours', function ($expiresHours) {
                return empty($expiresHours) || ($expiresHours >= 1 && $expiresHours <= 168);
            }, 'Expiration must be between 1 and 168 hours (1 week)');
    }
}
?>