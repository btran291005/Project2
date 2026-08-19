<?php
/**
 * File: frontend/admin/audit_log.php
 * Purpose: UI for viewing system-wide audit log, filterable by user/action/date.
 * Related: FR-ADM-07
 * Calls: AdminService::getAuditLogs(), ::listAccounts()
 *
 * Layout tham khảo 1 mockup "System Audit Log" (search bar + filter row +
 * table + pagination + summary cards), NHƯNG đã bỏ/thay các phần mockup vẽ
 * ra mà hệ thống thật không có dữ liệu để lấp đầy trung thực:
 *   - Mockup có avatar ảnh, before->after value inline (VD: "150 -> 220
 *     units", "Manual -> AI Predictive"): audit_logs chỉ lưu action_type/
 *     target_table/target_id, KHÔNG lưu old_value/new_value - hiển thị giả
 *     những con số không tồn tại là sai lệch thông tin, nên bỏ.
 *   - Mockup có actor ảo "System AI Engine"/"System Daemon": audit_logs.
 *     account_id là NOT NULL FK -> accounts, không có khái niệm actor hệ
 *     thống không gắn với tài khoản thật nào.
 *   - Mockup có "Security Flags"/"AI Interventions"/"Storage Usage" cards:
 *     không có nguồn dữ liệu nào trong DB cho 3 chỉ số này - bỏ, chỉ giữ lại
 *     "Total Logs" (đếm được thật từ kết quả filter) làm summary card.
 *   - "Module" column: suy ra từ target_table (vd: 'products' -> INVENTORY)
 *     qua map cố định trong code, vì audit_logs không có cột module riêng.
 *
 * Read-only, filter qua GET (không có side-effect, URL share lại được).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/AdminService.php';

Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();

// --- Filter (GET, đọc-only) ---
$filters = [];

$filterAccountId = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int) $_GET['account_id'] : null;
if ($filterAccountId !== null) {
    $filters['account_id'] = $filterAccountId;
}

$filterActionType = isset($_GET['action_type']) ? trim((string) $_GET['action_type']) : '';
if ($filterActionType !== '') {
    $filters['action_type'] = $filterActionType;
}

// "Last 24 hours / 7 days / 30 days / All time" - khoảng thời gian nhanh,
// thay cho 2 ô date riêng lẻ để giống đúng kiểu dropdown 1-chạm của mockup.
$filterRange = $_GET['range'] ?? '24h';
$rangeToFromDate = [
    '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    '7d'  => date('Y-m-d H:i:s', strtotime('-7 days')),
    '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
    'all' => null,
];
if (!array_key_exists($filterRange, $rangeToFromDate)) {
    $filterRange = '24h';
}
if ($rangeToFromDate[$filterRange] !== null) {
    $filters['from_date'] = $rangeToFromDate[$filterRange];
}

$searchKeyword = trim((string) ($_GET['q'] ?? ''));

$allLogs = $adminService->getAuditLogs($filters);

// Search theo keyword (account_name/action_type/target_table) - lọc phía
// PHP sau khi đã lọc DB, vì đây chỉ là tìm nhanh trong tập kết quả đã đủ nhỏ
// (không cần thêm 1 tham số filter riêng ở AdminService cho việc này).
if ($searchKeyword !== '') {
    $needle = mb_strtolower($searchKeyword);
    $allLogs = array_values(array_filter($allLogs, function (array $log) use ($needle) {
        return str_contains(mb_strtolower($log['account_name']), $needle)
            || str_contains(mb_strtolower($log['action_type']), $needle)
            || str_contains(mb_strtolower((string) ($log['target_table'] ?? '')), $needle);
    }));
}

// Pagination phía PHP (đơn giản, đủ dùng vì getAuditLogs() không hỗ trợ LIMIT/OFFSET) -
// nếu số dòng log tăng lớn về sau, nên chuyển phần LIMIT xuống tầng SQL trong AdminService.
$totalLogs   = count($allLogs);
$perPage     = 25;
$totalPages  = max(1, (int) ceil($totalLogs / $perPage));
$currentPage = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$pagedLogs   = array_slice($allLogs, ($currentPage - 1) * $perPage, $perPage);

$accounts = $adminService->listAccounts();

/** Suy ra "Module" hiển thị từ target_table - audit_logs không có cột module riêng. */
function auditModuleLabel(?string $targetTable): string
{
    if ($targetTable === null) {
        return 'SYSTEM';
    }
    $map = [
        'products'               => 'INVENTORY',
        'stock'                  => 'INVENTORY',
        'stock_movements'        => 'INVENTORY',
        'reorder_rules'          => 'RULES',
        'purchase_orders'        => 'ORDERS',
        'purchase_order_details' => 'ORDERS',
        'accounts'               => 'SECURITY',
        'role_permissions'       => 'SECURITY',
        'suppliers'              => 'SUPPLIERS',
        'warehouses'             => 'WAREHOUSES',
        'api_configs'            => 'SETTINGS',
    ];
    return $map[$targetTable] ?? strtoupper($targetTable);
}

/** Nhãn hành động dễ đọc - tái dùng đúng bảng nhãn đã có ở dashboard.php để nhất quán toàn hệ thống. */
function formatActionLabel(string $actionType): string
{
    $map = [
        'LOGIN'                   => 'Logged in',
        'LOGOUT'                  => 'Logged out',
        'LOGIN_ROLE_MISMATCH'     => 'Selected wrong role',
        'CREATE_ACCOUNT'          => 'Created account',
        'UPDATE_ACCOUNT'          => 'Updated account',
        'LOCK_ACCOUNT'            => 'Locked account',
        'UNLOCK_ACCOUNT'          => 'Unlocked account',
        'RESET_PASSWORD'          => 'Reset password',
        'CREATE_PRODUCT'          => 'Created product',
        'UPDATE_PRODUCT'          => 'Updated product',
        'DEACTIVATE_PRODUCT'      => 'Deactivated product',
        'ACTIVATE_PRODUCT'        => 'Activated product',
        'CREATE_SUPPLIER'         => 'Created supplier',
        'UPDATE_SUPPLIER'         => 'Updated supplier',
        'DELETE_SUPPLIER'         => 'Deleted supplier',
        'CREATE_WAREHOUSE'        => 'Created warehouse',
        'UPDATE_WAREHOUSE'        => 'Updated warehouse',
        'DELETE_WAREHOUSE'        => 'Deleted warehouse',
        'UPDATE_ROLE_PERMISSIONS' => 'Updated role permissions',
        'UPDATE_REORDER_RULE'     => 'Updated reorder rule',
        'UPDATE_API_CONFIG'       => 'Updated API config',
        'APPROVE_PO'              => 'Approved purchase order',
        'REJECT_PO'               => 'Rejected purchase order',
        'OVERRIDE_PO_QTY'         => 'Overrode PO quantity',
    ];
    return $map[$actionType] ?? ucwords(strtolower(str_replace('_', ' ', $actionType)));
}

/** Màu severity theo action - cùng quy tắc đã dùng ở dashboard.php cho Alerts panel. */
function auditSeverity(string $actionType): string
{
    if (str_starts_with($actionType, 'REJECT') || str_starts_with($actionType, 'LOCK') || str_starts_with($actionType, 'DELETE') || $actionType === 'LOGIN_ROLE_MISMATCH') {
        return 'danger';
    }
    if (str_starts_with($actionType, 'APPROVE') || str_starts_with($actionType, 'UPDATE') || str_starts_with($actionType, 'RESET') || str_starts_with($actionType, 'OVERRIDE')) {
        return 'warning';
    }
    if (str_starts_with($actionType, 'CREATE') || str_starts_with($actionType, 'UNLOCK')) {
        return 'success';
    }
    return 'info';
}

$hasActiveFilter = $filterAccountId !== null || $filterActionType !== '' || $searchKeyword !== '' || $filterRange !== '24h';

$pageTitle   = 'Audit Log';
$breadcrumbs = ['Admin', 'Audit Log'];
$activeMenu  = 'audit_log';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - InventoryDSS</title>
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
                        <h2 class="page-heading mb-1">
                            System Audit Log
                            <span class="badge-count" title="Total logs currently filtered"><?= number_format($totalLogs) ?></span>
                        </h2>
                        <p class="page-subheading mb-0">Log of all sensitive actions across the system (FR-SYS-03).</p>
                    </div>
                </div>

                <!-- Filter bar -->
                <div class="panel-card panel-card-compact mb-3">
                    <form method="get" class="filter-bar">
                        <div class="filter-bar-search">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search: user, action, table..."
                                   aria-label="Search" value="<?= htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <select name="range" class="form-select form-select-sm" aria-label="Time range" onchange="this.form.submit()">
                                <option value="24h" <?= $filterRange === '24h' ? 'selected' : '' ?>>Last 24 hours</option>
                                <option value="7d" <?= $filterRange === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                                <option value="30d" <?= $filterRange === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                                <option value="all" <?= $filterRange === 'all' ? 'selected' : '' ?>>All time</option>
                            </select>
                        </div>
                        <div>
                            <select name="account_id" class="form-select form-select-sm" aria-label="Performed by" onchange="this.form.submit()">
                                <option value="">All users</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= (int) $acc['account_id'] ?>" <?= $filterAccountId === (int) $acc['account_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($acc['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="text" name="action_type" class="form-control form-control-sm" placeholder="Action: APPROVE, CREATE..."
                                   aria-label="Action type" value="<?= htmlspecialchars($filterActionType, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="filter-bar-actions d-flex gap-2">
                            <button type="submit" class="btn btn-brand btn-sm">Filter</button>
                            <?php if ($hasActiveFilter): ?>
                                <a href="audit_log.php" class="btn btn-outline-secondary btn-sm">Clear filters</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Danh sách log -->
                <div class="panel-card">
                    <?php if (empty($pagedLogs)): ?>
                        <div class="empty-state">No logs match the current filters.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-borderless align-middle mb-0 data-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Performed By</th>
                                        <th>Module</th>
                                        <th>Action</th>
                                        <th>Target</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pagedLogs as $log): ?>
                                        <?php $severity = auditSeverity($log['action_type']); ?>
                                        <tr>
                                            <td class="text-muted text-nowrap"><?= htmlspecialchars(date('H:i:s d/m/Y', strtotime((string) $log['timestamp'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($log['account_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="status-badge status-badge-muted"><?= htmlspecialchars(auditModuleLabel($log['target_table']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td>
                                                <span class="status-badge status-badge-<?= $severity ?>">
                                                    <?= htmlspecialchars(formatActionLabel($log['action_type']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="text-muted">
                                                <?php if (!empty($log['target_table'])): ?>
                                                    <?= htmlspecialchars($log['target_table'], ENT_QUOTES, 'UTF-8') ?><?= $log['target_id'] !== null ? ' #' . (int) $log['target_id'] : '' ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="d-flex justify-content-between align-items-center mt-3 px-1">
                            <span class="text-muted small">
                                Showing <?= ($currentPage - 1) * $perPage + 1 ?>–<?= min($currentPage * $perPage, $totalLogs) ?> of <?= number_format($totalLogs) ?> logs
                            </span>
                            <?php if ($totalPages > 1): ?>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                            $baseParams = $_GET;
                                            $buildUrl = fn(int $p) => '?' . http_build_query(array_merge($baseParams, ['page' => $p]));
                                        ?>
                                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $currentPage > 1 ? htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') : '#' ?>">‹</a>
                                        </li>
                                        <?php
                                            $startPage = max(1, $currentPage - 2);
                                            $endPage   = min($totalPages, $currentPage + 2);
                                        ?>
                                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                            <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars($buildUrl($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $currentPage < $totalPages ? htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') : '#' ?>">›</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../components/footer.php'; ?>