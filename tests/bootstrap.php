<?php
declare(strict_types=1);

// Minimal bootstrap to keep tests side-effect free.
date_default_timezone_set('UTC');

if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE) {
	@session_start();
}

// Flag for code that wants to detect unit test context.
if (!defined('UNIT_TEST')) {
	define('UNIT_TEST', true);
}

// Keep tests independent of Composer autoload to avoid platform_check fatal on PHP < required by vendor.
// Rely on environment variables already present or provided by CI.
if (!getenv('APP_ENV')) {
	putenv('APP_ENV=test');
	$_ENV['APP_ENV'] = 'test';
}
