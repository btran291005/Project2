<?php
/**
 * File: frontend/staff/dashboard.php
 * Purpose: Dashboard tổng quan cho Store Staff - tồn kho thấp cần bổ sung gấp,
 * lô hàng sắp hết hạn, xu hướng bán 7 ngày toàn store, lối tắt tới các thao
 * tác hàng ngày (nhận hàng/kiểm kê/ghi sự cố).
 * Related: FR-STF-01, FR-STF-03, FR-STF-10, FR-STF-12, FR-STF-13
 * Calls: StaffService::getUrgentRestockList(), ::getExpiringBatches(),
 *        ::getSalesOverview7d(), ::getLastLoginTime(), ::listOrdersAwaitingReceipt()
 *
 * ⚠️ Bố cục lấy cảm hứng từ 1 mockup "Staff Task Center" do người dùng cung
 * cấp, nhưng ĐÃ ĐIỀU CHỈNH để chỉ hiển thị dữ liệu THẬT có trong hệ thống:
 *   - "Daily Progress %" (18/24 tasks) - KHÔNG có bảng task/checklist theo ca
 *     trong schema - BỎ, thay bằng số liệu thật có ý nghĩa tương đương: số
 *     sản phẩm cần bổ sung gấp + số lô sắp hết hạn (đây MỚI là "việc cần làm"
 *     thật của Staff, không phải % nhiệm vụ tùy tiện).
 *   - "YOUR SHIFT 08:00-16:00" (giờ ca cố định) - KHÔNG có bảng ca làm việc -
 *     THAY bằng giờ ĐĂNG NHẬP GẦN NHẤT lấy từ audit_logs (LOGIN gần nhất của
 *     chính tài khoản này) - đây là proxy thật, không phải giờ ca bịa ra.
 *   - "AI RECOMMENDATION" (gợi ý tự nhiên do AI viết) - KHÔNG có AI service
 *     nào sinh văn bản gợi ý - BỎ hẳn, không giả lập văn bản AI.
 *   - "Location: Aisle 4, Shelf B2" - KHÔNG có cột vị trí kệ trong products/
 *     stock - BỎ cột này khỏi bảng Restock Queue.
 *   - "Priority Alerts: Price updates & shelf tag changes" - không có nghiệp
 *     vụ này trong hệ thống - BỎ.
 *   - "Alert Feed" (nhiệt độ tủ lạnh, giao hàng mới) - không có cảm biến
 *     nhiệt độ / bảng delivery log riêng - BỎ.
 *   - "Priority" cột trong Restock Queue - GIỮ, nhưng tính từ tỉ lệ
 *     current_quantity/reorder_point thật (đã có sẵn ở getUrgentRestockList()),
 *     không phải nhãn gán tùy ý.
 *
 * Style/layout đồng bộ frontend/manager/dashboard.php (tái dùng nguyên các
 * class CSS đã có: kpi-card, panel-card, activity-chart-*, status-badge).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/StaffService.php';

// BR-19 / NFR-03: chỉ Store Staff được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_STAFF]);

$staffService = new StaffService();

$urgentRestock   = $staffService->getUrgentRestockList();
$expiringBatches = $staffService->getExpiringBatches();
$salesOverview   = $staffService->getSalesOverview7d();
$ordersAwaiting  = $staffService->listOrdersAwaitingReceipt();
$lastLoginTime   = $staffService->getLastLoginTime((int) Auth::id());

$dailyTxCounts = array_column($salesOverview['daily_transaction_count'], 'count');
$topProducts   = $salesOverview['top_products'];

$urgentCount    = count($urgentRestock);
$expiringCount  = count($expiringBatches);
$awaitingCount  = count($ordersAwaiting);

/** Nhãn ưu tiên theo tỉ lệ current_quantity/reorder_point - khớp đúng cách sắp xếp của getUrgentRestockList(). */
function restockPriorityLabel(int $currentQty, int $reorderPoint): array
{
    if ($currentQty <= 0) {
        return ['label' => 'CRITICAL', 'class' => 'status-badge-danger'];
    }
    $ratio = $reorderPoint > 0 ? $currentQty / $reorderPoint : 0;
    if ($ratio <= 0.5) {
        return ['label' => 'HIGH', 'class' => 'status-badge-danger'];
    }
    if ($ratio <= 0.8) {
        return ['label' => 'MEDIUM', 'class' => 'status-badge-warning'];
    }
    return ['label' => 'NORMAL', 'class' => 'status-badge-info'];
}

$pageTitle   = 'Dashboard';
$breadcrumbs = ['Staff', 'Dashboard'];
$activeMenu  = 'dashboard';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - InventoryDSS</title>
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

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <span class="text-muted small text-uppercase fw-semibold" style="letter-spacing: .5px;">Store Operational View</span>
                        <h2 class="page-heading mb-1">Staff Task Center</h2>
                        <p class="page-subheading mb-0">
                            Welcome, <?= htmlspecialchars((string) Auth::fullName(), ENT_QUOTES, 'UTF-8') ?>.
                            <?php if ($lastLoginTime !== null): ?>
                                Shift started at <?= htmlspecialchars(date('H:i', strtotime($lastLoginTime)), ENT_QUOTES, 'UTF-8') ?>.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- KPI: việc cần làm hôm nay - số liệu thật thay cho "% task hoàn thành" của mockup -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $urgentCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Urgent Restock</span>
                            <span class="kpi-value"><?= number_format($urgentCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card <?= $expiringCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <span class="kpi-label">Expiring Batches (<?= EXPIRY_ALERT_WINDOW_HOURS ?>h)</span>
                            <span class="kpi-value"><?= number_format($expiringCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">PO Awaiting Receipt</span>
                            <span class="kpi-value"><?= number_format($awaitingCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-3">
                        <div class="kpi-card">
                            <span class="kpi-label">Today's Transactions</span>
                            <span class="kpi-value"><?= number_format(end($dailyTxCounts) ?: 0) ?></span>
                        </div>
                    </div>
                </div>

<!-- Quick Actions: 3 card lối tắt tới các trang thao tác hàng ngày đã có sẵn.
                     Mỗi action là 1 card riêng (icon + tiêu đề + mô tả + nút). -->
                <div class="mb-4">
                    <h3 class="quick-actions-heading">Quick Actions</h3>
                    <div class="quick-actions-grid">
                        <div class="quick-action-card">
                            <span class="quick-action-card-icon">📦</span>
                            <span class="quick-action-card-title">Receive Goods</span>
                            <span class="quick-action-card-desc"><?= $awaitingCount ?> Pending Orders</span>
                            <a href="inventory/goods_receipt.php" class="quick-action-card-btn">Open</a>
                        </div>
                        <div class="quick-action-card">
                            <span class="quick-action-card-icon">🔢</span>
                            <span class="quick-action-card-title">Stock Count</span>
                            <span class="quick-action-card-desc">Start New Session</span>
                            <a href="inventory/stock_count.php" class="quick-action-card-btn">Start</a>
                        </div>
                        <div class="quick-action-card">
                            <span class="quick-action-card-icon">⚠️</span>
                            <span class="quick-action-card-title">Incident Report</span>
                            <span class="quick-action-card-desc">Customer Feedback</span>
                            <a href="customer_feedback.php" class="quick-action-card-btn">Report</a>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Restock Queue: danh sách bổ sung gấp, sắp theo mức độ nguy cấp -->
                    <div class="col-12 col-xl-7">
                        <div class="panel-card mb-3">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Restock Queue
                                    <span class="badge-count badge-count-warn"><?= $urgentCount ?></span>
                                </h3>
                                <span class="panel-card-note">Sorted by urgency (stock ÷ reorder point)</span>
                            </div>

                            <?php if (empty($urgentRestock)): ?>
                                <div class="empty-state">No products need urgent restocking. 🎉</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Current Stock</th>
                                                <th class="text-end">Reorder Point</th>
                                                <th>Priority</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($urgentRestock, 0, 8) as $item): ?>
                                                <?php $priority = restockPriorityLabel((int) $item['current_quantity'], (int) $item['reorder_point']); ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="text-muted small"><?= htmlspecialchars($item['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td class="text-end fw-semibold"><?= number_format((int) $item['current_quantity']) ?></td>
                                                    <td class="text-end text-muted"><?= number_format((int) $item['reorder_point']) ?></td>
                                                    <td><span class="status-badge <?= $priority['class'] ?>"><?= $priority['label'] ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($urgentCount > 8): ?>
                                    <div class="text-end mt-2">
                                        <a href="stock/low_stock_alerts.php" class="panel-card-link">View all <?= $urgentCount ?> products →</a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Sales trend 7 ngày toàn store -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Sales Trend</h3>
                                <span class="panel-card-note">Transactions/day, last 7 days</span>
                            </div>

                            <?php if (array_sum($dailyTxCounts) === 0): ?>
                                <div class="empty-state">No sales transactions in the last 7 days.</div>
                            <?php else: ?>
                                <?php
                                    $chartW = 700; $chartH = 200;
                                    $padTop = 20; $padBottom = 12; $padLeft = 34; $padRight = 14;
                                    $plotW = $chartW - $padLeft - $padRight;
                                    $plotH = $chartH - $padTop - $padBottom;

                                    $maxCount = max($dailyTxCounts) ?: 1;
                                    $axisMax = max(4, (int) ceil($maxCount / 4) * 4);
                                    $yTicks = [0, (int) round($axisMax * 0.5), $axisMax];

                                    $trend = $salesOverview['daily_transaction_count'];
                                    $n = count($trend);
                                    $pts = [];
                                    foreach ($trend as $i => $row) {
                                        $x = $n > 1 ? $padLeft + ($i / ($n - 1)) * $plotW : $padLeft;
                                        $y = $padTop + $plotH - (($row['count'] / $axisMax) * $plotH);
                                        $pts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'count' => $row['count']];
                                    }

                                    $linePath = '';
                                    if ($n > 0) {
                                        $linePath = 'M ' . $pts[0]['x'] . ',' . $pts[0]['y'];
                                        for ($i = 1; $i < $n; $i++) {
                                            $linePath .= ' L ' . $pts[$i]['x'] . ',' . $pts[$i]['y'];
                                        }
                                    }
                                    $areaPath = $linePath . ' L ' . ($padLeft + $plotW) . ',' . ($padTop + $plotH)
                                              . ' L ' . $padLeft . ',' . ($padTop + $plotH) . ' Z';
                                ?>
                                <div class="activity-chart-wrap">
                                    <svg class="activity-chart-svg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="xMidYMid meet">
                                        <defs>
                                            <linearGradient id="staffSalesAreaFill" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="var(--brand-primary)" stop-opacity="0.32"></stop>
                                                <stop offset="100%" stop-color="var(--brand-primary)" stop-opacity="0"></stop>
                                            </linearGradient>
                                        </defs>

                                        <?php foreach ($yTicks as $tickIdx => $tick): ?>
                                            <?php $tickY = round($padTop + $plotH - (($tick / $axisMax) * $plotH), 1); ?>
                                            <line x1="<?= $padLeft ?>" y1="<?= $tickY ?>" x2="<?= $padLeft + $plotW ?>" y2="<?= $tickY ?>"
                                                  stroke="<?= $tickIdx === 0 ? 'var(--surface-border)' : 'var(--surface-border-soft)' ?>"
                                                  stroke-width="<?= $tickIdx === 0 ? 1.5 : 1 ?>"
                                                  <?= $tickIdx === 0 ? '' : 'stroke-dasharray="3 4"' ?>></line>
                                            <text x="<?= $padLeft - 8 ?>" y="<?= $tickY + 3 ?>" text-anchor="end" class="activity-chart-axis-label"><?= $tick ?></text>
                                        <?php endforeach; ?>

                                        <path d="<?= htmlspecialchars($areaPath, ENT_QUOTES, 'UTF-8') ?>" fill="url(#staffSalesAreaFill)"></path>
                                        <path d="<?= htmlspecialchars($linePath, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="var(--brand-primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></path>

                                        <?php foreach ($pts as $i => $p): ?>
                                            <?php $isLast = $i === $n - 1; ?>
                                            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="<?= $isLast ? 5 : 3.5 ?>" fill="#fff" stroke="var(--brand-primary)" stroke-width="<?= $isLast ? 3 : 2 ?>"></circle>
                                            <text x="<?= $p['x'] ?>" y="<?= max(12, $p['y'] - 10) ?>" text-anchor="middle" class="activity-chart-point-label<?= $isLast ? ' activity-chart-point-label-current' : '' ?>"><?= (int) $p['count'] ?></text>
                                        <?php endforeach; ?>
                                    </svg>
                                    <div class="activity-chart-labels">
                                        <?php foreach ($trend as $i => $row): ?>
                                            <span class="<?= $i === $n - 1 ? 'activity-chart-label-current' : '' ?>"><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($topProducts)): ?>
                                <hr class="my-3">
                                <div class="text-muted small text-uppercase fw-semibold mb-2" style="letter-spacing: .5px;">Best Sellers (7 days)</div>
                                <?php $maxSold = max(array_column($topProducts, 'total_quantity_sold')); ?>
                                <?php foreach ($topProducts as $p): ?>
                                    <?php $pct = $maxSold > 0 ? round(((int) $p['total_quantity_sold'] / $maxSold) * 100) : 0; ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small"><?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="small fw-semibold"><?= number_format((int) $p['total_quantity_sold']) ?> units</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 6px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background: var(--brand-primary);"></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cột phải: lô sắp hết hạn -->
                    <div class="col-12 col-xl-5">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">
                                    Expiring Soon
                                    <?php if ($expiringCount > 0): ?>
                                        <span class="badge-count badge-count-warn"><?= $expiringCount ?></span>
                                    <?php endif; ?>
                                </h3>
                                <span class="panel-card-note">Within <?= EXPIRY_ALERT_WINDOW_HOURS ?> hours (FR-STF-12)</span>
                            </div>

                            <?php if (empty($expiringBatches)): ?>
                                <div class="empty-state">No batches expiring soon.</div>
                            <?php else: ?>
                                <div class="record-list">
                                    <?php foreach (array_slice($expiringBatches, 0, 8) as $batch): ?>
                                        <div class="record-card">
                                            <div class="record-card-header">
                                                <p class="record-card-title"><?= htmlspecialchars($batch['product_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                                <span class="status-badge status-badge-warning">
                                                    <?= (int) $batch['quantity_remaining'] ?> left
                                                </span>
                                            </div>
                                            <span class="record-card-meta">
                                                Expires <?= htmlspecialchars(date('H:i d/m', strtotime((string) $batch['expiry_date'])), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>