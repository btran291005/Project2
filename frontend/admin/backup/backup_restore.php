<?php
/**
 * File: frontend/admin/setting/backup_restore.php
 * Purpose: UI sao lưu/phục hồi CSDL (FR-ADM-10) - tạo backup mới, phục hồi từ
 * 1 bản backup đã có, tải file .sql, xem lịch sử Recent Backups.
 * Related: FR-ADM-10
 * Calls: AdminService::backupDatabase(), restoreDatabase(), listBackupHistory(),
 *        getBackupStats()
 *
 * LƯU Ý DỮ LIỆU (đối chiếu với mockup tham khảo - KHÔNG bịa các phần sau vì
 * hệ thống thật không có):
 *   - KHÔNG có "Backup Intelligence" (AI gợi ý % confidence, dự đoán storage
 *     tăng X% trong Y ngày) - không có analytics/AI engine nào cho việc này.
 *   - KHÔNG có "Infrastructure Health" (Database/Backup Service/Storage
 *     Server/Cloud Connection - Healthy/Running/Online) - không có health-
 *     check service nào theo dõi các thành phần này.
 *   - KHÔNG có "Automated Scheduler" (Frequency/Execution Time/Target
 *     Location Cloud S3/Local NAS) - không có cron job hay cloud storage
 *     integration nào trong hệ thống, backup CHỈ chạy khi Admin bấm nút.
 *   - KHÔNG có "Retention & Purge Policy" (auto-compress, encrypt AES-256,
 *     tự xóa backup cũ) - chưa implement nén/mã hóa/tự dọn dẹp.
 *   - KHÔNG có "Sync Status: Cloud Active" - không có cloud sync nào.
 *   - Latest Backup / Total Backups / Storage Used / Success Rate / Last
 *     Restore DÙNG DATA THẬT từ AdminService::getBackupStats() (tính trực
 *     tiếp từ bảng backup_history, không lưu số liệu tổng hợp riêng).
 *
 * Style/layout đồng bộ các trang admin khác - app-shell + panel-card.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/AdminService.php';

Middleware::guard([ROLE_ADMIN]);

$adminService = new AdminService();
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ POST: create_backup / restore_backup
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_backup') {
        $result = $adminService->backupDatabase($actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    } elseif ($action === 'restore_backup') {
        $sourceBackupId = (int) ($_POST['source_backup_id'] ?? 0);
        $result = $adminService->restoreDatabase($sourceBackupId, $actorId);
        $flashMessage = $result['message'];
        $flashIsError = !$result['success'];
    }
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$stats = $adminService->getBackupStats();
$history = $adminService->listBackupHistory(20);

/** Đổi bytes -> chuỗi dễ đọc (KB/MB/GB) - dùng cho Storage Used và cột Size trong bảng. */
function formatBytes(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return round($value, 1) . ' ' . $units[$i];
}

/** Thời điểm -> chuỗi tương đối kiểu "5 days ago" - chỉ dùng cấp độ ngày/giờ, không suy diễn phút/giây cho chính xác giả tạo. */
function formatRelativeTime(?string $datetime): string
{
    if ($datetime === null) {
        return 'None yet';
    }
    $diffSeconds = time() - strtotime($datetime);
    if ($diffSeconds < 3600) {
        $mins = max(1, (int) floor($diffSeconds / 60));
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diffSeconds < 86400) {
        $hours = (int) floor($diffSeconds / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int) floor($diffSeconds / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

/** Badge màu theo status backup/restore. */
function backupStatusPillClass(string $status): string
{
    return match ($status) {
        'success' => 'stock-pill-success',
        'failed'  => 'stock-pill-critical',
        'running' => 'stock-pill-warn',
        default   => 'stock-pill-muted',
    };
}

$pageTitle   = 'System Backup & Restore';
$breadcrumbs = ['Admin', 'Settings', 'System Backup'];
$activeMenu  = 'system_backup';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Backup &amp; Restore - InventoryDSS</title>
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

                <div class="mb-4">
                    <h2 class="page-heading mb-1">System Backup &amp; Restore</h2>
                    <p class="page-subheading mb-0">Securely back up and restore the database (FR-ADM-10).</p>
                </div>

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3 mb-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <!-- KPI cards - toàn bộ số liệu THẬT từ backup_history, không có ô nào bịa -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="kpi-card h-100">
                            <span class="kpi-label">Latest Backup</span>
                            <span class="kpi-value" style="font-size: 1.3rem;">
                                <?= $stats['latest_backup_at'] !== null ? htmlspecialchars(date('H:i d/m', strtotime($stats['latest_backup_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                            </span>
                            <?php if ($stats['latest_backup_status'] !== null): ?>
                                <span class="stock-pill <?= backupStatusPillClass($stats['latest_backup_status']) ?>" style="width: fit-content;">
                                    <?= htmlspecialchars(ucfirst($stats['latest_backup_status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="kpi-card h-100">
                            <span class="kpi-label">Total Backups</span>
                            <span class="kpi-value"><?= $stats['total_backups'] ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="kpi-card h-100">
                            <span class="kpi-label">Storage Used</span>
                            <span class="kpi-value"><?= formatBytes($stats['storage_used_bytes']) ?></span>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="kpi-card h-100">
                            <span class="kpi-label">Success Rate</span>
                            <span class="kpi-value">
                                <?= $stats['success_rate_percent'] !== null ? $stats['success_rate_percent'] . '%' : '—' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-8">

                        <!-- Actions -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <form method="POST" onsubmit="return confirm('Create a new backup of the entire database?');">
                                <input type="hidden" name="action" value="create_backup">
                                <button type="submit" class="btn btn-brand d-inline-flex align-items-center gap-2">Create Backup</button>
                            </form>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#restoreModal">Restore Point</button>
                        </div>

                        <!-- Recent Backups -->
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title mb-0">Recent Backups</h3>
                                <span class="panel-card-note"><?= count($history) ?> record<?= count($history) === 1 ? '' : 's' ?></span>
                            </div>

                            <?php if (empty($history)): ?>
                                <div class="empty-state">No backups yet. Click "Create Backup" to create the first one.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table data-table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Backup ID</th>
                                                <th>Type</th>
                                                <th>Date &amp; Time</th>
                                                <th>Size</th>
                                                <th>Status</th>
                                                <th class="text-end">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($history as $row): ?>
                                                <tr>
                                                    <td class="fw-semibold">#BK-<?= (int) $row['backup_id'] ?></td>
                                                    <td class="text-muted small"><?= $row['backup_type'] === 'full' ? 'Full System' : 'Restore' ?></td>
                                                    <td>
                                                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($row['started_at'])), ENT_QUOTES, 'UTF-8') ?>
                                                        <div class="text-muted small">by <?= htmlspecialchars($row['started_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td><?= formatBytes($row['file_size_bytes'] !== null ? (int) $row['file_size_bytes'] : null) ?></td>
                                                    <td>
                                                        <span class="stock-pill <?= backupStatusPillClass($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status']), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php if ($row['status'] === 'failed' && !empty($row['error_message'])): ?>
                                                            <div class="text-danger small mt-1" style="max-width: 260px;" title="<?= htmlspecialchars($row['error_message'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <?= htmlspecialchars(mb_strimwidth($row['error_message'], 0, 60, '…'), ENT_QUOTES, 'UTF-8') ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($row['status'] === 'success' && $row['backup_type'] === 'full'): ?>
                                                            <a href="backup_download.php?backup_id=<?= (int) $row['backup_id'] ?>" class="btn btn-outline-secondary btn-sm">Download</a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                    <div class="col-lg-4">
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <h3 class="panel-card-title mb-0">Last Restore</h3>
                            </div>
                            <div class="fw-semibold"><?= formatRelativeTime($stats['latest_restore_at']) ?></div>
                            <div class="text-muted small mt-1">
                                <?= $stats['latest_restore_at'] !== null ? 'Restore completed successfully.' : 'No restore has been performed yet.' ?>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <!-- Modal: Restore từ 1 bản backup đã tạo thành công -->
    <div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="restore_backup">
                <div class="modal-header">
                    <h5 class="modal-title">Restore Database</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php
                        $restorableBackups = array_filter(
                            $history,
                            static fn(array $r): bool => $r['status'] === 'success' && $r['backup_type'] === 'full'
                        );
                    ?>
                    <?php if (empty($restorableBackups)): ?>
                        <p class="text-muted mb-0">No successful backup is available to restore from.</p>
                    <?php else: ?>
                        <label class="form-label small">Select a backup to restore</label>
                        <select name="source_backup_id" class="form-select" required>
                            <?php foreach ($restorableBackups as $r): ?>
                                <option value="<?= (int) $r['backup_id'] ?>">
                                    #BK-<?= (int) $r['backup_id'] ?> — <?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['started_at'])), ENT_QUOTES, 'UTF-8') ?> (<?= formatBytes((int) $r['file_size_bytes']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="alert alert-warning py-2 px-3 mt-3 mb-0" style="font-size: .82rem;">
                            ⚠️ Restoring will OVERWRITE all current data with the data from the selected backup. This action cannot be undone.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($restorableBackups)): ?>
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('FINAL CONFIRMATION: restoring will overwrite all current data. Continue?');">Restore</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>