<?php
/**
 * File: frontend/manager/supplier_leadtime.php
 * Purpose: Báo cáo lead-time và tỉ lệ sai lệch giao hàng của từng nhà cung
 * cấp, kèm gợi ý nhà cung cấp tin cậy nhất khi chuẩn bị tạo PO mới. Dùng
 * ManagerService::listSuppliers()/getSupplierPerformance()/
 * getMostReliableSuppliers() (đã có sẵn).
 * Related: FR-MGR-11
 *
 * Style/layout đồng bộ frontend/manager/dashboard.php (app-shell + panel-card
 * + status-badge, thay cho Bootstrap card/badge thuần trước đây).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();

$suppliers = $managerService->listSuppliers();
$mostReliable = $managerService->getMostReliableSuppliers(5);

// Gộp performance stats cho từng supplier hiển thị ở bảng chính
$performanceBySupplierId = [];
foreach ($suppliers as $supplier) {
    $stats = $managerService->getSupplierPerformance((int) $supplier['supplier_id']);
    $performanceBySupplierId[(int) $supplier['supplier_id']] = $stats;
}

/** Phân loại mức độ sai lệch để chọn class status-badge phù hợp. */
function discrepancyBadgeClass(float $ratePercent): string
{
    if ($ratePercent > 20) {
        return 'status-badge-danger';
    }
    if ($ratePercent > 5) {
        return 'status-badge-warning';
    }
    return 'status-badge-success';
}

$pageTitle   = 'Supplier Lead-Time Report';
$breadcrumbs = ['Manager', 'Supplier Lead-time'];
$activeMenu  = 'lead_time';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Lead-Time Report - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../components/header.php'; ?>

            <main class="app-main">

                <div class="mb-4">
                    <h2 class="page-heading mb-1">Supplier Lead-Time & Delivery Accuracy</h2>
                    <p class="page-subheading mb-0">Đánh giá độ tin cậy nhà cung cấp dựa trên lịch sử giao hàng thực tế (FR-MGR-11).</p>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-xl-8">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Toàn bộ nhà cung cấp
                                    <span class="badge-count"><?= count($suppliers) ?></span>
                                </h3>
                            </div>

                            <?php if (empty($suppliers)): ?>
                                <div class="empty-state">Chưa có nhà cung cấp nào.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Nhà cung cấp</th>
                                                <th>Liên hệ</th>
                                                <th class="text-end">Lead-time TB (ngày)</th>
                                                <th class="text-end">Đơn đã giao</th>
                                                <th class="text-end">Tỉ lệ sai lệch</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <?php $stats = $performanceBySupplierId[(int) $supplier['supplier_id']] ?? false; ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= htmlspecialchars($supplier['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-muted"><?= htmlspecialchars($supplier['contact_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end text-muted"><?= htmlspecialchars((string) ($supplier['avg_lead_time_days'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-end"><?= $stats ? (int) $stats['total_delivered_orders'] : 0 ?></td>
                                                    <td class="text-end">
                                                        <?php if ($stats && $stats['discrepancy_rate_percent'] !== null): ?>
                                                            <?php $rate = (float) $stats['discrepancy_rate_percent']; ?>
                                                            <span class="status-badge <?= discrepancyBadgeClass($rate) ?>">
                                                                <?= $rate ?>%
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Chưa có đơn đã giao</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <p class="text-muted small mt-3 mb-0">
                                Lead-time trung bình là giá trị tham khảo do Admin cấu hình; Tỉ lệ sai lệch được tính
                                từ dữ liệu giao hàng thực tế (BR-08, BR-09, BR-10).
                            </p>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <div class="panel-card h-100">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Đáng tin cậy nhất</h3>
                            </div>

                            <?php if (empty($mostReliable)): ?>
                                <div class="empty-state">Chưa đủ lịch sử giao hàng để đánh giá.</div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($mostReliable as $i => $supplier): ?>
                                        <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom: 1px solid var(--surface-border-soft);">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="status-badge status-badge-muted">#<?= $i + 1 ?></span>
                                                <span class="fw-semibold small"><?= htmlspecialchars($supplier['supplier_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <span class="text-muted small"><?= htmlspecialchars((string) ($supplier['discrepancy_rate_percent'] ?? 0), ENT_QUOTES, 'UTF-8') ?>%</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <p class="text-muted small mt-3 mb-0">
                                Được gợi ý khi tạo Purchase Order mới, xếp hạng theo tỉ lệ sai lệch thấp nhất,
                                sau đó lead-time thấp nhất.
                            </p>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>