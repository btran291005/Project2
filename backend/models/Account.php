<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Account
{
    private PDO $conn;
    private string $table = 'accounts';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // accounts - lookup dùng cho Auth::login()

    /* Lấy 1 account theo username (dùng cho Auth::login()). */
    public function getByUsername(string $username): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT account_id, username, password_hash, full_name, role_id, status
             FROM {$this->table}
             WHERE username = :username
             LIMIT 1"
        );
        $stmt->execute([':username' => $username]);
        return $stmt->fetch();
    }

    public function getById(int $accountId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT a.account_id, a.username, a.full_name, a.email, a.phone_number,
                    a.role_id, r.role_name, a.status, a.created_at
             FROM {$this->table} a
             JOIN roles r ON r.role_id = a.role_id
             WHERE a.account_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $accountId]);
        return $stmt->fetch();
    }

    /* FR-ADM-02: danh sách toàn bộ account, kèm role_name, lọc theo role_id/status/keyword. */
    public function getAll(?int $roleId = null, ?string $status = null, ?string $keyword = null): array
    {
        $sql = "SELECT a.account_id, a.username, a.full_name, a.email, a.phone_number,
                       a.role_id, r.role_name, a.status, a.created_at
                FROM {$this->table} a
                JOIN roles r ON r.role_id = a.role_id
                WHERE 1=1";
        $params = [];

        if ($roleId !== null) {
            $sql .= " AND a.role_id = :role_id";
            $params[':role_id'] = $roleId;
        }
        if ($status !== null) {
            $sql .= " AND a.status = :status";
            $params[':status'] = $status;
        }
        if ($keyword !== null && $keyword !== '') {
            $sql .= " AND (a.full_name LIKE :kw1 OR a.username LIKE :kw2 OR a.email LIKE :kw3)";
            $kwParam = '%' . $keyword . '%';
            $params[':kw1'] = $kwParam;
            $params[':kw2'] = $kwParam;
            $params[':kw3'] = $kwParam;
        }

        $sql .= " ORDER BY a.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /* FR-ADM-02: tạo account mới. $data['password'] PHẢI là plain text - hàm này tự hash bằng password_hash(); Service không được tự hash trước.
     * BR-17: chỉ AdminService (sau khi Middleware::guard([ROLE_ADMIN])) được gọi hàm này.*/
    public function create(array $data): array
    {
        $sql = "INSERT INTO {$this->table} (username, password_hash, full_name, email, phone_number, role_id, status)
                VALUES (:username, :password_hash, :full_name, :email, :phone_number, :role_id, :status)";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':username'      => trim($data['username']),
                ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                ':full_name'     => trim($data['full_name']),
                ':email'         => !empty($data['email']) ? trim($data['email']) : null,
                ':phone_number'  => !empty($data['phone_number']) ? trim($data['phone_number']) : null,
                ':role_id'       => (int) $data['role_id'],
                ':status'        => $data['status'] ?? 'active',
            ]);

            return [
                'success'    => true,
                'account_id' => $this->conn->lastInsertId(),
                'message'    => 'Account created successfully.',
            ];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['success' => false, 'message' => "Username '{$data['username']}' already exists."];
            }
            error_log('[Account::create] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while creating the account.'];
        }
    }

    /* FR-ADM-02: cập nhật full_name/email/phone_number/role_id. KHÔNG đổi password ở đây - dùng updatePassword() riêng để tránh vô tình ghi đè bằng chuỗi rỗng nếu form không gửi password. */
    public function update(int $accountId, array $data): bool
    {
        $allowed = ['full_name', 'email', 'phone_number', 'role_id'];
        $fields = [];
        $params = [':id' => $accountId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE account_id = :id";
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    /* Đổi mật khẩu - gọi Auth::validatePasswordStrength() TRƯỚC khi tới đây. */
    public function updatePassword(int $accountId, string $newPlainPassword): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET password_hash = :password_hash WHERE account_id = :id"
        );
        return $stmt->execute([
            ':password_hash' => password_hash($newPlainPassword, PASSWORD_DEFAULT),
            ':id'            => $accountId,
        ]);
    }

    /* FR-ADM-02: khóa tài khoản. */
    public function lock(int $accountId): bool
    {
        return $this->setStatus($accountId, 'locked');
    }

    /* FR-ADM-02: mở khóa tài khoản. */
    public function unlock(int $accountId): bool
    {
        return $this->setStatus($accountId, 'active');
    }

    private function setStatus(int $accountId, string $status): bool
    {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = :status WHERE account_id = :id");
        return $stmt->execute([':status' => $status, ':id' => $accountId]);
    }

    /**
     * FR-ADM-02: xoá tài khoản. account_id được NHIỀU bảng khác tham chiếu
     * KHÔNG có ON DELETE CASCADE (purchase_orders.created_by/approved_by,
     * reorder_rules.updated_by, audit_logs.account_id, stock_transactions,
     * inventory_adjustments, stock_counts, shortage_incidents, api_configs...)
     * - hầu hết là NOT NULL. Nếu account đã từng thao tác bất kỳ nghiệp vụ
     * nào, MySQL sẽ từ chối bằng lỗi khoá ngoại (1451) - đây là hành vi ĐÚNG
     * và CỐ Ý, để không bao giờ âm thầm mất dấu vết audit log/PO/lịch sử tồn
     * kho (vi phạm FR-ADM-07/FR-SYS-03) chỉ vì xoá 1 tài khoản.
     *
     * @return array{success: bool, message: string, blocked?: bool} $blocked=true nghĩa là DB từ chối do còn dữ liệu liên quan (không phải lỗi hệ thống).
     */
    public function delete(int $accountId): array
    {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE account_id = :id");
            $stmt->execute([':id' => $accountId]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Account not found.'];
            }

            return ['success' => true, 'message' => 'Account deleted.'];
        } catch (PDOException $e) {
            // 1451 = Cannot delete or update a parent row: a foreign key constraint fails
            if ((int) $e->errorInfo[1] === 1451 || (int) $e->getCode() === 23000) {
                return [
                    'success' => false,
                    'blocked' => true,
                    'message' => 'Cannot delete: this account has related data in the system (Purchase Order, audit log, stock in/out records...). Please use the Lock Account feature instead.',
                ];
            }
            error_log('[Account::delete] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while deleting the account.'];
        }
    }

    // roles / permissions / role_permissions (FR-ADM-03)

    public function getAllRoles(): array
    {
        return $this->conn->query("SELECT role_id, role_name FROM roles ORDER BY role_id")->fetchAll();
    }

    /* Đếm số permission thật (role_permissions) của từng role - dùng cho summary
     * card ở accounts.php/permissions.php. KHÔNG bịa số cố định như mockup. */
    public function countPermissionsByRole(): array
    {
        $rows = $this->conn->query(
            "SELECT role_id, COUNT(*) AS permission_count FROM role_permissions GROUP BY role_id"
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['role_id']] = (int) $row['permission_count'];
        }
        return $result;
    }

    public function getAllPermissions(): array
    {
        return $this->conn->query(
            "SELECT permission_id, permission_code, description FROM permissions ORDER BY permission_code"
        )->fetchAll();
    }

    /* Danh sách permission (record đầy đủ) đang gán cho 1 role - dùng cho UI permissions.php. */
    public function getPermissionsForRole(int $roleId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT p.permission_id, p.permission_code, p.description
             FROM role_permissions rp
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = :role_id
             ORDER BY p.permission_code"
        );
        $stmt->execute([':role_id' => $roleId]);
        return $stmt->fetchAll();
    }

    /* Danh sách permission_code (string) của 1 role - dùng bởi Middleware khi cần kiểm tra permission chi tiết (thay vì chỉ check theo role_id). */
    public function getRolePermissionCodes(int $roleId): array
    {
        $rows = $this->getPermissionsForRole($roleId);
        return array_column($rows, 'permission_code');
    }

    /* FR-ADM-03: gán 1 permission cho 1 role (bỏ qua nếu đã tồn tại). */
    public function assignPermissionToRole(int $roleId, int $permissionId): bool
    {
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)"
        );
        return $stmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
    }

    /* FR-ADM-03: thu hồi 1 permission khỏi 1 role. */
    public function revokePermissionFromRole(int $roleId, int $permissionId): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id"
        );
        return $stmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
    }
}