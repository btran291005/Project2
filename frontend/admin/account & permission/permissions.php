<?php
/**
 * File: frontend/admin/account & permission/permissions.php
 * Purpose: UI quản lý Role & Permission - xem 3 role card (số user + số
 * permission thật theo role_permissions) và ma trận Permission Matrix
 * (checkbox permission x role, sync qua AdminService::syncRolePermissions()).
 * Related: FR-ADM-03, BR-17
 * Calls: AdminService::listRoles(), listPermissions(), getPermissionsForRole(),
 *        syncRolePermissions(), countPermissionsByRole(), listAccounts(),
 *        getAuditLogs()
 *
 * LƯU Ý DỮ LIỆU (đối chiếu với mockup tham khảo - KHÔNG bịa các phần sau
 * vì schema thật không có):
 *   - Bảng `permissions` chỉ có permission_code (VD: 'FR-ADM-01') + description
 *     tự do, KHÔNG có cột "module" phân loại theo nhóm chức năng (Dashboard,
 *     User Management, Inventory Tracking...) như mockup. Ma trận dưới đây
 *     liệt kê ĐÚNG permission_code + description thật, không nhóm giả theo
 *     module tưởng tượng.
 *   - KHÔNG có "RBAC Insight" (AI confidence %, gợi ý tự động) - hệ thống
 *     không có engine phân tích quyền hạn nào, không bịa số % tin cậy.
 *   - KHÔNG có "Security Health" (MFA Adoption %, Password Strength) - bảng
 *     accounts không lưu MFA status hay độ mạnh password ở dạng đọc được
 *     (chỉ có password_hash một chiều).
 *   - KHÔNG có "Reset Defaults" - không có khái niệm baseline "default
 *     permissions" nào được định nghĩa trong DB/service để reset về.
 *   - "RBAC Activity" DÙNG DATA THẬT từ audit_logs (filter action_type LIKE
 *     '%ROLE%' hoặc '%PERMISSION%'), không bịa tên người/hành động.
 *
 * Style/layout đồng bộ accounts.php (cùng thư mục) - 2 file là 2 tab của
 * cùng 1 trang "User & Permission Management" theo đúng kiến trúc thật của
 * repo (điều hướng bằng link thật, không tab client-side giả).
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
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ POST: save_matrix (ma trận permission x role, submit 1 lần cho cả 3 role)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_matrix') {
        // Checkbox gửi lên dạng permissions[role_id][] = permission_id (chỉ có
        // key khi checkbox được tick - HTML checkbox không gửi gì nếu bỏ tick,
        // nên role nào không có key nghĩa là "bỏ tick hết", không phải "không đổi").
        $submittedRoleIds = array_map('intval', explode(',', (string) ($_POST['role_ids'] ?? '')));
        $permissionsByRole = $_POST['permissions'] ?? [];

        $allOk = true;
        foreach ($submittedRoleIds as $roleId) {
            $permissionIds = array_map('intval', $permissionsByRole[(string) $roleId] ?? []);
            $result = $adminService->syncRolePermissions($roleId, $permissionIds, $actorId);
            $allOk = $allOk && $result['success'];
        }

        $flashMessage = $allOk ? 'Permission configuration saved for all roles.' : 'An error occurred while saving one or more roles.';
        $flashIsError = !$allOk;
    }
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$roles       = $adminService->listRoles();       // [['role_id'=>1,'role_name'=>'Admin'], ...]
$permissions = $adminService->listPermissions();  // [['permission_id'=>1,'permission_code'=>'FR-ADM-01','description'=>...], ...]

// Số user theo role - giống hệt logic ở accounts.php, để 3 card ở đây khớp
// đúng số liệu với tab Users (không tính lại theo cách khác gây lệch số).
$allAccounts = $adminService->listAccounts();
$usersByRole = [];
foreach ($allAccounts as $a) {
    $usersByRole[(int) $a['role_id']] = ($usersByRole[(int) $a['role_id']] ?? 0) + 1;
}
$permissionCountsByRole = $adminService->countPermissionsByRole();

// Ma trận: với mỗi role, tập hợp permission_id đang được gán (dùng để pre-check
// checkbox đúng trạng thái hiện tại trong DB khi render form).
$assignedByRole = [];
foreach ($roles as $role) {
    $rid = (int) $role['role_id'];
    $assignedByRole[$rid] = array_map(
        static fn(array $p): int => (int) $p['permission_id'],
        $adminService->getPermissionsForRole($rid)
    );
}

// RBAC Activity - audit log THẬT liên quan tới role/permission, không bịa tên
// người dùng như mockup. Logger::log() ghi action_type 'UPDATE_ROLE_PERMISSIONS'
// khi syncRolePermissions() thay đổi gì đó (xem AdminService.php).
$rbacActivity = array_slice(
    array_filter(
        $adminService->getAuditLogs(),
        static fn(array $log): bool => str_contains($log['action_type'], 'ROLE') || str_contains($log['action_type'], 'PERMISSION')
    ),
    0,
    6
);

/** Badge màu theo role - Admin nổi bật nhất (accent), Manager info, Staff trung tính. Khớp accounts.php. */
function accountRolePillClass(int $roleId): string
{
    return match ($roleId) {
        ROLE_ADMIN   => 'stock-pill-accent',
        ROLE_MANAGER => 'stock-pill-info-role',
        default      => 'stock-pill-muted',
    };
}

$pageTitle   = 'Roles & Permissions';
$breadcrumbs = ['Admin', 'Users & Roles', 'Permission Matrix'];
$activeMenu  = 'permissions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles &amp; Permissions - InventoryDSS</title>
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

                <!-- Page intro -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">User &amp; Permission Management</h2>
                        <p class="page-subheading mb-0">Configure role-based access control policy (FR-ADM-03, BR-17).</p>
                    </div>
                    <a href="accounts.php" class="btn btn-brand d-inline-flex align-items-center gap-2">+ Create User</a>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3 mb-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <!-- Tabs: Users (accounts.php) / Roles & Permission Matrix (trang hiện tại) -->
                <ul class="nav nav-tabs mb-4" style="border-bottom: 2px solid var(--surface-border);">
                    <li class="nav-item">
                        <a class="nav-link" href="accounts.php">Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active fw-semibold" href="permissions.php">Roles &amp; Permission Matrix</a>
                    </li>
                </ul>

                <div class="row g-4">
                    <div class="col-lg-8">

                        <!-- Role summary cards - số user + số permission THẬT, không hardcode -->
                        <div class="row g-3 mb-4">
                            <?php foreach ($roles as $role): ?>
                                <?php
                                    $rid = (int) $role['role_id'];
                                    $userCount = $usersByRole[$rid] ?? 0;
                                    $permCount = $permissionCountsByRole[$rid] ?? 0;
                                ?>
                                <div class="col-12 col-md-4">
                                    <div class="panel-card h-100">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h3 class="panel-card-title mb-0"><?= htmlspecialchars($role['role_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="stock-pill <?= accountRolePillClass($rid) ?>"><?= $userCount ?> Users</span>
                                        </div>
                                        <div class="text-muted small mb-3"><?= $permCount ?> permission<?= $permCount === 1 ? '' : 's' ?> assigned</div>
                                        <a href="accounts.php?role_id=<?= $rid ?>" class="panel-card-link">View users &rarr;</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Permission Matrix -->
                        <div class="panel-card">
                            <form method="POST" id="matrixForm">
                                <input type="hidden" name="action" value="save_matrix">
                                <input type="hidden" name="role_ids" value="<?= htmlspecialchars(implode(',', array_map(static fn($r) => (int) $r['role_id'], $roles)), ENT_QUOTES, 'UTF-8') ?>">

                                <div class="panel-card-header">
                                    <div>
                                        <h3 class="panel-card-title mb-0">Permission Matrix</h3>
                                        <p class="text-muted small mb-0 mt-1">Configure cross-module access control policy.</p>
                                    </div>
                                    <button type="submit" class="btn btn-brand btn-sm">Save Configuration</button>
                                </div>

                                <?php if (empty($permissions)): ?>
                                    <div class="empty-state">No permissions defined in the system yet.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table data-table align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Permission</th>
                                                    <?php foreach ($roles as $role): ?>
                                                        <th class="text-center"><?= htmlspecialchars(strtoupper($role['role_name']), ENT_QUOTES, 'UTF-8') ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($permissions as $perm): ?>
                                                    <?php $pid = (int) $perm['permission_id']; ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-semibold"><?= htmlspecialchars($perm['description'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <div class="text-muted small"><?= htmlspecialchars($perm['permission_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        </td>
                                                        <?php foreach ($roles as $role): ?>
                                                            <?php
                                                                $rid = (int) $role['role_id'];
                                                                $isChecked = in_array($pid, $assignedByRole[$rid] ?? [], true);
                                                            ?>
                                                            <td class="text-center">
                                                                <input type="checkbox"
                                                                       class="form-check-input"
                                                                       name="permissions[<?= $rid ?>][]"
                                                                       value="<?= $pid ?>"
                                                                       <?= $isChecked ? 'checked' : '' ?>
                                                                       aria-label="<?= htmlspecialchars($role['role_name'] . ' - ' . $perm['permission_code'], ENT_QUOTES, 'UTF-8') ?>">
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>

                    </div>

                    <div class="col-lg-4">
                        <!-- RBAC Activity - audit log THẬT, không bịa tên người dùng -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title mb-0">RBAC Activity</h3>
                            </div>

                            <?php if (empty($rbacActivity)): ?>
                                <div class="empty-state">No role/permission changes recorded yet.</div>
                            <?php else: ?>
                                <div class="activity-list">
                                    <?php foreach ($rbacActivity as $log): ?>
                                        <div class="activity-item">
                                            <div class="activity-item-main">
                                                <?= htmlspecialchars($log['account_name'], ENT_QUOTES, 'UTF-8') ?>
                                                &mdash;
                                                <?= htmlspecialchars($log['action_type'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ($log['target_table']): ?>
                                                    <span class="text-muted">(<?= htmlspecialchars($log['target_table'], ENT_QUOTES, 'UTF-8') ?><?= $log['target_id'] ? ' #' . (int) $log['target_id'] : '' ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-item-meta">
                                                <span class="activity-item-time"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($log['timestamp'])), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <a href="../audit_log.php" class="panel-card-link d-inline-block mt-2">View All Audit Logs &rarr;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>