<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Middleware.php';
require_once __DIR__ . '/../services/ManagerService.php';

/*
 * Bọc TOÀN BỘ xử lý (kể cả Middleware::guardApi()) trong output buffer.
 *
 * Lý do: khi APP_ENV=development (app_config.php bật display_errors=1),
 * bất kỳ PHP warning/notice/deprecated nào phát sinh trong Middleware,
 * ManagerService, IntegrationService, ForecastAPI... sẽ bị PHP echo thẳng
 * ra output dưới dạng HTML ("<b>Warning</b>: ... in ... on line ...")
 * TRƯỚC khi tới dòng echo json_encode(...) cuối cùng. Front-end (forecast.php)
 * gọi response.json() nhận về "<b>Warning</b>...{...}" và bị lỗi
 * `"...</...>"... is not valid JSON`.
 *
 * Cách xử lý: dùng 1 hàm respond() DUY NHẤT để luôn ob_get_clean() trước khi
 * echo JSON thật sự - đảm bảo mọi output rò rỉ (dù thoát ở nhánh nào, kể cả
 * bên trong Middleware::guardApi()) đều bị chặn lại và ghi vào error_log
 * thay vì lọt ra response gửi cho trình duyệt.
 */
ob_start();

/** Gửi JSON response duy nhất 1 lần, loại bỏ mọi output rò rỉ trước đó. */
function respondForecastJson(int $httpCode, array $payload): never
{
    $leaked = ob_get_clean();
    if ($leaked !== false && trim($leaked) !== '') {
        error_log('[forecast_request.php] Leaked output before JSON: ' . substr($leaked, 0, 1000));
    }

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Bắt cả trường hợp Middleware::guardApi() tự echo JSON lỗi 401/403 rồi exit():
// output đó cũng nằm trong buffer đang mở, nên cần đóng buffer đúng cách
// ngay sau khi guardApi() chạy xong (nếu nó không exit) hoặc chặn từ đầu
// bằng cách kiểm tra quyền trước khi mở buffer thật sự cần thiết.
// -> Đơn giản và chắc chắn nhất: đăng ký shutdown handler để đảm bảo nếu
//    guardApi() (hoặc bất cứ đâu) gọi exit() trực tiếp mà không qua
//    respondForecastJson(), buffer vẫn được flush ra (không mất trắng trang),
//    dù trong trường hợp đó output vẫn luôn là JSON hợp lệ do guardApi() tự
//    kiểm soát, không lẫn HTML.
register_shutdown_function(static function (): void {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
});

Middleware::guardApi([ROLE_MANAGER]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondForecastJson(405, ['success' => false, 'message' => 'Only POST is allowed.']);
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respondForecastJson(400, ['success' => false, 'message' => 'Dữ liệu yêu cầu không hợp lệ.']);
}

$token = is_array($input) ? (string) ($input['csrf_token'] ?? '') : '';
$sessionToken = (string) ($_SESSION['forecast_csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    respondForecastJson(419, ['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.']);
}

$productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($productId === false) {
    respondForecastJson(422, ['success' => false, 'message' => 'Sản phẩm không hợp lệ.']);
}

try {
    $result = (new ManagerService())->getForecastSuggestion((int) $productId);
} catch (Throwable $e) {
    error_log('[forecast_request.php] ' . $e->getMessage());
    $result = ['success' => false, 'message' => 'Đã xảy ra lỗi khi tạo dự báo. Vui lòng thử lại.'];
}

respondForecastJson(200, $result);