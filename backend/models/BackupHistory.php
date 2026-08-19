<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/*
 * FR-ADM-10: model cho bảng backup_history - lịch sử backup/restore CSDL
 * thật. Model này CHỈ đọc/ghi DB, KHÔNG tự gọi mysqldump/mysql - việc gọi
 * binary hệ điều hành thuộc về AdminService (xem ghi chú SCHEMA GAP /
 * INFRA REQUIREMENT ở đầu backupDatabase()/restoreDatabase() trong đó),
 * giữ đúng ranh giới Model chỉ chạm DB - Service chứa business/OS logic.
 */
class BackupHistory
{
    private PDO $conn;
    private string $table = 'backup_history';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    private function tableExists(): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table"
        );
        $stmt->execute([':table' => $this->table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function createTable(): void
    {
        $this->conn->exec(
            "CREATE TABLE {$this->table} (
                backup_id INT AUTO_INCREMENT PRIMARY KEY,
                backup_type ENUM('full', 'restore') NOT NULL DEFAULT 'full',
                file_path VARCHAR(255) NULL,
                file_size_bytes BIGINT NULL,
                status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
                error_message TEXT NULL,
                started_by INT NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                FOREIGN KEY (started_by) REFERENCES accounts(account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureTableExists(): void
    {
        if ($this->tableExists()) {
            return;
        }

        $this->createTable();
    }

    /** Tạo 1 dòng lịch sử mới ở trạng thái 'running' - gọi NGAY TRƯỚC KHI Service thực thi shell_exec(), để có bản ghi kể cả khi tiến trình OS bị crash giữa chừng. */
    public function createRunning(string $backupType, int $startedBy): int
    {
        $this->ensureTableExists();

        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (backup_type, status, started_by, started_at)
             VALUES (:type, 'running', :started_by, NOW())"
        );
        $stmt->execute([':type' => $backupType, ':started_by' => $startedBy]);
        return (int) $this->conn->lastInsertId();
    }

    /** Đóng 1 dòng lịch sử khi tiến trình backup/restore đã có kết quả cuối cùng (thành công hoặc thất bại). */
    public function markFinished(
        int $backupId,
        bool $success,
        ?string $filePath,
        ?int $fileSizeBytes,
        ?string $errorMessage
    ): bool {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = :status, file_path = :file_path, file_size_bytes = :file_size,
                 error_message = :error_message, finished_at = NOW()
             WHERE backup_id = :id"
        );
        return $stmt->execute([
            ':status'        => $success ? 'success' : 'failed',
            ':file_path'     => $filePath,
            ':file_size'     => $fileSizeBytes,
            ':error_message' => $errorMessage,
            ':id'            => $backupId,
        ]);
    }

    /* FR-ADM-10: danh sách lịch sử backup/restore, mới nhất trước - dùng cho bảng "Recent Backups". */
    public function getAll(int $limit = 50): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $stmt = $this->conn->prepare(
            "SELECT bh.backup_id, bh.backup_type, bh.file_path, bh.file_size_bytes,
                    bh.status, bh.error_message, bh.started_at, bh.finished_at,
                    a.full_name AS started_by_name
             FROM {$this->table} bh
             JOIN accounts a ON a.account_id = bh.started_by
             ORDER BY bh.started_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getById(int $backupId): array|false
    {
        if (!$this->tableExists()) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "SELECT bh.backup_id, bh.backup_type, bh.file_path, bh.file_size_bytes,
                    bh.status, bh.error_message, bh.started_at, bh.finished_at,
                    a.full_name AS started_by_name
             FROM {$this->table} bh
             JOIN accounts a ON a.account_id = bh.started_by
             WHERE bh.backup_id = :id"
        );
        $stmt->execute([':id' => $backupId]);
        return $stmt->fetch();
    }

    /*
     * FR-ADM-10: thống kê THẬT để hiển thị KPI card (Latest Backup, Total
     * Backups, Storage Used, Success Rate) - tính trực tiếp từ dữ liệu có
     * sẵn trong bảng, KHÔNG lưu số liệu tổng hợp riêng (tránh lệch dữ liệu
     * gốc khi có backup mới). success_rate_percent = NULL nếu chưa có lần
     * backup 'full' nào từng hoàn tất (tránh chia cho 0).
     */
    public function getStats(): array
    {
        if (!$this->tableExists()) {
            return [
                'total_backups'         => 0,
                'storage_used_bytes'    => 0,
                'success_rate_percent'  => null,
                'latest_backup_at'      => null,
                'latest_backup_status'  => null,
                'latest_restore_at'     => null,
            ];
        }

        $stmt = $this->conn->query(
            "SELECT
                COUNT(*) AS total_backups,
                SUM(CASE WHEN status = 'success' THEN file_size_bytes ELSE 0 END) AS storage_used_bytes,
                SUM(CASE WHEN backup_type = 'full' AND status IN ('success', 'failed') THEN 1 ELSE 0 END) AS total_finished_full,
                SUM(CASE WHEN backup_type = 'full' AND status = 'success' THEN 1 ELSE 0 END) AS total_success_full
             FROM {$this->table}
             WHERE backup_type = 'full'"
        );
        $row = $stmt->fetch();

        $totalFinished = (int) ($row['total_finished_full'] ?? 0);
        $totalSuccess  = (int) ($row['total_success_full'] ?? 0);

        $latestStmt = $this->conn->query(
            "SELECT started_at, status FROM {$this->table}
             WHERE backup_type = 'full'
             ORDER BY started_at DESC LIMIT 1"
        );
        $latest = $latestStmt->fetch();

        $latestRestoreStmt = $this->conn->query(
            "SELECT finished_at FROM {$this->table}
             WHERE backup_type = 'restore' AND status = 'success'
             ORDER BY finished_at DESC LIMIT 1"
        );
        $latestRestore = $latestRestoreStmt->fetch();

        return [
            'total_backups'         => (int) ($row['total_backups'] ?? 0),
            'storage_used_bytes'    => (int) ($row['storage_used_bytes'] ?? 0),
            'success_rate_percent'  => $totalFinished > 0 ? round($totalSuccess / $totalFinished * 100, 1) : null,
            'latest_backup_at'      => $latest['started_at'] ?? null,
            'latest_backup_status'  => $latest['status'] ?? null,
            'latest_restore_at'     => $latestRestore['finished_at'] ?? null,
        ];
    }
}