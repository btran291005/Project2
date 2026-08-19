<?php
/**
 * File: frontend/admin/setting/backup_download.php
 * Purpose: Tải file .sql của 1 bản backup đã tạo thành công (FR-ADM-10).
 * Related: FR-ADM-10
 * Calls: AdminService::listBackupHistory() (để xác thực backup_id thuộc về
 *        1 dòng backup_history có thật, tránh path traversal)
 *
 * LÝ DO CẦN FILE RIÊNG (không link thẳng <a href="...file.sql">):
 * File backup nằm trong backend/storage/backups/ - NGOÀI mọi thư mục mà
 * frontend/.htaccess hay routing thường cho phép truy cập trực tiếp, và
 * backend/.htaccess đã chặn hẳn (Require all denied) vì file chứa toàn bộ
 * dữ liệu kể cả password_hash. Script này đọc file thay người dùng, SAU KHI
 * đã xác thực: (1) đã đăng nhập Admin, (2) backup_id tồn tại thật trong
 * backup_history với status='success' (không cho tải file tùy ý qua tham số
 * đường dẫn - path traversal).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/AdminService.php';

Middleware::guard([ROLE_ADMIN]);

$backupId = (int) ($_GET['backup_id'] ?? 0);

$adminService = new AdminService();
// Chỉ tìm trong danh sách backup_history CÓ THẬT của hệ thống - không nhận
// bất kỳ path nào từ query string, loại bỏ hoàn toàn rủi ro path traversal.
$history = $adminService->listBackupHistory(1000);
$target = null;
foreach ($history as $row) {
    if ((int) $row['backup_id'] === $backupId) {
        $target = $row;
        break;
    }
}

if ($target === null || $target['status'] !== 'success' || $target['file_path'] === null) {
    http_response_code(404);
    echo 'No valid backup found to download.';
    exit;
}

$filePath = $target['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'The backup file no longer exists on the server.';
    exit;
}

Logger::logCurrentUser('DOWNLOAD_BACKUP', 'backup_history', $backupId);

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;