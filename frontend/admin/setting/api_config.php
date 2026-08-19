<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/AdminService.php';

Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiName = trim((string) ($_POST['api_name'] ?? ''));
    $endpointUrl = trim((string) ($_POST['endpoint_url'] ?? ''));
    $apiKey = (string) ($_POST['api_key'] ?? '');

    if ($apiName && $endpointUrl && $apiKey) {
        $result = $adminService->upsertApiConfig($apiName, $endpointUrl, $apiKey, (int) $_SESSION['account_id']);
        if ($result['success']) {
            $successMessage = $result['message'];
        } else {
            $errorMessage = $result['message'];
        }
    } else {
        $errorMessage = 'Tất cả các trường đều bắt buộc.';
    }
}

$configs = $adminService->listApiConfigs();
$activeMenu = 'settings';
$pageTitle = 'Cấu hình API';
$breadcrumbs = ['Admin', 'Cấu hình API'];
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?> - InventoryDSS</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
    <style>
        .api-config-page { padding: 24px; max-width: 1200px; margin: 0 auto; }
        .api-config-intro { margin-bottom: 24px; }
        .api-config-intro h2 { margin: 0 0 8px; color: #172b4d; font-size: 24px; }
        .api-config-intro p { margin: 0; color: #5e6c84; max-width: 800px; line-height: 1.5; }
        .api-config-form, .api-config-list { background: #fff; border: 1px solid #e7ecf2; border-radius: 14px; box-shadow: 0 3px 10px rgba(9,30,66,.05); padding: 18px; margin-bottom: 24px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; color: #344563; margin-bottom: 7px; }
        .form-group input, .form-group textarea { width: 100%; border: 1px solid #c1c7d0; border-radius: 8px; padding: 11px 12px; color: #172b4d; background: #fff; font-family: inherit; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-actions { display: flex; gap: 8px; margin-top: 20px; }
        .btn { border: 0; border-radius: 8px; padding: 11px 17px; font-weight: 700; color: #fff; background: #0052cc; cursor: pointer; min-height: 42px; }
        .btn:hover { background: #0747a6; }
        .btn:disabled { opacity: .65; cursor: wait; }
        .btn-secondary { background: #626f86; }
        .btn-secondary:hover { background: #44546f; }
        .alert { padding: 12px 14px; border-radius: 9px; font-size: 14px; margin-bottom: 18px; }
        .alert.success { color: #155724; background: #e3fcef; }
        .alert.error { color: #ae2e24; background: #ffebe6; }
        .api-config-list h3 { margin: 0 0 16px; color: #172b4d; font-size: 16px; }
        .config-item { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 12px; align-items: center; padding: 12px 0; border-bottom: 1px solid #ebecf0; }
        .config-item:last-child { border-bottom: none; }
        .config-label { font-size: 13px; color: #5e6c84; }
        .config-value { color: #172b4d; font-size: 14px; word-break: break-all; }
        .config-status { font-size: 12px; color: #5e6c84; }
        .api-name { font-weight: 700; color: #172b4d; }
        .empty-state { text-align: center; padding: 30px; color: #6b778c; }
        @media(max-width: 768px) {
            .api-config-page { padding: 16px; }
            .config-item { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="app-shell">
<?php require __DIR__ . '/../components/sidebar.php'; ?>
<main class="app-main">
    <?php require __DIR__ . '/../components/header.php'; ?>
    <section class="api-config-page">
        <div class="api-config-intro">
            <h2>Cấu hình API</h2>
            <p>Cấu hình các API bên ngoài như Demand Forecast API, Notification API, v.v. Admin cần thiết lập endpoint URL và API key để hệ thống có thể gọi tới các dịch vụ này.</p>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="api-config-form">
            <h3>Thêm / Cập nhật cấu hình API</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="api_name">Tên API <span style="color: #ae2e24;">*</span></label>
                    <input type="text" id="api_name" name="api_name" required placeholder="VD: Demand_Forecast_API, AI_Demand_Forecast" style="max-width: 400px;">
                    <small style="color: #5e6c84; display: block; margin-top: 4px;">Tên định danh của API (VD: Demand_Forecast_API, Notification_API)</small>
                </div>

                <div class="form-group">
                    <label for="endpoint_url">Endpoint URL <span style="color: #ae2e24;">*</span></label>
                    <input type="url" id="endpoint_url" name="endpoint_url" required placeholder="VD: http://localhost:8000 hoặc http://forecast-api.example.com">
                    <small style="color: #5e6c84; display: block; margin-top: 4px;">URL gốc của dịch vụ API (sẽ được tự động thêm /forecast nếu cần)</small>
                </div>

                <div class="form-group">
                    <label for="api_key">API Key <span style="color: #ae2e24;">*</span></label>
                    <input type="password" id="api_key" name="api_key" required placeholder="Nhập API key bảo mật">
                    <small style="color: #5e6c84; display: block; margin-top: 4px;">Khóa bảo mật để xác thực khi gọi API (sẽ được mã hóa trong DB)</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Lưu cấu hình API</button>
                </div>
            </form>
        </section>

        <section class="api-config-list">
            <h3>Cấu hình API hiện tại</h3>
            <?php if (empty($configs)): ?>
                <div class="empty-state">Chưa có API nào được cấu hình. Hãy thêm mới ở trên.</div>
            <?php else: ?>
                <?php foreach ($configs as $config): ?>
                    <div class="config-item">
                        <div>
                            <div class="api-name"><?= htmlspecialchars($config['api_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="config-label">Tên API</div>
                        </div>
                        <div>
                            <div class="config-value"><?= htmlspecialchars($config['endpoint_url'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="config-label">Endpoint URL</div>
                        </div>
                        <div>
                            <div class="config-status">Được cấu hình bởi: <?= htmlspecialchars($config['configured_by_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="config-label">Thông tin</div>
                        </div>
                        <div style="text-align: center; font-size: 12px; color: #0052cc;">✓ Hoạt động</div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </section>
</main>
</body>
</html>