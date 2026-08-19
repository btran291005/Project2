<?php
 
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Logger
{
    /* Ghi 1 dòng audit log. */
    public static function log(
        int $accountId,
        string $actionType,
        ?string $targetTable = null,
        ?int $targetId = null
    ): bool {
        try {
            $pdo = Database::getConnection();

            $sql = 'INSERT INTO audit_logs (account_id, action_type, target_table, target_id, timestamp)
                    VALUES (:account_id, :action_type, :target_table, :target_id, NOW())';

            $stmt = $pdo->prepare($sql);

            return $stmt->execute([
                ':account_id'   => $accountId,
                ':action_type'  => $actionType,
                ':target_table' => $targetTable,
                ':target_id'    => $targetId,
            ]);
        } catch (PDOException $e) {
            // Nguyên tắc: lỗi ghi log KHÔNG được làm sập luồng nghiệp vụ chính
            // (VD: nếu ghi audit_logs lỗi thì việc approve PO vẫn phải thành công).
            // Chỉ ghi lỗi ra error_log của server để dev theo dõi.
            error_log('[Logger] Failed to write audit log: ' . $e->getMessage());
            return false;
        }
    }

    /* Helper: log hành động của user đang đăng nhập (lấy từ session qua Auth). Dùng trong Service khi đã chắc chắn có người dùng đăng nhập. */
    public static function logCurrentUser(
        string $actionType,
        ?string $targetTable = null,
        ?int $targetId = null
    ): bool {
        $accountId = Auth::id();

        if ($accountId === null) {
            // Không có user trong session (VD: cron job, system action) -> không log qua hàm này
            error_log('[Logger] logCurrentUser() called without an authenticated session.');
            return false;
        }

        return self::log($accountId, $actionType, $targetTable, $targetId);
    }
}