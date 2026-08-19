<?php

declare(strict_types=1);

// 1. Múi giờ hệ thống - Việt Nam (ảnh hưởng tới mọi NOW()/date() trong PHP & MySQL)
date_default_timezone_set('Asia/Ho_Chi_Minh');

// 2. Đường dẫn thư mục gốc (dùng require/include tuyệt đối, tránh lỗi path)

define('ROOT_PATH', dirname(__DIR__, 2));          // .../InventoryDSS_Group3_INS328201
define('BACKEND_PATH', ROOT_PATH . '/backend');
define('FRONTEND_PATH', ROOT_PATH . '/frontend');
define('CORE_PATH', BACKEND_PATH . '/core');
define('MODELS_PATH', BACKEND_PATH . '/models');
define('SERVICES_PATH', BACKEND_PATH . '/services');
define('API_PATH', BACKEND_PATH . '/api');

// URL gốc của ứng dụng (dùng cho redirect, link tuyệt đối trong header/sidebar)
// Tự động phát hiện đường dẫn khi app được đặt trong thư mục con của htdocs.
$baseUrl = '/InventoryDSS_Group3_INS328201/frontend';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $scriptName = $_SERVER['SCRIPT_NAME'];
    if (false !== ($pos = strpos($scriptName, '/frontend'))) {
        $baseUrl = substr($scriptName, 0, $pos + strlen('/frontend'));
    } elseif (false !== ($pos = strpos($scriptName, '/backend'))) {
        $baseUrl = substr($scriptName, 0, $pos) . '/frontend';
    }
}
define('BASE_URL', rtrim($baseUrl, '/'));

// 3. Cấu hình Session (đăng nhập/đăng xuất - FR-SYS-01)
define('SESSION_NAME', 'INVENTORYDSS_SESSID');
define('SESSION_LIFETIME_SECONDS', 8 * 60 * 60); // 8 tiếng ~ 1 ca làm việc

// 4. RBAC - Định danh Role (khớp bảng roles trong DB: role_id 1/2/3)
// Dùng hằng số thay vì hardcode số/string rải rác khắp code -> dễ bảo trì (NFR-07)
define('ROLE_ADMIN', 1);
define('ROLE_MANAGER', 2);
define('ROLE_STAFF', 3);

define('ROLE_NAMES', [
    ROLE_ADMIN   => 'Admin',
    ROLE_MANAGER => 'Manager',
    ROLE_STAFF   => 'Store Staff',
]);

// 5. Hằng số nghiệp vụ (Business Rules) - tránh magic number rải rác trong Service

// BR-18 / NFR-06: Timeout gọi AI Forecast API trước khi fallback về Reorder Point.
// 12 giây đủ cho một lượt fit model cục bộ đầu tiên, nhưng vẫn không để Manager chờ vô hạn.
define('FORECAST_API_TIMEOUT_SECONDS', 12);

// Hợp đồng Forecast: dùng chuỗi ngày liên tục (bao gồm ngày không phát sinh bán hàng)
// để model nhận diện đúng ngày nhu cầu bằng 0 thay vì hiểu là dữ liệu bị thiếu.
define('FORECAST_HISTORY_DAYS', 56);
define('FORECAST_HORIZON_DAYS', 7);

// FR-MGR-12: "Top 10 Stock-out Risk" tính theo doanh số bán trung bình N ngày gần nhất
define('STOCKOUT_RISK_SALES_WINDOW_DAYS', 7);

// FR-STF-13: Toggle xem lịch sử bán hàng 7/30 ngày
define('SALES_HISTORY_SHORT_RANGE_DAYS', 7);
define('SALES_HISTORY_LONG_RANGE_DAYS', 30);

// User Story: cảnh báo lô hàng tươi sống sắp hết hạn trong 12-24h tới
define('EXPIRY_ALERT_WINDOW_HOURS', 24);

// Admin dashboard "Product Mix": số category lớn nhất hiển thị riêng trên
// donut chart, phần còn lại gộp vào 1 lát "Khác" - tránh donut/legend vỡ
// layout khi hệ thống có nhiều category (xem AdminService::getSystemSummary()).
define('PRODUCT_MIX_TOP_N', 5);

// 6. Chế độ môi trường (dùng để bật/tắt hiển thị lỗi chi tiết)
define('APP_ENV', 'production'); // 'development' | 'production'

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// 7. FR-ADM-10: Backup/Restore CSDL - cấu hình hạ tầng cho AdminService::backupDatabase()/restoreDatabase()
//
// ⚠️ MÔI TRƯỜNG LOCAL (XAMPP trên Windows, khớp backend/config/database.php).
// BACKUP_MYSQL_BIN_DIR là THƯ MỤC chứa mysqldump.exe/mysql.exe - đường dẫn
// XAMPP mặc định trên Windows. Nếu máy bạn cài XAMPP ở ổ đĩa khác hoặc dùng
// MySQL/MariaDB cài riêng (không qua XAMPP), SỬA LẠI hằng số này cho khớp -
// đây là điểm DUY NHẤT cần đổi, code gọi shell_exec() không cần sửa gì thêm.
// Trên Linux, nếu mysqldump/mysql đã có sẵn trong $PATH, để chuỗi rỗng ''.
define('BACKUP_MYSQL_BIN_DIR', 'C:\\xampp\\mysql\\bin\\');

// Thư mục lưu file .sql backup - phải có quyền ghi (XAMPP mặc định user chạy
// PHP có quyền ghi trong htdocs). KHÔNG đặt trong frontend/ hay bất kỳ thư
// mục nào web server có thể serve trực tiếp - file backup chứa toàn bộ dữ
// liệu (kể cả password_hash), lộ ra ngoài Internet là rủi ro bảo mật nghiêm
// trọng. backend/storage/ nằm ngoài frontend/ nên an toàn theo kiến trúc
// hiện tại của repo (chỉ frontend/ được cấu hình làm document root).
define('BACKUP_STORAGE_DIR', ROOT_PATH . '/backend/storage/backups');

// Credential DB tách riêng cho module backup (Database.php giữ private const,
// không expose ra ngoài để PDO connection không bị truy cập trái phép từ
// code khác - nhưng mysqldump/mysql CLI cần credential dạng tham số dòng
// lệnh, nên khai báo lại ở đây. PHẢI LUÔN khớp với backend/config/database.php -
// nếu đổi 1 chỗ, nhớ đổi luôn chỗ còn lại).
define('BACKUP_DB_HOST', '127.0.0.1');
define('BACKUP_DB_PORT', '3306');
define('BACKUP_DB_NAME', 'project2');
define('BACKUP_DB_USER', 'root');
define('BACKUP_DB_PASS', '');