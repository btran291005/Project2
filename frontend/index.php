<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

switch (Auth::roleId()) {
    case ROLE_ADMIN:
        header('Location: admin/dashboard.php');
        break;

    case ROLE_MANAGER:
        header('Location: manager/dashboard.php');
        break;

    case ROLE_STAFF:
        header('Location: staff/dashboard.php');
        break;

    default:
        // Trường hợp bất thường: role_id trong session không khớp role nào đã biết -> không đoán mò, buộc đăng xuất để tránh truy cập sai chỗ.
        header('Location: logout.php');
        break;
}
exit;