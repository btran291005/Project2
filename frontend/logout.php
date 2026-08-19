<?php
/**
 * File: frontend/logout.php
 * Purpose: Ghi log LOGOUT, hủy session, redirect về login.php.
 * Related: FR-SYS-01
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::logout();

header('Location: login.php');
exit;