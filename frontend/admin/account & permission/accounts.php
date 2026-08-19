<?php
/**
 * File: frontend/admin/accounts.php
 * Purpose: UI quản lý tài khoản người dùng - tạo/sửa/khoá-mở khoá/đặt lại mật
 * khẩu (FR-ADM-02, BR-17). Đây là bản đầy đủ thay thế stub Phase 2-3.
 * Related: FR-ADM-02, FR-SYS-01, BR-17, BR-19, NFR-03
 * Calls: AdminService::listAccounts(), getAccountDetail(), createAccount(),
 *        updateAccount(), resetPassword(), lockAccount(), unlockAccount(),
 *        listRoles(), countPermissionsByRole()
 *
 * LƯU Ý DỮ LIỆU: bảng accounts THẬT chỉ có username, password_hash, full_name,
 * email (nullable), phone_number (nullable), role_id, status, created_at.
 * KHÔNG có avatar, store, position, emp_id, MFA, password-strength %,
 * failed-attempt counter... nên các phần này KHÔNG xuất hiện trên trang -
 * không bịa field để giống mockup tham khảo.
 *
 * Style/layout đồng bộ frontend/admin/dashboard.php (header/sidebar/footer
 * component + Bootstrap 5 + panel-card/data-table/stock-pill dùng chung).
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
// XỬ LÝ POST: create_account / update_account / reset_password / lock / unlock
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account') {
        $result = $adminService->createAccount([
            'username'     => $_POST['username'] ?? '',
            'password'     => $_POST['password'] ?? '',
            'full_name'    => $_POST['full_name'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'phone_number' => $_POST['phone_number'] ?? '',
            'role_id'      => $_POST['role_id'] ?? 0,
        ], $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'update_account') {
        $result = $adminService->updateAccount((int) ($_POST['account_id'] ?? 0), [
            'full_name'    => $_POST['full_name'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'phone_number' => $_POST['phone_number'] ?? '',
            'role_id'      => $_POST['role_id'] ?? 0,
        ], $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'reset_password') {
        $result = $adminService->resetPassword(
            (int) ($_POST['account_id'] ?? 0),
            (string) ($_POST['new_password'] ?? ''),
            $actorId
        );
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'lock_account') {
        $result = $adminService->lockAccount((int) ($_POST['account_id'] ?? 0), $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'unlock_account') {
        $result = $adminService->unlockAccount((int) ($_POST['account_id'] ?? 0), $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'delete_account') {
        $result = $adminService->deleteAccount((int) ($_POST['account_id'] ?? 0), $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    }
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$roles = $adminService->listRoles(); // [['role_id'=>1,'role_name'=>'Admin'], ...]

$filterRoleId = isset($_GET['role_id']) && $_GET['role_id'] !== '' ? (int) $_GET['role_id'] : null;
$filterStatus = $_GET['status'] ?? '';
$filterStatus = in_array($filterStatus, ['active', 'locked'], true) ? $filterStatus : null;
$keyword      = trim((string) ($_GET['q'] ?? ''));

$accounts = $adminService->listAccounts($filterRoleId, $filterStatus, $keyword !== '' ? $keyword : null);

// Summary card: tính TRỰC TIẾP từ danh sách KHÔNG lọc (để card luôn phản ánh
// tổng toàn hệ thống, độc lập với filter đang áp dụng lên bảng bên dưới).
$allAccountsUnfiltered = $filterRoleId === null && $filterStatus === null && $keyword === ''
    ? $accounts
    : $adminService->listAccounts();

$totalUsers  = count($allAccountsUnfiltered);
$activeCount = count(array_filter($allAccountsUnfiltered, fn($a) => $a['status'] === 'active'));
$lockedCount = count(array_filter($allAccountsUnfiltered, fn($a) => $a['status'] === 'locked'));

// Số user theo từng role - dùng cho 3 card "Administrator / Store Manager /
// Store Staff" bên dưới, và số permission THẬT (không bịa 18/11/7 như mockup).
$usersByRole = [];
foreach ($allAccountsUnfiltered as $a) {
    $usersByRole[(int) $a['role_id']] = ($usersByRole[(int) $a['role_id']] ?? 0) + 1;
}
$permissionCountsByRole = $adminService->countPermissionsByRole();

// Account đang xem chi tiết qua modal (?view=<id>) - dùng để pre-fill modal Edit
// bằng PHP thay vì đọc lại qua JS/data-attribute cho từng dòng bảng.
$viewingAccountId = isset($_GET['view']) ? (int) $_GET['view'] : null;
$viewingAccount   = $viewingAccountId ? $adminService->getAccountDetail($viewingAccountId) : false;

/** Badge màu theo status tài khoản - active=success (xanh), locked=danger (đỏ). */
function accountStatusPillClass(string $status): string
{
    return $status === 'locked' ? 'stock-pill-critical' : 'stock-pill-success';
}

/** Badge màu theo role - Admin nổi bật nhất (accent), Manager info, Staff trung tính. */
function accountRolePillClass(int $roleId): string
{
    return match ($roleId) {
        ROLE_ADMIN   => 'stock-pill-accent',
        ROLE_MANAGER => 'stock-pill-info-role',
        default      => 'stock-pill-muted',
    };
}

$pageTitle   = 'Accounts';
$breadcrumbs = ['Admin', 'Accounts'];
$activeMenu  = 'accounts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts - InventoryDSS</title>
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

                <!-- Page intro + quick action -->
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div>
                        <h2 class="page-heading mb-1">User &amp; Permission Management</h2>
                        <p class="page-subheading mb-0">Manage user accounts, roles, and system access (FR-ADM-02, BR-17).</p>
                    </div>
                    <button type="button" class="btn btn-brand d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#createAccountModal">
                        + Create User
                    </button>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3 mb-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <!-- Tabs: Users (trang hiện tại) / Permissions (trang riêng) -
                     mockup gộp 3 tab Users/Roles/Permission Matrix vào 1 trang,
                     nhưng kiến trúc thật của repo tách accounts.php (Users) và
                     permissions.php (Roles + Permission Matrix) - giữ nguyên
                     kiến trúc đó, chỉ nối 2 trang bằng tab-link thật (điều
                     hướng), không giả lập tab client-side. -->
                <ul class="nav nav-tabs mb-4" style="border-bottom: 2px solid var(--surface-border);">
                    <li class="nav-item">
                        <a class="nav-link active fw-semibold" href="accounts.php">Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="permissions.php">Roles &amp; Permission Matrix</a>
                    </li>
                </ul>

                <!-- Summary cards - số liệu tính TRỰC TIẾP từ accounts thật, không bịa AVG LOGIN/MFA -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Total Users</span>
                            </div>
                            <span class="kpi-value"><?= number_format($totalUsers) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Currently Active</span>
                            </div>
                            <span class="kpi-value" style="color: var(--color-success);"><?= number_format($activeCount) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-xl-4">
                        <div class="kpi-card <?= $lockedCount > 0 ? 'kpi-card-warn' : '' ?>">
                            <div class="kpi-card-top">
                                <span class="kpi-label">Locked Accounts</span>
                            </div>
                            <span class="kpi-value" style="color: var(--color-danger);"><?= number_format($lockedCount) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Filter + search -->
                <form method="get" class="panel-card mb-3">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md-5">
                            <input type="text" name="q" class="form-control" placeholder="Search by name, username, or email..." value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <select name="role_id" class="form-select" onchange="this.form.submit()">
                                <option value="">All roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= (int) $role['role_id'] ?>" <?= $filterRoleId === (int) $role['role_id'] ? 'selected' : '' ?>><?= htmlspecialchars($role['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">All status</option>
                                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="locked" <?= $filterStatus === 'locked' ? 'selected' : '' ?>>Locked</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-brand flex-fill">Search</button>
                            <a href="accounts.php" class="btn btn-outline-secondary" title="Clear filters">&#8635;</a>
                        </div>
                    </div>
                </form>

                <!-- Bảng danh sách user -->
                <div class="panel-card mb-4">
                    <div class="panel-card-header">
                        <h3 class="panel-card-title">All Users</h3>
                        <span class="panel-card-note"><?= count($accounts) ?> user<?= count($accounts) === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if (empty($accounts)): ?>
                        <div class="empty-state">No users match the current filters.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>User Info</th>
                                        <th>Contact</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accounts as $acc): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($acc['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small">@<?= htmlspecialchars($acc['username'], ENT_QUOTES, 'UTF-8') ?> &middot; GS-<?= str_pad((string) $acc['account_id'], 4, '0', STR_PAD_LEFT) ?></div>
                                            </td>
                                            <td>
                                                <div class="small"><?= $acc['email'] ? htmlspecialchars($acc['email'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">No email</span>' ?></div>
                                                <div class="small text-muted"><?= $acc['phone_number'] ? htmlspecialchars($acc['phone_number'], ENT_QUOTES, 'UTF-8') : 'No phone' ?></div>
                                            </td>
                                            <td><span class="stock-pill <?= accountRolePillClass((int) $acc['role_id']) ?>"><?= htmlspecialchars(strtoupper($acc['role_name']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><span class="stock-pill <?= accountStatusPillClass($acc['status']) ?>"><?= $acc['status'] === 'locked' ? 'LOCKED' : 'ACTIVE' ?></span></td>
                                            <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y', strtotime($acc['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                        data-bs-toggle="modal" data-bs-target="#userDetailModal"
                                                        data-account-id="<?= (int) $acc['account_id'] ?>"
                                                        data-full-name="<?= htmlspecialchars($acc['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-username="<?= htmlspecialchars($acc['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-email="<?= htmlspecialchars((string) $acc['email'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-phone="<?= htmlspecialchars((string) $acc['phone_number'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-role-id="<?= (int) $acc['role_id'] ?>"
                                                        data-role-name="<?= htmlspecialchars($acc['role_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-status="<?= htmlspecialchars($acc['status'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-created-at="<?= htmlspecialchars($acc['created_at'], ENT_QUOTES, 'UTF-8') ?>">
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

                <!-- Role summary cards - số user + số permission THẬT theo role_permissions,
                     không hardcode "18/11/7 Permissions" như mockup tham khảo -->
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
                                <a href="permissions.php?role_id=<?= $rid ?>" class="panel-card-link">Manage Policy &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </main>
        </div>
    </div>

    <!-- ===================== MODAL: Create User ===================== -->
    <div class="modal fade" id="createAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="create_account">
                <div class="modal-header">
                    <h5 class="modal-title">Create User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <div>
                        <label class="form-label small fw-semibold">Full name</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Email (optional)</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Phone (optional)</label>
                            <input type="text" name="phone_number" class="form-control">
                        </div>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Role</label>
                        <select name="role_id" class="form-select" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= (int) $role['role_id'] ?>"><?= htmlspecialchars($role['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <div class="form-text">At least 8 characters, incl. 1 uppercase, 1 lowercase, 1 number, 1 special character.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: User Details (view + quick actions) ===================== -->
    <div class="modal fade" id="userDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">User Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h4 id="udFullName" class="mb-0"></h4>
                    <div class="text-muted small mb-2" id="udUsernameId"></div>
                    <div class="d-flex gap-2 mb-3">
                        <span class="stock-pill" id="udRoleBadge"></span>
                        <span class="stock-pill" id="udStatusBadge"></span>
                    </div>

                    <div class="row g-2" style="font-size: .87rem;">
                        <div class="col-6">
                            <div class="text-muted small">Email</div>
                            <div class="fw-semibold" id="udEmail"></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Phone</div>
                            <div class="fw-semibold" id="udPhone"></div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted small">Joined</div>
                            <div class="fw-semibold" id="udCreatedAt"></div>
                        </div>
                    </div>

                    <hr>

                    <!-- Edit form - full_name/email/phone/role -->
                    <form method="POST" id="udEditForm" class="d-flex flex-column gap-2">
                        <input type="hidden" name="action" value="update_account">
                        <input type="hidden" name="account_id" id="udEditAccountId">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Full name</label>
                                <input type="text" name="full_name" id="udEditFullName" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Role</label>
                                <select name="role_id" id="udEditRoleId" class="form-select form-select-sm">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['role_id'] ?>"><?= htmlspecialchars($role['role_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Email</label>
                                <input type="email" name="email" id="udEditEmail" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Phone</label>
                                <input type="text" name="phone_number" id="udEditPhone" class="form-control form-control-sm">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-brand btn-sm align-self-start mt-1">Save changes</button>
                    </form>

                    <hr>

                    <!-- Reset password -->
                    <form method="POST" id="udResetForm" class="d-flex gap-2 align-items-end mb-2">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="account_id" id="udResetAccountId">
                        <div class="flex-fill">
                            <label class="form-label small fw-semibold">New password</label>
                            <input type="password" name="new_password" class="form-control form-control-sm" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Reset PW</button>
                    </form>

                    <!-- Lock/Unlock + Delete - đặt cạnh nhau như mockup (Lock/Delete cùng hàng) -->
                    <div class="row g-2">
                        <div class="col-6">
                            <form method="POST" id="udLockForm">
                                <input type="hidden" name="action" id="udLockAction" value="lock_account">
                                <input type="hidden" name="account_id" id="udLockAccountId">
                                <button type="submit" class="btn btn-sm w-100" id="udLockBtn"></button>
                            </form>
                        </div>
                        <div class="col-6">
                            <form method="POST" id="udDeleteForm" onsubmit="return confirm('Permanently delete this account? This action cannot be undone.');">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="account_id" id="udDeleteAccountId">
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100" id="udDeleteBtn">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div class="form-text mt-1">
                        Deleting only succeeds if this account has no related data (Purchase Orders, audit history, stock records...). Otherwise, use Lock instead.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const CURRENT_ACTOR_ID = <?= (int) $actorId ?>;

        // Đổ dữ liệu từ data-* attribute của nút "View" vào modal User Details -
        // tránh phải render 1 modal riêng cho mỗi dòng bảng (128 user = 128 modal
        // nếu làm theo cách PHP-loop, rất nặng DOM).
        document.getElementById('userDetailModal').addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            if (!btn) return;

            const fullName = btn.getAttribute('data-full-name');
            const username  = btn.getAttribute('data-username');
            const accountId = btn.getAttribute('data-account-id');
            const email     = btn.getAttribute('data-email') || '';
            const phone     = btn.getAttribute('data-phone') || '';
            const roleId    = btn.getAttribute('data-role-id');
            const roleName  = btn.getAttribute('data-role-name');
            const status    = btn.getAttribute('data-status');
            const createdAt = btn.getAttribute('data-created-at');

            document.getElementById('udFullName').textContent = fullName;
            document.getElementById('udUsernameId').textContent = '@' + username + ' \u00b7 GS-' + String(accountId).padStart(4, '0');
            document.getElementById('udEmail').textContent = email || 'No email';
            document.getElementById('udPhone').textContent = phone || 'No phone';
            document.getElementById('udCreatedAt').textContent = createdAt ? createdAt.split(' ')[0].split('-').reverse().join('/') : '';

            const roleBadge = document.getElementById('udRoleBadge');
            roleBadge.textContent = roleName.toUpperCase();
            roleBadge.className = 'stock-pill ' + (roleId === '1' ? 'stock-pill-accent' : (roleId === '2' ? 'stock-pill-info-role' : 'stock-pill-muted'));

            const statusBadge = document.getElementById('udStatusBadge');
            const isLocked = status === 'locked';
            statusBadge.textContent = isLocked ? 'LOCKED' : 'ACTIVE';
            statusBadge.className = 'stock-pill ' + (isLocked ? 'stock-pill-critical' : 'stock-pill-success');

            document.getElementById('udEditAccountId').value = accountId;
            document.getElementById('udEditFullName').value = fullName;
            document.getElementById('udEditEmail').value = email;
            document.getElementById('udEditPhone').value = phone;
            document.getElementById('udEditRoleId').value = roleId;

            document.getElementById('udResetAccountId').value = accountId;

            document.getElementById('udLockAccountId').value = accountId;
            const lockBtn = document.getElementById('udLockBtn');
            document.getElementById('udLockAction').value = isLocked ? 'unlock_account' : 'lock_account';
            lockBtn.textContent = isLocked ? 'Unlock account' : 'Lock account';
            lockBtn.className = 'btn btn-sm w-100 ' + (isLocked ? 'btn-outline-success' : 'btn-outline-danger');

            document.getElementById('udDeleteAccountId').value = accountId;
            const deleteBtn = document.getElementById('udDeleteBtn');
            const isSelf = accountId === String(CURRENT_ACTOR_ID);
            deleteBtn.disabled = isSelf;
            deleteBtn.title = isSelf ? 'You cannot delete the account you are currently logged in with.' : '';
        });
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>