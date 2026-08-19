<?php
/**
 * File: frontend/manager/shortage_incidents.php
 * Purpose: Ghi nhận sự cố thiếu hàng (VD: phát hiện qua phản hồi khách hàng
 * dù dashboard chưa cảnh báo kịp) và xử lý đóng sự cố. Dùng
 * ManagerService::logShortageIncident()/resolveShortageIncident()/
 * listShortageIncidents() (đã có sẵn).
 * Related: FR-MGR-07
 *
 * Đây chính là mảnh ghép "đóng vòng lặp" đã bàn từ Tutorial 2/3: có cảnh
 * báo sớm (FR-MGR-12 Top 10 Stock-out Risk) NHƯNG vẫn cần cơ chế xử lý khi
 * lỡ vẫn xảy ra thiếu hàng ngoài dự kiến - đúng root cause "shortages
 * detected only after customer complaints" từ Tutorial 1.
 *
 * Style/layout đồng bộ frontend/manager/dashboard.php (app-shell + panel-card
 * + status-badge). Modal "Resolve" của từng dòng được RENDER RIÊNG SAU
 * </table> (không còn lồng <div class="modal"> bên trong <tbody> như bản cũ -
 * đó là HTML không hợp lệ vì tbody chỉ được phép chứa <tr>).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/ManagerService.php';
require_once __DIR__ . '/../../backend/models/Product.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();
$productModel = new Product();

$flashMessage = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $result = $managerService->logShortageIncident(
            (int) ($_POST['product_id'] ?? 0),
            Auth::id(),
            $_POST['resolution_action'] ?: null
        );
    } elseif ($action === 'resolve') {
        $result = $managerService->resolveShortageIncident(
            (int) ($_POST['incident_id'] ?? 0),
            $_POST['resolution_action'] ?? ''
        );
    } else {
        $result = ['success' => false, 'message' => 'Hành động không hợp lệ.'];
    }

    $flashMessage = $result['message'];
    $flashType = $result['success'] ? 'success' : 'danger';
}

$filterStatus = $_GET['status'] ?? null;
$incidents = $managerService->listShortageIncidents($filterStatus ?: null);
$products = $productModel->getAll();

$pageTitle   = 'Shortage Incidents';
$breadcrumbs = ['Manager', 'Shortage Incidents'];
$activeMenu  = 'shortage';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shortage Incidents - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme_variables.css">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">Shortage Incidents</h2>
                        <p class="page-subheading mb-0">Ghi nhận và xử lý các sự cố thiếu hàng ngoài dự kiến (FR-MGR-07).</p>
                    </div>
                    <button type="button" class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#logIncidentModal">
                        + Ghi nhận sự cố
                    </button>
                </div>

                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Filter theo trạng thái -->
                <div class="panel-card mb-3">
                    <form method="get" class="filter-bar p-1">
                        <div>
                            <label class="form-label">Trạng thái</label>
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tất cả trạng thái</option>
                                <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
                                <option value="Resolved" <?= $filterStatus === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>
                        <?php if ($filterStatus): ?>
                            <a href="shortage_incidents.php" class="btn btn-outline-secondary btn-sm">Xóa lọc</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">
                            Danh sách sự cố
                            <span class="badge-count"><?= count($incidents) ?></span>
                        </h3>
                    </div>

                    <?php if (empty($incidents)): ?>
                        <div class="empty-state">Không có sự cố thiếu hàng nào.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Sản phẩm</th>
                                        <th>Người ghi nhận</th>
                                        <th>Trạng thái</th>
                                        <th>Hướng xử lý</th>
                                        <th>Ngày tạo</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($incidents as $incident): ?>
                                        <tr>
                                            <td class="text-muted"><?= htmlspecialchars($incident['sku_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($incident['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($incident['handled_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php if ($incident['status'] === 'Open'): ?>
                                                    <span class="status-badge status-badge-warning">Open</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-badge-success">Resolved</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted small"><?= htmlspecialchars($incident['resolution_action'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-muted"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $incident['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end">
                                                <?php if ($incident['status'] === 'Open'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-success"
                                                            data-bs-toggle="modal" data-bs-target="#resolveModal-<?= (int) $incident['incident_id'] ?>">
                                                        Xử lý
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <!-- Modal: Log new incident -->
    <div class="modal fade" id="logIncidentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Ghi nhận sự cố thiếu hàng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Sản phẩm</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Chọn sản phẩm --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int) $product['product_id'] ?>">
                                    <?= htmlspecialchars($product['sku_code'] . ' - ' . $product['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú ban đầu (tùy chọn)</label>
                        <textarea name="resolution_action" class="form-control" rows="2"
                                  placeholder="VD: Nhận phản ánh từ khách hàng, đang kiểm tra với nhân viên cửa hàng..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-brand">Ghi nhận</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Resolve incident (1 modal riêng cho mỗi sự cố đang Open) -->
    <?php foreach ($incidents as $incident): ?>
        <?php if ($incident['status'] === 'Open'): ?>
            <div class="modal fade" id="resolveModal-<?= (int) $incident['incident_id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="post" class="modal-content">
                        <input type="hidden" name="action" value="resolve">
                        <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Xử lý sự cố: <?= htmlspecialchars($incident['product_name'], ENT_QUOTES, 'UTF-8') ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label">Hướng xử lý</label>
                            <textarea name="resolution_action" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-success">Đánh dấu đã xử lý</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>