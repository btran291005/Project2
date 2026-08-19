<?php
/**
 * File: frontend/admin/inventory/inventory_count_history.php
 * Purpose: UI xem lịch sử kiểm kê (stock count) toàn hệ thống cho Admin, kèm
 * số dòng lệch mỗi phiên, để phát hiện bất thường giữa số đếm thực tế và số
 * liệu hệ thống (FR-ADM-09).
 * Related: FR-ADM-09, BR-14
 * Calls: AdminService::getInventoryCountHistory(), getInventoryCountDetail()
 *
 * LƯU Ý DỮ LIỆU (đối chiếu prototype tham khảo GS25 IntelliStock):
 * - Bảng stock_counts THẬT chỉ có (count_id, performed_by, count_date) - KHÔNG
 *   có warehouse_id (1 phiên kiểm kê không gắn với đúng 1 kho trong schema này)
 *   và KHÔNG có cột approval status (Approved/Pending Review). Do đó:
 *   - Cột "Warehouse" của mockup KHÔNG xuất hiện (không có dữ liệu để hiển thị).
 *   - Cột "Status" KHÔNG dùng badge Approved/Pending Review giả - thay bằng
 *     mức độ chênh lệch thật (Variance Level: Clean / Minor / Needs Review),
 *     suy ra từ discrepancy_items/total_items - do StockCount::finalizeSession()
 *     tự động ghi nhận điều chỉnh (stock_movements) ngay khi phiên hoàn tất,
 *     không có bước "chờ duyệt" riêng trong DB.
 * - "Inventory Accuracy" tính TRỰC TIẾP = 1 - (tổng discrepancy_items / tổng
 *   total_items) trên toàn bộ lịch sử đang hiển thị, không bịa số % 30 ngày
 *   hay so sánh kỳ trước (không có timeseries nào lưu sẵn cho việc này).
 * - "Variance by Product Category" của mockup cần join stock_count_details ->
 *   products -> categories rồi so sánh discrepancy theo từng category - vượt
 *   phạm vi 2 file frontend (sẽ cần thêm 1 method Service khác); bỏ qua panel
 *   này để không bịa dữ liệu, thay bằng "Sessions by Staff" (thống kê thật có
 *   sẵn ngay trong getInventoryCountHistory()).
 *
 * Style/layout đồng bộ frontend/admin/dashboard.php và accounts.php (header/
 * sidebar/footer component + Bootstrap 5 + kpi-card/panel-card/data-table/
 * stock-pill dùng chung).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/AdminService.php';

// BR-19 / NFR-03: chỉ Admin được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$allSessions = $adminService->getInventoryCountHistory();

$keyword       = trim((string) ($_GET['q'] ?? ''));
$filterVariance = $_GET['variance'] ?? '';
$filterVariance = in_array($filterVariance, ['clean', 'minor', 'review'], true) ? $filterVariance : null;

/** Mức chênh lệch (%) của 1 phiên = discrepancy_items / total_items * 100. */
function sessionVariancePercent(array $session): float
{
    $total = (int) $session['total_items'];
    if ($total <= 0) {
        return 0.0;
    }
    return round(((int) $session['discrepancy_items'] / $total) * 100, 1);
}

/** Phân loại mức độ chênh lệch - ngưỡng đơn giản, không bịa số liệu, chỉ để nhóm trực quan. */
function varianceLevel(float $percent): array
{
    if ($percent <= 0) {
        return ['key' => 'clean', 'label' => 'Clean', 'pill' => 'stock-pill-success'];
    }
    if ($percent <= 5) {
        return ['key' => 'minor', 'label' => 'Minor Variance', 'pill' => 'stock-pill-warn'];
    }
    return ['key' => 'review', 'label' => 'Needs Review', 'pill' => 'stock-pill-critical'];
}

$sessions = array_map(function ($s) {
    $s['variance_percent'] = sessionVariancePercent($s);
    $s['variance_level']   = varianceLevel($s['variance_percent']);
    return $s;
}, $allSessions);

if ($keyword !== '') {
    $sessions = array_values(array_filter($sessions, function ($s) use ($keyword) {
        return stripos((string) $s['count_id'], $keyword) !== false
            || stripos($s['performed_by_name'], $keyword) !== false;
    }));
}
if ($filterVariance !== null) {
    $sessions = array_values(array_filter($sessions, fn($s) => $s['variance_level']['key'] === $filterVariance));
}

// KPI tổng hợp - tính từ TOÀN BỘ lịch sử (không phụ thuộc filter đang áp lên bảng)
$totalSessions       = count($allSessions);
$totalItemsAll       = array_sum(array_column($allSessions, 'total_items'));
$totalDiscrepancies  = array_sum(array_column($allSessions, 'discrepancy_items'));
$inventoryAccuracy   = $totalItemsAll > 0 ? round((1 - ($totalDiscrepancies / $totalItemsAll)) * 100, 1) : 100.0;
$needsReviewCount    = count(array_filter($allSessions, fn($s) => sessionVariancePercent($s) > 5));
$lastSession         = $allSessions[0] ?? null; // getHistory() đã ORDER BY count_date DESC

// Sessions by Staff - thống kê thật, thay cho "Variance by Product Category" (xem ghi chú đầu file)
$sessionsByStaff = [];
foreach ($allSessions as $s) {
    $name = $s['performed_by_name'];
    if (!isset($sessionsByStaff[$name])) {
        $sessionsByStaff[$name] = ['sessions' => 0, 'discrepancies' => 0];
    }
    $sessionsByStaff[$name]['sessions']++;
    $sessionsByStaff[$name]['discrepancies'] += (int) $s['discrepancy_items'];
}
arsort($sessionsByStaff);
$sessionsByStaff = array_slice($sessionsByStaff, 0, 5, true);

// Chi tiết phiên đang xem (?view=<id>) - modal gọi bằng fetch() kèm header
// X-Requested-With, trả JSON thẳng từ chính trang này (không cần route API
// riêng) rồi dừng luôn, không render tiếp phần HTML bên dưới.
$viewingCountId = isset($_GET['view']) ? (int) $_GET['view'] : null;
if ($viewingCountId !== null && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    $session = $adminService->getInventoryCountDetail($viewingCountId);
    header('Content-Type: application/json; charset=utf-8');
    if ($session === false) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
    } else {
        echo json_encode(['success' => true, 'session' => $session]);
    }
    exit;
}

/** Format datetime DB thành "DD/MM/YYYY HH:MM", đồng bộ style accounts.php. */
function formatCountDateTime(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('d/m/Y H:i', $ts);
}

$pageTitle   = 'Inventory Count History';
$breadcrumbs = ['Admin', 'Inventory', 'Count History'];
$activeMenu  = 'inventory_count_history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Count History - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h2 class="page-heading mb-1">Inventory Count History</h2>
                        <p class="page-subheading mb-0">Store-wide stock-count sessions and discrepancies (FR-ADM-09, BR-14).</p>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-4" style="border-bottom: 2px solid var(--surface-border);">
                    <li class="nav-item">
                        <a class="nav-link" href="inventory_overview.php">Overview</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active fw-semibold" href="inventory_count_history.php">Count History</a>
                    </li>
                </ul>

                <!-- KPI cards - tính từ dữ liệu thật -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Total Sessions</span></div>
                            <span class="kpi-value"><?= number_format($totalSessions) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Inventory Accuracy</span></div>
                            <span class="kpi-value" style="color: var(--color-success);"><?= number_format($inventoryAccuracy, 1) ?>%</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-top"><span class="kpi-label">Total Discrepancies</span></div>
                            <span class="kpi-value"><?= number_format($totalDiscrepancies) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card <?= $needsReviewCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top"><span class="kpi-label">Needs Review</span></div>
                            <span class="kpi-value" style="color: var(--color-danger);"><?= number_format($needsReviewCount) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Filter + search -->
                <form method="get" class="panel-card mb-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md-6">
                            <input type="text" name="q" class="form-control" placeholder="Search Session ID or staff name..." value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <select name="variance" class="form-select" onchange="this.form.submit()">
                                <option value="">All variance levels</option>
                                <option value="clean" <?= $filterVariance === 'clean' ? 'selected' : '' ?>>Clean</option>
                                <option value="minor" <?= $filterVariance === 'minor' ? 'selected' : '' ?>>Minor Variance</option>
                                <option value="review" <?= $filterVariance === 'review' ? 'selected' : '' ?>>Needs Review</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-brand flex-fill">Search</button>
                            <a href="inventory_count_history.php" class="btn btn-outline-secondary" title="Clear filters">&#8635;</a>
                        </div>
                    </div>
                </form>

                <div class="row g-4">
                    <div class="col-12 col-xl-8">
                        <!-- Bảng lịch sử kiểm kê -->
                        <div class="panel-card mb-4">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Count Sessions</h3>
                                <span class="panel-card-note"><?= count($sessions) ?> session<?= count($sessions) === 1 ? '' : 's' ?></span>
                            </div>

                            <?php if (empty($sessions)): ?>
                                <div class="empty-state">No count sessions match the current filters.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Session</th>
                                                <th>Count Date</th>
                                                <th>Performed By</th>
                                                <th>Items</th>
                                                <th>Variance</th>
                                                <th>Level</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sessions as $s): ?>
                                                <tr>
                                                    <td class="fw-semibold">CNT-<?= str_pad((string) $s['count_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                                    <td class="text-muted small"><?= formatCountDateTime($s['count_date']) ?></td>
                                                    <td><?= htmlspecialchars($s['performed_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= number_format((int) $s['total_items']) ?></td>
                                                    <td><?= number_format($s['variance_percent'], 1) ?>%</td>
                                                    <td><span class="stock-pill <?= $s['variance_level']['pill'] ?>"><?= $s['variance_level']['label'] ?></span></td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                                data-bs-toggle="modal" data-bs-target="#sessionDetailModal"
                                                                data-count-id="<?= (int) $s['count_id'] ?>">
                                                            View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4">
                        <!-- Sessions by Staff - thay cho "Variance by Product Category" (xem ghi chú đầu file) -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title">Sessions by Staff</h3>
                            </div>
                            <?php if (empty($sessionsByStaff)): ?>
                                <div class="empty-state">No sessions recorded yet.</div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($sessionsByStaff as $name => $stat): ?>
                                        <div>
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span class="fw-semibold"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="text-muted"><?= $stat['sessions'] ?> session<?= $stat['sessions'] === 1 ? '' : 's' ?></span>
                                            </div>
                                            <div class="text-muted small"><?= $stat['discrepancies'] ?> discrepant line<?= $stat['discrepancies'] === 1 ? '' : 's' ?> total</div>
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

    <!-- ===================== MODAL: Session Detail ===================== -->
    <div class="modal fade" id="sessionDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Count Session Detail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="sessionDetailLoading" class="text-muted small">Loading...</div>
                    <div id="sessionDetailContent" class="d-none">
                        <div class="row g-2 mb-3" style="font-size: .87rem;">
                            <div class="col-4">
                                <div class="text-muted small">Session</div>
                                <div class="fw-semibold" id="sdSessionId"></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">Performed by</div>
                                <div class="fw-semibold" id="sdPerformedBy"></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">Count date</div>
                                <div class="fw-semibold" id="sdCountDate"></div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0" style="font-size: .85rem;">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th class="text-end">System Qty</th>
                                        <th class="text-end">Actual Qty</th>
                                        <th class="text-end">Discrepancy</th>
                                    </tr>
                                </thead>
                                <tbody id="sdItemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Không lặp modal cho từng dòng (đã học từ pattern accounts.php) - nhưng
        // chi tiết phiên kiểm kê CÓ danh sách items (N dòng/phiên, N phiên) nên
        // load qua GET ?view=<id> bằng fetch() thay vì render sẵn N modal trong
        // DOM. Trang tự nó (không cần API riêng) trả JSON khi gọi kèm header
        // X-Requested-With, nhờ khối PHP phía dưới.
        const sessionModal = document.getElementById('sessionDetailModal');
        sessionModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;
            const countId = btn.getAttribute('data-count-id');

            document.getElementById('sessionDetailLoading').classList.remove('d-none');
            document.getElementById('sessionDetailContent').classList.add('d-none');

            fetch('inventory_count_history.php?view=' + encodeURIComponent(countId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('sessionDetailLoading').textContent = data.message || 'Could not load session detail.';
                        return;
                    }
                    const s = data.session;
                    document.getElementById('sdSessionId').textContent = 'CNT-' + String(s.count_id).padStart(4, '0');
                    document.getElementById('sdPerformedBy').textContent = s.performed_by_name || ('#' + s.performed_by);
                    document.getElementById('sdCountDate').textContent = s.count_date;

                    const tbody = document.getElementById('sdItemsBody');
                    tbody.innerHTML = '';
                    (s.items || []).forEach(item => {
                        const tr = document.createElement('tr');
                        const discrepancy = parseInt(item.discrepancy, 10);
                        const discClass = discrepancy === 0 ? 'text-muted' : (discrepancy < 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold');
                        tr.innerHTML = `
                            <td>${item.sku_code}</td>
                            <td>${item.product_name}</td>
                            <td class="text-end">${item.system_qty}</td>
                            <td class="text-end">${item.actual_qty}</td>
                            <td class="text-end ${discClass}">${discrepancy > 0 ? '+' : ''}${discrepancy}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    document.getElementById('sessionDetailLoading').classList.add('d-none');
                    document.getElementById('sessionDetailContent').classList.remove('d-none');
                })
                .catch(() => {
                    document.getElementById('sessionDetailLoading').textContent = 'Could not load session detail.';
                });
        });
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>