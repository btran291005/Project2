<?php
/**
 * File: backend/services/AdminService.php
 * Purpose: Business logic for all Admin actions — account/permission management,
 *          category-based reorder rule configuration, PO approval, API config,
 *          audit log retrieval.
 * Related: FR-ADM-01 through FR-ADM-11, BR-16, BR-17, BR-20
 * Note: PO approval logic (including the BR-20 edit-lock check) lives here,
 *       not in the UI page.
 *
 * QUY ƯỚC CHUNG CỦA SERVICE LAYER (áp dụng cho toàn bộ file trong Phase 4):
 *   - Service KHÔNG tự kiểm tra role (BR-17/BR-19) - việc đó đã được chặn ở
 *     Middleware::guard([ROLE_ADMIN]) ngay đầu file frontend/admin/*.php, TRƯỚC
 *     khi Controller gọi tới Service. Service chỉ nhận $actorId (account_id của
 *     người đang thao tác) để truyền xuống Model/Logger.
 *   - Service không tự mở PDO transaction chồng lên Model - các Model liên quan
 *     (Account, Product, Order...) đã tự atomic hóa (beginTransaction/commit) ở
 *     đúng chỗ cần. Service chỉ điều phối gọi nhiều Model theo đúng thứ tự
 *     nghiệp vụ và validate input trước khi xuống tầng data-access.
 *   - Input từ Controller (thường là $_POST) LUÔN được ép kiểu (int)/trim() ở
 *     đây trước khi truyền vào Model, không tin tưởng dữ liệu thô từ request.
 *   - Return value thống nhất dạng array ['success' => bool, 'message' => string, ...]
 *     giống hệt pattern đã có ở Model, để Controller xử lý nhất quán.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Supplier.php';
require_once __DIR__ . '/../models/Warehouse.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/StockCount.php';
require_once __DIR__ . '/../models/BackupHistory.php';

class AdminService
{
    private Account $accountModel;
    private Product $productModel;
    private Supplier $supplierModel;
    private Warehouse $warehouseModel;
    private Order $orderModel;
    private StockCount $stockCountModel;
    private BackupHistory $backupHistoryModel;

    public function __construct()
    {
        $this->accountModel    = new Account();
        $this->productModel    = new Product();
        $this->supplierModel   = new Supplier();
        $this->warehouseModel  = new Warehouse();
        $this->orderModel      = new Order();
        $this->stockCountModel = new StockCount();
        $this->backupHistoryModel = new BackupHistory();
    }

    // =====================================================================
    // 1. USER MANAGEMENT (FR-ADM-02, BR-17)
    // =====================================================================

    /**
     * FR-ADM-02: tạo tài khoản mới. Kiểm tra độ mạnh mật khẩu (Auth) trước khi
     * xuống Model - Model chỉ chịu trách nhiệm hash + INSERT, không validate rule.
     *
     * @param array $data ['username'=>string,'password'=>string,'full_name'=>string,'email'=>string?,'phone_number'=>string?,'role_id'=>int]
     * @return array{success: bool, account_id?: string, message: string}
     */
    public function createAccount(array $data, int $actorId): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $phone    = trim((string) ($data['phone_number'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $roleId   = (int) ($data['role_id'] ?? 0);

        if ($username === '' || $fullName === '') {
            return ['success' => false, 'message' => 'Username and full name cannot be empty.'];
        }

        if (!in_array($roleId, [ROLE_ADMIN, ROLE_MANAGER, ROLE_STAFF], true)) {
            return ['success' => false, 'message' => 'Invalid role.'];
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format.'];
        }

        $strength = Auth::validatePasswordStrength($password);
        if (!$strength['valid']) {
            return ['success' => false, 'message' => $strength['message']];
        }

        $result = $this->accountModel->create([
            'username'     => $username,
            'password'     => $password,
            'full_name'    => $fullName,
            'email'        => $email !== '' ? $email : null,
            'phone_number' => $phone !== '' ? $phone : null,
            'role_id'      => $roleId,
            'status'       => 'active',
        ]);

        if ($result['success']) {
            Logger::log($actorId, 'CREATE_ACCOUNT', 'accounts', (int) $result['account_id']);
        }

        return $result;
    }

    /**
     * FR-ADM-02: cập nhật họ tên/email/sđt/vai trò của 1 tài khoản. Đổi mật khẩu xử lý
     * riêng ở resetPassword() để tránh vô tình ghi đè bằng chuỗi rỗng.
     *
     * @param array $data ['full_name'=>string?, 'email'=>string?, 'phone_number'=>string?, 'role_id'=>int?]
     */
    public function updateAccount(int $accountId, array $data, int $actorId): array
    {
        $payload = [];

        if (array_key_exists('full_name', $data)) {
            $fullName = trim((string) $data['full_name']);
            if ($fullName === '') {
                return ['success' => false, 'message' => 'Full name cannot be empty.'];
            }
            $payload['full_name'] = $fullName;
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Invalid email format.'];
            }
            $payload['email'] = $email !== '' ? $email : null;
        }

        if (array_key_exists('phone_number', $data)) {
            $phone = trim((string) $data['phone_number']);
            $payload['phone_number'] = $phone !== '' ? $phone : null;
        }

        if (array_key_exists('role_id', $data)) {
            $roleId = (int) $data['role_id'];
            if (!in_array($roleId, [ROLE_ADMIN, ROLE_MANAGER, ROLE_STAFF], true)) {
                return ['success' => false, 'message' => 'Invalid role.'];
            }
            $payload['role_id'] = $roleId;
        }

        if (empty($payload)) {
            return ['success' => false, 'message' => 'No data to update.'];
        }

        $ok = $this->accountModel->update($accountId, $payload);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Account updated.' : 'An error occurred while updating.'];
    }

    /** FR-ADM-02: Admin đặt lại mật khẩu cho 1 tài khoản (không cần biết mật khẩu cũ). */
    public function resetPassword(int $accountId, string $newPassword, int $actorId): array
    {
        $strength = Auth::validatePasswordStrength($newPassword);
        if (!$strength['valid']) {
            return ['success' => false, 'message' => $strength['message']];
        }

        $ok = $this->accountModel->updatePassword($accountId, $newPassword);

        if ($ok) {
            Logger::log($actorId, 'RESET_PASSWORD', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Password reset.' : 'An error occurred while resetting the password.'];
    }

    /**
     * FR-ADM-02: khóa tài khoản. Chặn Admin tự khóa chính mình để tránh tình
     * huống hệ thống mất hết Admin còn hoạt động (không có trong BR nhưng là
     * an toàn vận hành tối thiểu).
     */
    public function lockAccount(int $accountId, int $actorId): array
    {
        if ($accountId === $actorId) {
            return ['success' => false, 'message' => 'Cannot lock the account you are currently logged in with.'];
        }

        $ok = $this->accountModel->lock($accountId);

        if ($ok) {
            Logger::log($actorId, 'LOCK_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Account locked.' : 'An error occurred while locking the account.'];
    }

    public function unlockAccount(int $accountId, int $actorId): array
    {
        $ok = $this->accountModel->unlock($accountId);

        if ($ok) {
            Logger::log($actorId, 'UNLOCK_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Account unlocked.' : 'An error occurred while unlocking the account.'];
    }

    /**
     * FR-ADM-02: xoá tài khoản. Chặn Admin tự xoá chính mình (cùng lý do an
     * toàn vận hành như lockAccount()). Xoá thật (không phải soft-delete) vì
     * bảng accounts không có cột trạng thái "deleted" riêng - nếu account đã
     * có dữ liệu liên quan (PO, audit log cũ, phiếu nhập/xuất...), DB sẽ tự
     * từ chối qua ràng buộc khoá ngoại (xem Account::delete()) và trả về
     * $result['blocked'] = true để UI gợi ý dùng Khoá tài khoản thay thế.
     */
    public function deleteAccount(int $accountId, int $actorId): array
    {
        if ($accountId === $actorId) {
            return ['success' => false, 'message' => 'Cannot delete the account you are currently logged in with.'];
        }

        $result = $this->accountModel->delete($accountId);

        if ($result['success']) {
            Logger::log($actorId, 'DELETE_ACCOUNT', 'accounts', $accountId);
        }

        return $result;
    }

    /** FR-ADM-02: danh sách tài khoản, lọc theo role/status/từ khoá tìm kiếm nếu cần (truyền qua từ UI filter). */
    public function listAccounts(?int $roleId = null, ?string $status = null, ?string $keyword = null): array
    {
        return $this->accountModel->getAll($roleId, $status, $keyword);
    }

    public function getAccountDetail(int $accountId): array|false
    {
        return $this->accountModel->getById($accountId);
    }

    /** Số permission thật đang gán cho từng role - dùng cho summary card, KHÔNG bịa số cố định. */
    public function countPermissionsByRole(): array
    {
        return $this->accountModel->countPermissionsByRole();
    }

    // =====================================================================
    // 2. ROLE & PERMISSION MANAGEMENT (FR-ADM-03, BR-17)
    // =====================================================================

    public function listRoles(): array
    {
        return $this->accountModel->getAllRoles();
    }

    public function listPermissions(): array
    {
        return $this->accountModel->getAllPermissions();
    }

    /** FR-ADM-03: xem chi tiết các permission đang gán cho 1 role - dùng để render checkbox trên UI. */
    public function getPermissionsForRole(int $roleId): array
    {
        return $this->accountModel->getPermissionsForRole($roleId);
    }

    /**
     * FR-ADM-03: đồng bộ toàn bộ permission của 1 role theo danh sách permission_id
     * gửi từ form (checkbox) - permission nào có trong $permissionIds thì gán,
     * permission nào đang có mà không còn trong danh sách thì thu hồi.
     * Gộp thành 1 hàm duy nhất để UI chỉ cần submit checkbox state hiện tại,
     * không cần tính diff ở Controller.
     *
     * @param int[] $permissionIds
     */
    public function syncRolePermissions(int $roleId, array $permissionIds, int $actorId): array
    {
        $current       = array_column($this->accountModel->getPermissionsForRole($roleId), 'permission_id');
        $desired       = array_map('intval', $permissionIds);
        $toAssign      = array_diff($desired, $current);
        $toRevoke      = array_diff($current, $desired);

        foreach ($toAssign as $permissionId) {
            $this->accountModel->assignPermissionToRole($roleId, (int) $permissionId);
        }
        foreach ($toRevoke as $permissionId) {
            $this->accountModel->revokePermissionFromRole($roleId, (int) $permissionId);
        }

        if (!empty($toAssign) || !empty($toRevoke)) {
            Logger::log($actorId, 'UPDATE_ROLE_PERMISSIONS', 'role_permissions', $roleId);
        }

        return ['success' => true, 'message' => 'Role permissions updated.'];
    }

    // =====================================================================
    // 3. MASTER DATA MANAGEMENT (FR-ADM-01: Product, Supplier, Warehouse)
    // =====================================================================
    // Ghi chú: master data đã có CRUD đầy đủ ở tầng Model (Product/Supplier/
    // Warehouse). Service ở đây chủ yếu validate input tối thiểu + đảm bảo mọi
    // thay đổi master data đều có audit log (FR-SYS-03), vì các Model này
    // (khác với Product::upsertReorderRule()) KHÔNG tự ghi Logger.

    public function listProducts(?int $categoryId = null, ?string $keyword = null, bool $activeOnly = false): array
    {
        return $this->productModel->getAll($categoryId, $keyword, $activeOnly);
    }

    public function getProductDetail(int $productId): array|false
    {
        return $this->productModel->getById($productId);
    }

    public function listCategories(): array
    {
        return $this->productModel->getCategories();
    }

    /**
     * FR-ADM-01 / FR-ADM-05: tạo sản phẩm mới. category_id bắt buộc (đã được
     * DB enforce qua NOT NULL, nhưng validate sớm ở đây để trả lỗi rõ ràng
     * thay vì để PDOException).
     *
     * @param array $data Bao gồm 'unit_cost' (giá nhập từ NCC, dùng để tính
     *                     giá trị đơn đặt hàng - PO Amount). Nếu không truyền,
     *                     Product::create() mặc định 0.
     */
    public function createProduct(array $data, int $actorId): array
    {
        if (empty($data['sku_code']) || empty($data['product_name'])) {
            return ['success' => false, 'message' => 'SKU code and product name cannot be empty.'];
        }
        if (empty($data['category_id'])) {
            return ['success' => false, 'message' => 'Product must be assigned a category_type before saving (FR-ADM-05).'];
        }
        if (empty($data['supplier_id'])) {
            return ['success' => false, 'message' => 'Product must be assigned a supplier.'];
        }
        if (isset($data['unit_cost']) && (float) $data['unit_cost'] < 0) {
            return ['success' => false, 'message' => 'Purchase cost (unit_cost) cannot be negative.'];
        }

        $result = $this->productModel->create($data);

        if ($result['success']) {
            Logger::log($actorId, 'CREATE_PRODUCT', 'products', (int) $result['product_id']);
        }

        return $result;
    }

    /**
     * @param array $data Có thể bao gồm 'unit_cost' (giá nhập) - Product::update()
     *                     chỉ ghi các field có mặt trong $data (partial update).
     */
    public function updateProduct(int $productId, array $data, int $actorId): array
    {
        if (isset($data['unit_cost']) && (float) $data['unit_cost'] < 0) {
            return ['success' => false, 'message' => 'Purchase cost (unit_cost) cannot be negative.'];
        }

        $ok = $this->productModel->update($productId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Product updated.' : 'An error occurred or nothing changed.'];
    }

    /**
     * "Xóa" sản phẩm = vô hiệu hóa (deactivate), KHÔNG xóa cứng - lý do đã ghi
     * rõ trong Product::deactivate() (product_id bị nhiều bảng khác tham chiếu).
     */
    public function deactivateProduct(int $productId, int $actorId): array
    {
        $ok = $this->productModel->deactivate($productId);

        if ($ok) {
            Logger::log($actorId, 'DEACTIVATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Product discontinued.' : 'An error occurred.'];
    }

    public function activateProduct(int $productId, int $actorId): array
    {
        $ok = $this->productModel->activate($productId);

        if ($ok) {
            Logger::log($actorId, 'ACTIVATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Product reactivated.' : 'An error occurred.'];
    }

    // --- Suppliers ---

    public function listSuppliers(): array
    {
        return $this->supplierModel->getAll();
    }

    public function getSupplierDetail(int $supplierId): array|false
    {
        return $this->supplierModel->getById($supplierId);
    }

    public function createSupplier(array $data, int $actorId): array
    {
        if (empty($data['supplier_name'])) {
            return ['success' => false, 'message' => 'Supplier name cannot be empty.'];
        }

        $supplierId = $this->supplierModel->create($data);
        Logger::log($actorId, 'CREATE_SUPPLIER', 'suppliers', (int) $supplierId);

        return ['success' => true, 'supplier_id' => $supplierId, 'message' => 'Supplier created.'];
    }

    public function updateSupplier(int $supplierId, array $data, int $actorId): array
    {
        $ok = $this->supplierModel->update($supplierId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_SUPPLIER', 'suppliers', $supplierId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Supplier updated.' : 'An error occurred or nothing changed.'];
    }

    public function deleteSupplier(int $supplierId, int $actorId): array
    {
        $ok = $this->supplierModel->delete($supplierId);

        if ($ok) {
            Logger::log($actorId, 'DELETE_SUPPLIER', 'suppliers', $supplierId);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Supplier deleted.'
                : 'Cannot delete: this supplier is still referenced by products or orders.',
        ];
    }

    // --- Warehouses ---

    public function listWarehouses(): array
    {
        return $this->warehouseModel->getAll();
    }

    public function createWarehouse(array $data, int $actorId): array
    {
        if (empty($data['warehouse_name'])) {
            return ['success' => false, 'message' => 'Warehouse name cannot be empty.'];
        }

        $warehouseId = $this->warehouseModel->create($data);
        Logger::log($actorId, 'CREATE_WAREHOUSE', 'warehouses', (int) $warehouseId);

        return ['success' => true, 'warehouse_id' => $warehouseId, 'message' => 'Warehouse created.'];
    }

    public function updateWarehouse(int $warehouseId, array $data, int $actorId): array
    {
        $ok = $this->warehouseModel->update($warehouseId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_WAREHOUSE', 'warehouses', $warehouseId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Warehouse updated.' : 'An error occurred or nothing changed.'];
    }

    public function deleteWarehouse(int $warehouseId, int $actorId): array
    {
        $ok = $this->warehouseModel->delete($warehouseId);

        if ($ok) {
            Logger::log($actorId, 'DELETE_WAREHOUSE', 'warehouses', $warehouseId);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Warehouse deleted.' : 'Cannot delete: this warehouse still has stock or is referenced.',
        ];
    }

    // =====================================================================
    // 4. CATEGORY & REORDER RULE CONFIGURATION (FR-ADM-04, BR-16)
    // =====================================================================

    public function listReorderRules(): array
    {
        return $this->productModel->getAllReorderRules();
    }

    public function getReorderRule(int $ruleId): array|false
    {
        return $this->productModel->getReorderRuleById($ruleId);
    }

    /**
     * BR-16: chỉ Admin cấu hình reorder_point/min/max/safety_stock. Validate
     * tính hợp lý của các mốc TRƯỚC khi xuống Model, vì DB không có CHECK ràng
     * buộc thứ tự safety_stock <= reorder_point <= max_stock giữa các cột này.
     *
     * $target: ['category_id'=>int] HOẶC ['product_id'=>int] - đúng 1 trong 2.
     * $data:   ['min_stock','max_stock','safety_stock','reorder_point']
     */
    public function configureReorderRule(array $target, array $data, int $actorId): array
    {
        foreach (['min_stock', 'max_stock', 'safety_stock', 'reorder_point'] as $field) {
            if (!isset($data[$field]) || (int) $data[$field] < 0) {
                return ['success' => false, 'message' => "Value '{$field}' must be a non-negative integer."];
            }
        }

        $minStock     = (int) $data['min_stock'];
        $maxStock     = (int) $data['max_stock'];
        $safetyStock  = (int) $data['safety_stock'];
        $reorderPoint = (int) $data['reorder_point'];

        if ($maxStock < $minStock) {
            return ['success' => false, 'message' => 'max_stock must be greater than or equal to min_stock.'];
        }
        if ($reorderPoint < $safetyStock) {
            return ['success' => false, 'message' => 'reorder_point must be greater than or equal to safety_stock.'];
        }
        if ($reorderPoint > $maxStock) {
            return ['success' => false, 'message' => 'reorder_point cannot exceed max_stock.'];
        }

        return $this->productModel->upsertReorderRule(
            $target,
            [
                'min_stock'     => $minStock,
                'max_stock'     => $maxStock,
                'safety_stock'  => $safetyStock,
                'reorder_point' => $reorderPoint,
            ],
            $actorId
        );
        // Product::upsertReorderRule() đã tự ghi Logger::log('UPDATE_REORDER_RULE')
        // bên trong Model - Service không log lại để tránh trùng audit log.
    }

    // =====================================================================
    // 5. SYSTEM CONFIGURATION (alert levels, api_configs)
    // =====================================================================
    // ⚠️ GHI CHÚ CẦN NHÓM XÁC NHẬN: bảng `api_configs` (config_id, api_name,
    // endpoint_url, api_key, configured_by) đã có trong db.sql nhưng CHƯA có
    // Model riêng (không có ApiConfig.php trong backend/models/). Vì Phase 3
    // (Models) coi như đã đóng, Service ở đây tạm thời query PDO trực tiếp qua
    // Database::getConnection() cho phần này - nếu nhóm muốn đúng chuẩn tách
    // lớp (Model/Service riêng biệt) như các bảng khác, nên bổ sung
    // backend/models/ApiConfig.php ở Phase 3.5 rồi thay lại các hàm bên dưới
    // để gọi qua Model thay vì PDO trực tiếp.

    /** FR-SYS-04 / System Configuration: xem cấu hình API hiện tại (VD: Forecast API endpoint). */
    public function listApiConfigs(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT ac.config_id, ac.api_name, ac.endpoint_url, ac.configured_by, a.full_name AS configured_by_name
             FROM api_configs ac
             LEFT JOIN accounts a ON a.account_id = ac.configured_by
             ORDER BY ac.api_name ASC"
        );
        return $stmt->fetchAll();
        // Lưu ý: KHÔNG select api_key ra ngoài danh sách để tránh lộ secret lên UI listing.
    }

    /**
     * Cấu hình/tạo mới 1 API config (VD: Demand Forecast API). Nếu đã tồn tại
     * config cùng api_name thì cập nhật đè, ngược lại tạo mới - giữ 1 api_name
     * chỉ có đúng 1 dòng cấu hình hiệu lực tại một thời điểm.
     */
    public function upsertApiConfig(string $apiName, string $endpointUrl, string $apiKey, int $actorId): array
    {
        $apiName     = trim($apiName);
        $endpointUrl = trim($endpointUrl);

        if ($apiName === '' || $endpointUrl === '' || trim($apiKey) === '') {
            return ['success' => false, 'message' => 'API name, endpoint and API key cannot be empty.'];
        }

        try {
            $pdo = Database::getConnection();

            $existing = $pdo->prepare("SELECT config_id FROM api_configs WHERE api_name = :api_name");
            $existing->execute([':api_name' => $apiName]);
            $row = $existing->fetch();

            if ($row) {
                $stmt = $pdo->prepare(
                    "UPDATE api_configs
                     SET endpoint_url = :endpoint_url, api_key = :api_key, configured_by = :configured_by
                     WHERE config_id = :id"
                );
                $stmt->execute([
                    ':endpoint_url'  => $endpointUrl,
                    ':api_key'       => $apiKey,
                    ':configured_by' => $actorId,
                    ':id'            => $row['config_id'],
                ]);
                $configId = (int) $row['config_id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO api_configs (api_name, endpoint_url, api_key, configured_by)
                     VALUES (:api_name, :endpoint_url, :api_key, :configured_by)"
                );
                $stmt->execute([
                    ':api_name'      => $apiName,
                    ':endpoint_url'  => $endpointUrl,
                    ':api_key'       => $apiKey,
                    ':configured_by' => $actorId,
                ]);
                $configId = (int) $pdo->lastInsertId();
            }

            Logger::log($actorId, 'UPDATE_API_CONFIG', 'api_configs', $configId);

            return ['success' => true, 'config_id' => $configId, 'message' => 'API configuration saved.'];
        } catch (PDOException $e) {
            error_log('[AdminService::upsertApiConfig] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while saving the API configuration.'];
        }
    }

    // =====================================================================
    // 6. PURCHASE ORDER APPROVAL (FR-ADM-06, BR-07, BR-20)
    // =====================================================================
    // Business logic PO approval SỐNG Ở ĐÂY (Service), không phải ở UI page -
    // đúng ghi chú trong docblock gốc của file này. Order::approve()/reject()
    // đã tự kiểm tra status='Pending' và tự ghi Logger, Service chỉ điều phối.

    /** FR-ADM-06: danh sách PO đang chờ duyệt, kèm chi tiết dòng sản phẩm để Admin xem trước khi quyết định. */
    public function listPendingApprovals(): array
    {
        $orders = $this->orderModel->getPendingApproval();

        foreach ($orders as &$po) {
            $po['details'] = $this->orderModel->getDetails((int) $po['po_id']);
        }
        unset($po);

        return $orders;
    }

    /**
     * FR-ADM-06 / BR-07: duyệt 1 PO - Pending -> Approved. Sau bước này PO mới
     * được coi là "đã gửi nhà cung cấp" về mặt nghiệp vụ (BR-07: PO chỉ được
     * gửi NCC sau khi Admin duyệt). Việc gọi API/gửi email thông báo NCC thực
     * tế thuộc về IntegrationService (Phase sau) - AdminService chỉ đổi status.
     */
    public function approvePurchaseOrder(int $poId, int $actorId): array
    {
        return $this->orderModel->approve($poId, $actorId);
    }

    /**
     * FR-ADM-06 / BR-20: từ chối 1 PO - Pending -> Rejected. Theo đúng thiết kế
     * của Order::reject() (đã ghi rõ trong Model), PO bị reject KHÔNG tự mở khóa
     * để Manager sửa lại - Manager phải tạo Draft mới nếu muốn gửi lại.
     */
    public function rejectPurchaseOrder(int $poId, string $reason, int $actorId): array
    {
        if (trim($reason) === '') {
            return ['success' => false, 'message' => 'Please enter a reason for rejecting the order.'];
        }

        $result = $this->orderModel->reject($poId, $actorId);

        // Order::reject() chỉ nhận (poId, rejectedBy) và không có cột lưu lý do
        // từ chối trên purchase_orders - ghi lý do vào audit log riêng để không
        // mất thông tin (⚠️ nếu cần hiển thị lý do từ chối lại cho Manager trên
        // UI, cân nhắc bổ sung cột rejection_reason vào purchase_orders ở Phase 1).
        if ($result['success']) {
            Logger::log($actorId, 'REJECT_PO_REASON: ' . $reason, 'purchase_orders', $poId);
        }

        return $result;
    }

    public function getPurchaseOrderDetail(int $poId): array|false
    {
        $po = $this->orderModel->getById($poId);
        if ($po === false) {
            return false;
        }

        $po['details'] = $this->orderModel->getDetails($poId);
        return $po;
    }

    public function listPurchaseOrders(?string $status = null): array
    {
        return $this->orderModel->getAll($status);
    }

    // =====================================================================
    // 7. AUDIT & ACTIVITY LOGS (FR-ADM-07)
    // =====================================================================
    // ⚠️ GHI CHÚ: chưa có AuditLog.php Model riêng (tương tự api_configs ở trên).
    // Query trực tiếp qua PDO tại đây; nên tách ra Model riêng nếu Phase 3 mở lại.

    /**
     * FR-ADM-07: xem audit log toàn hệ thống, filter theo user/loại hành động/khoảng thời gian.
     *
     * @param array $filters ['account_id'=>int?, 'action_type'=>string?, 'from_date'=>string?, 'to_date'=>string?]
     */
    public function getAuditLogs(array $filters = []): array
    {
        $sql = "SELECT al.log_id, al.account_id, a.full_name AS account_name, al.action_type,
                       al.target_table, al.target_id, al.timestamp
                FROM audit_logs al
                JOIN accounts a ON a.account_id = al.account_id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['account_id'])) {
            $sql .= " AND al.account_id = :account_id";
            $params[':account_id'] = (int) $filters['account_id'];
        }
        if (!empty($filters['action_type'])) {
            $sql .= " AND al.action_type LIKE :action_type";
            $params[':action_type'] = '%' . $filters['action_type'] . '%';
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND al.timestamp >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND al.timestamp <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY al.timestamp DESC";

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =====================================================================
    // 7B. SYSTEM OVERVIEW / DASHBOARD SUMMARY (FR-ADM-01, FR-ADM-06, FR-ADM-07,
    //     FR-SYS-02, FR-MGR-12 dùng chung khái niệm "low stock")
    // =====================================================================
    // ⚠️ GHI CHÚ: các con số ở đây là AGGREGATE (COUNT/SUM) đơn thuần, không có
    // Model riêng nào expose sẵn — Product/Account/Supplier/Order Model hiện tại
    // chỉ có getAll() (trả full rows). Query trực tiếp qua PDO tại đây để tránh
    // phải SELECT full rows rồi count() ở PHP (tốn bộ nhớ không cần thiết), giống
    // đúng pattern getAuditLogs() ở trên (query thẳng, không qua Model).

    /**
     * FR-ADM-01/06/07: tổng hợp số liệu cho trang "System Overview" (Admin Dashboard).
     * Gồm: KPI đếm nhanh (users/products/suppliers/pending PO), danh sách cảnh báo
     * tồn kho thấp (BR-04: stock <= reorder_point) ưu tiên theo doanh số gần nhất
     * (BR-13), PO đang chờ duyệt (rút gọn, không kèm chi tiết dòng - dùng cho card
     * tóm tắt, khác với listPendingApprovals() ở trên vốn kèm details() đầy đủ để
     * duyệt), và hoạt động gần đây (audit log mới nhất).
     *
     * @return array{
     *   kpi: array{active_users:int, total_products:int, total_suppliers:int, pending_pos:int, transactions_30d:int, deltas: array, trends: array},
     *   performance: array{stockout_rate:float, inventory_value:float, avg_approval_hours:?float, approval_success_rate:?float, rejection_rate:?float},
     *   low_stock_alerts: array,
     *   pending_orders: array,
     *   recent_activity: array,
     *   activity_trend_7d: array,
     *   product_mix: array,
     *   po_workflow: array
     * }
     */
    public function getSystemSummary(): array
    {
        $pdo = Database::getConnection();

        // --- KPI đếm nhanh ---
        $activeUsers    = (int) $pdo->query(
            "SELECT COUNT(*) FROM accounts WHERE status = 'active'"
        )->fetchColumn();

        $totalProducts  = (int) $pdo->query(
            "SELECT COUNT(*) FROM products WHERE is_active = 1"
        )->fetchColumn();

        $totalSuppliers = (int) $pdo->query(
            "SELECT COUNT(*) FROM suppliers"
        )->fetchColumn();

        $pendingPos     = (int) $pdo->query(
            "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'"
        )->fetchColumn();

        // "Transactions" = số phiếu bán hàng (sales_transactions) trong 30 ngày gần nhất -
        // dùng làm chỉ số hoạt động bán hàng tổng quan trên dashboard.
        $transactions30d = (int) $pdo->query(
            "SELECT COUNT(*) FROM sales_transactions WHERE transaction_time >= (NOW() - INTERVAL 30 DAY)"
        )->fetchColumn();

        // --- BR-04 / BR-13: sản phẩm chạm/dưới Reorder Point, ưu tiên sản phẩm bán
        // chạy (sales_volume 30 ngày gần nhất) lên đầu danh sách cảnh báo. Reorder
        // point lấy theo rule riêng của product nếu có, fallback về rule theo category
        // (đúng thiết kế "effective rule" của Product::getEffectiveReorderRule(), viết
        // lại thành 1 câu SQL duy nhất ở đây để không phải loop N+1 query theo từng sản phẩm).
        //
        // ⚠️ FIX #2 (2026-07): FIX #1 (bọc thành subquery "ranked") vẫn còn lỗi
        // 1052 "Column 'reorder_point' is ambiguous in on clause" trên môi
        // trường thực tế (MySQL/MariaDB agressive derived-table merge): khi 2
        // subquery LEFT JOIN (rule_product, rule_category) cùng SELECT từ 1
        // bảng gốc (reorder_rules) với CÙNG tên cột, optimizer merge/inline cả
        // 2 subquery thành 2 lần JOIN trực tiếp vào reorder_rules, rồi tự sinh
        // ON clause tham chiếu "reorder_point" không rõ instance nào.
        // Cách sửa dứt điểm: gộp product-rule và category-rule thành 1 JOIN
        // DUY NHẤT vào reorder_rules (không self-join 2 lần), dùng điều kiện
        // OR để khớp theo product_id trước, category_id sau - loại bỏ hoàn
        // toàn khả năng optimizer merge sai vì giờ chỉ còn 1 JOIN vào bảng đó.
        // Ưu tiên rule theo product (rr.product_id = p.product_id) hơn rule
        // theo category (rr.category_id = p.category_id) được xử lý bằng
        // ORDER BY + LIMIT 1 trong correlated subquery riêng cho từng sản phẩm.
        $lowStockSql = "
            SELECT * FROM (
                SELECT
                    p.product_id, p.sku_code, p.product_name,
                    COALESCE(SUM(st.quantity_on_hand), 0) AS current_stock,
                    COALESCE((
                        SELECT rr.reorder_point FROM reorder_rules rr
                        WHERE rr.product_id = p.product_id
                        LIMIT 1
                    ), (
                        SELECT rr2.reorder_point FROM reorder_rules rr2
                        WHERE rr2.category_id = p.category_id AND rr2.product_id IS NULL
                        LIMIT 1
                    ), 0) AS reorder_point,
                    COALESCE((
                        SELECT rr.safety_stock FROM reorder_rules rr
                        WHERE rr.product_id = p.product_id
                        LIMIT 1
                    ), (
                        SELECT rr2.safety_stock FROM reorder_rules rr2
                        WHERE rr2.category_id = p.category_id AND rr2.product_id IS NULL
                        LIMIT 1
                    ), 0) AS safety_stock,
                    COALESCE(sold.qty_sold_30d, 0) AS qty_sold_30d
                FROM products p
                LEFT JOIN stock st ON st.product_id = p.product_id
                LEFT JOIN (
                    SELECT std.product_id, SUM(std.quantity_sold) AS qty_sold_30d
                    FROM sales_transaction_details std
                    JOIN sales_transactions t ON t.transaction_id = std.transaction_id
                    WHERE t.transaction_time >= (NOW() - INTERVAL 30 DAY)
                    GROUP BY std.product_id
                ) AS sold ON sold.product_id = p.product_id
                WHERE p.is_active = 1
                GROUP BY p.product_id, p.sku_code, p.product_name, p.category_id, sold.qty_sold_30d
            ) AS ranked
            WHERE ranked.current_stock <= ranked.reorder_point
            ORDER BY ranked.qty_sold_30d DESC, ranked.current_stock ASC
            LIMIT 8";

        $lowStockAlerts = $pdo->query($lowStockSql)->fetchAll();

        // --- FR-ADM-06: PO đang chờ duyệt, bản rút gọn (không kèm details dòng)
        // cho card tóm tắt trên dashboard. Muốn xem đầy đủ dòng sản phẩm để duyệt
        // -> dùng listPendingApprovals() ở trang PO Approval riêng.
        $pendingOrders = $this->orderModel->getPendingApproval();
        $pendingOrders = array_slice($pendingOrders, 0, 6);

        // --- FR-ADM-07: hoạt động gần đây (audit log mới nhất) ---
        $recentActivity = $this->getAuditLogs();
        $recentActivity = array_slice($recentActivity, 0, 6);

        // --- KPI card "phụ liệu" (sparkline HOẶC delta), lấy dữ liệu thật
        // theo ĐÚNG bản chất biến động của mỗi KPI:
        //   - active_users / total_products / total_suppliers: các sự kiện
        //     "tạo mới"/"đăng nhập" này hiếm khi xảy ra ĐỀU ĐẶN mỗi ngày (vs
        //     transactions/PO vốn phát sinh liên tục) -> sparkline 7 điểm
        //     thường ra đường phẳng (0 suốt tuần), không có giá trị hiển thị.
        //     Thay bằng DELTA: tổng số sự kiện trong 7 ngày qua (đủ để biết
        //     "tuần này có gì thay đổi không" mà không vẽ chart giả).
        //   - pending_pos / transactions: phát sinh tự nhiên hàng ngày trong
        //     vận hành cửa hàng -> giữ sparkline (có ý nghĩa thống kê thật).
        $activeUsersLoginsThisWeek     = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'LOGIN' AND timestamp >= (CURDATE() - INTERVAL 6 DAY)"
        )->fetchColumn();
        $totalProductsCreatedThisWeek  = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'CREATE_PRODUCT' AND timestamp >= (CURDATE() - INTERVAL 6 DAY)"
        )->fetchColumn();
        $totalSuppliersCreatedThisWeek = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action_type = 'CREATE_SUPPLIER' AND timestamp >= (CURDATE() - INTERVAL 6 DAY)"
        )->fetchColumn();

        $pendingPosTrend   = $this->getDailyCountTrend($pdo, "SELECT DATE(created_at), COUNT(*) FROM purchase_orders WHERE created_at >= (CURDATE() - INTERVAL 6 DAY) GROUP BY DATE(created_at)");
        $transactionsTrend = $this->getDailyCountTrend($pdo, "SELECT DATE(transaction_time), COUNT(*) FROM sales_transactions WHERE transaction_time >= (CURDATE() - INTERVAL 6 DAY) GROUP BY DATE(transaction_time)");

        // "User Activity Trend" (chart lớn ở giữa trang, KHÁC với sparkline
        // trong từng KPI card) vẫn dùng TOÀN BỘ audit_logs làm thước đo hoạt
        // động hệ thống nói chung (không giới hạn action_type nào) - đây vẫn
        // là lựa chọn đúng cho 1 chart tổng quan, khác với sparkline của
        // từng KPI card vốn PHẢI khớp đúng ý nghĩa của riêng KPI đó.
        $activityTrend7d = $this->getDailyCountTrend($pdo, "SELECT DATE(timestamp), COUNT(*) FROM audit_logs WHERE timestamp >= (CURDATE() - INTERVAL 6 DAY) GROUP BY DATE(timestamp)");

        // --- "Product Mix": tỷ trọng số SKU active theo từng category, dùng
        // cho donut chart. category_name lấy trực tiếp từ bảng categories
        // (KHÔNG bịa nhãn "Beverages/Snacks/Other" cố định như mockup, vì
        // dữ liệu thật có thể có category khác - hiển thị đúng category thật).
        $productMixRaw = $pdo->query("
            SELECT c.category_name, COUNT(p.product_id) AS sku_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.category_id AND p.is_active = 1
            GROUP BY c.category_id, c.category_name
            HAVING sku_count > 0
            ORDER BY sku_count DESC
        ")->fetchAll();

        // Donut chỉ đọc được với ít lát cắt - với cửa hàng có nhiều category
        // (10+), vẽ hết tất cả khiến mỗi lát quá mỏng để phân biệt và legend
        // tràn dài mất kiểm soát. Giữ PRODUCT_MIX_TOP_N category lớn nhất,
        // gộp phần còn lại thành 1 lát "Khác" - vẫn phản ánh đúng 100% tổng số
        // SKU, chỉ gộp nhãn hiển thị chứ không bỏ sót dữ liệu.
        $productMix = [];
        if ($totalProducts > 0) {
            $topRows = array_slice($productMixRaw, 0, PRODUCT_MIX_TOP_N);
            $otherRows = array_slice($productMixRaw, PRODUCT_MIX_TOP_N);

            foreach ($topRows as $row) {
                $productMix[] = [
                    'category_name' => $row['category_name'],
                    'sku_count'     => (int) $row['sku_count'],
                    'percentage'    => round(((int) $row['sku_count'] / $totalProducts) * 100, 1),
                ];
            }

            if (!empty($otherRows)) {
                $otherSkuCount = array_sum(array_map(fn($r) => (int) $r['sku_count'], $otherRows));
                $productMix[] = [
                    'category_name' => 'Other (' . count($otherRows) . ' groups)',
                    'sku_count'     => $otherSkuCount,
                    'percentage'    => round(($otherSkuCount / $totalProducts) * 100, 1),
                ];
            }
        }

        // --- "Purchase Order Workflow": đếm PO theo từng trạng thái thật của
        // schema (Draft, Pending, Approved, Rejected, Delivered - KHÔNG có
        // "Shipped"/"Closed" vì cột status trong db.sql không có 2 giá trị
        // này, xem CREATE TABLE purchase_orders).
        $poStatusRaw = $pdo->query("
            SELECT status, COUNT(*) AS total FROM purchase_orders GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $poWorkflow = [];
        foreach (['Draft', 'Pending', 'Approved', 'Delivered', 'Rejected'] as $status) {
            $poWorkflow[] = [
                'status' => $status,
                'count'  => (int) ($poStatusRaw[$status] ?? 0),
            ];
        }

        // --- "Performance Comparison": CHỈ 2 chỉ số có dữ liệu thật để tính -
        // Stock-out Rate (tỷ lệ SKU active đang = 0 tồn kho) và Inventory
        // Value (SUM stock * unit_cost). KHÔNG thêm "Forecast Error (MAPE)"
        // hay "Inventory Turnover" như mockup vì hệ thống hiện KHÔNG lưu
        // lịch sử forecast-vs-actual hay COGS theo kỳ - thêm 2 chỉ số đó sẽ
        // phải bịa số, không làm.
        $stockoutRateRow = $pdo->query("
            SELECT
                COUNT(*) AS total_active,
                SUM(CASE WHEN COALESCE(st.total_qty, 0) = 0 THEN 1 ELSE 0 END) AS stockout_count
            FROM products p
            LEFT JOIN (
                SELECT product_id, SUM(quantity_on_hand) AS total_qty FROM stock GROUP BY product_id
            ) st ON st.product_id = p.product_id
            WHERE p.is_active = 1
        ")->fetch();

        $stockoutRate = ((int) $stockoutRateRow['total_active']) > 0
            ? round(((int) $stockoutRateRow['stockout_count'] / (int) $stockoutRateRow['total_active']) * 100, 1)
            : 0.0;

        $inventoryValue = (float) $pdo->query("
            SELECT COALESCE(SUM(s.quantity_on_hand * p.unit_cost), 0)
            FROM stock s
            JOIN products p ON p.product_id = s.product_id
        ")->fetchColumn();

        // --- PO approval performance (Admin duyệt/từ chối) - dùng created_at/
        // approved_at đã có sẵn trên purchase_orders, không cần thêm cột nào.
        $avgApprovalHours = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, approved_at)) / 3600
            FROM purchase_orders
            WHERE status IN ('Approved', 'Rejected') AND approved_at IS NOT NULL
        ")->fetchColumn();
        $avgApprovalHours = $avgApprovalHours !== false && $avgApprovalHours !== null ? round((float) $avgApprovalHours, 1) : null;

        $poDecisionCounts = $pdo->query("
            SELECT status, COUNT(*) AS total FROM purchase_orders
            WHERE status IN ('Approved', 'Rejected') GROUP BY status
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalDecided = array_sum($poDecisionCounts);
        $approvalSuccessRate = $totalDecided > 0 ? round((($poDecisionCounts['Approved'] ?? 0) / $totalDecided) * 100, 1) : null;
        $rejectionRate       = $totalDecided > 0 ? round((($poDecisionCounts['Rejected'] ?? 0) / $totalDecided) * 100, 1) : null;

        return [
            'kpi' => [
                'active_users'     => $activeUsers,
                'total_products'   => $totalProducts,
                'total_suppliers'  => $totalSuppliers,
                'pending_pos'      => $pendingPos,
                'transactions_30d' => $transactions30d,
                // Delta 7 ngày qua - dùng cho card KHÔNG có sparkline (biến
                // động không đều đặn hàng ngày).
                'deltas' => [
                    'active_users'    => $activeUsersLoginsThisWeek,
                    'total_products'  => $totalProductsCreatedThisWeek,
                    'total_suppliers' => $totalSuppliersCreatedThisWeek,
                ],
                // Sparkline 7 ngày - chỉ cho card có hoạt động phát sinh tự
                // nhiên hàng ngày (PO, giao dịch bán hàng).
                'trends' => [
                    'pending_pos'      => $pendingPosTrend,
                    'transactions_30d' => $transactionsTrend,
                ],
            ],
            'performance' => [
                'stockout_rate'          => $stockoutRate,
                'inventory_value'        => $inventoryValue,
                'avg_approval_hours'     => $avgApprovalHours,
                'approval_success_rate'  => $approvalSuccessRate,
                'rejection_rate'         => $rejectionRate,
            ],
            'low_stock_alerts'  => $lowStockAlerts,
            'pending_orders'    => $pendingOrders,
            'recent_activity'   => $recentActivity,
            'activity_trend_7d' => $activityTrend7d,
            'product_mix'       => $productMix,
            'po_workflow'       => $poWorkflow,
        ];
    }

    /**
     * Helper dùng chung cho MỌI sparkline/trend-chart 7 ngày trên dashboard -
     * chạy 1 câu SQL bất kỳ có dạng "SELECT DATE(cột_thời_gian), COUNT(*) ...
     * GROUP BY DATE(cột_thời_gian)" (2 cột: ngày, số đếm), rồi tự điền đủ 7
     * ngày liên tục kể cả ngày không có dữ liệu (count = 0) - đảm bảo mọi
     * chart luôn có đúng 7 điểm, không bị "gãy" khi có ngày trống.
     *
     * ⚠️ $sql PHẢI là câu query TĨNH do code nội bộ định nghĩa (không bao giờ
     * nhận trực tiếp từ input người dùng) - các lời gọi hàm này trong
     * getSystemSummary() đều hardcode sẵn, không có tham số nào từ ngoài lọt
     * vào chuỗi SQL, nên không có rủi ro SQL Injection dù không dùng prepared
     * statement ở đây.
     *
     * @return array<int, array{date: string, label: string, count: int}> Đúng 7 phần tử, cũ -> mới.
     */
    private function getDailyCountTrend(PDO $pdo, string $sql): array
    {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} day"));
            $trend[] = [
                'date'  => $date,
                'label' => date('D', strtotime($date)), // Mon, Tue, ...
                'count' => (int) ($rows[$date] ?? 0),
            ];
        }

        return $trend;
    }

    // =====================================================================
    // 8. KPI COMPARISON REPORT (FR-ADM-08)
    // =====================================================================
    // ⚠️ GHI CHÚ QUAN TRỌNG: 3 chỉ số yêu cầu (stock-out rate, turnover, forecast
    // error) tính với data hiện có như sau:
    //   - stock-out rate: ước lượng gián tiếp qua shortage_incidents (chưa có
    //     bảng đo trực tiếp số lần chạm 0 tồn kho).
    //   - turnover (vòng quay tồn kho): products.unit_cost (giá NHẬP/giá vốn)
    //     đã có trong schema -> tính được COGS = SUM(quantity_sold × unit_cost)
    //     đúng chuẩn kế toán (turnover luôn dùng giá VỐN, không dùng giá bán,
    //     nên KHÔNG cần cột giá bán lẻ để tính chỉ số này).
    //   - forecast error: so sánh demand_forecasts.suggested_qty với sales thực
    //     tế SAU đó cùng kỳ - tính theo SỐ LƯỢNG (MAE), vì demand_forecasts
    //     cũng không lưu giá trị tiền, chỉ lưu suggested_qty.
    // Hàm dưới đây trả về approximation tốt nhất có thể với data hiện tại, kèm
    // ghi chú rõ trong kết quả trả về.

    /**
     * FR-ADM-08: so sánh KPI giữa 2 khoảng thời gian.
     *
     * @param string $period1From Y-m-d
     * @param string $period1To   Y-m-d
     * @param string $period2From Y-m-d
     * @param string $period2To   Y-m-d
     */
    public function getKpiComparison(
        string $period1From,
        string $period1To,
        string $period2From,
        string $period2To
    ): array {
        return [
            'period_1' => $this->calculateKpisForPeriod($period1From, $period1To),
            'period_2' => $this->calculateKpisForPeriod($period2From, $period2To),
            'note'     => 'Turnover is calculated by VALUE (COGS = quantity sold × unit_cost) / current average '
                        . 'stock value. Forecast MAE is still by quantity because demand_forecasts '
                        . 'does not store monetary value.',
        ];
    }

    private function calculateKpisForPeriod(string $fromDate, string $toDate): array
    {
        $pdo = Database::getConnection();

        // Stock-out rate xấp xỉ: tỉ lệ sự cố thiếu hàng (shortage_incidents) trên
        // tổng số sản phẩm active trong kỳ - proxy hợp lý nhất với data hiện có.
        $shortageStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT product_id) AS products_with_shortage
             FROM shortage_incidents
             WHERE created_at BETWEEN :from_date AND :to_date"
        );
        $shortageStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $shortageCount = (int) $shortageStmt->fetch()['products_with_shortage'];

        $totalActiveStmt = $pdo->query("SELECT COUNT(*) AS total FROM products WHERE is_active = 1");
        $totalActive = (int) $totalActiveStmt->fetch()['total'];

        $stockoutRate = $totalActive > 0 ? round(($shortageCount / $totalActive) * 100, 1) : 0.0;

        // Turnover theo GIÁ TRỊ: COGS (SL bán × unit_cost mỗi SP) / giá trị tồn
        // kho hiện tại (SL tồn × unit_cost) - không có snapshot tồn kho đầu/cuối
        // kỳ trong schema, nên dùng giá trị tồn kho hiện tại làm mẫu số tham
        // khảo (không phải turnover chuẩn kế toán dùng tồn kho trung bình đầu-cuối kỳ).
        $cogsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(std.quantity_sold * p.unit_cost), 0) AS total_cogs
             FROM sales_transaction_details std
             JOIN sales_transactions st ON st.transaction_id = std.transaction_id
             JOIN products p ON p.product_id = std.product_id
             WHERE st.transaction_time BETWEEN :from_date AND :to_date"
        );
        $cogsStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $totalCogs = (float) $cogsStmt->fetch()['total_cogs'];

        $currentStockValueStmt = $pdo->query(
            "SELECT COALESCE(SUM(s.quantity_on_hand * p.unit_cost), 0) AS total_stock_value
             FROM stock s
             JOIN products p ON p.product_id = s.product_id"
        );
        $currentStockValue = (float) $currentStockValueStmt->fetch()['total_stock_value'];

        $turnoverRatio = $currentStockValue > 0 ? round($totalCogs / $currentStockValue, 2) : 0.0;

        // Forecast error: so sánh demand_forecasts.suggested_qty (api_status='success')
        // với tổng thực bán của cùng sản phẩm trong kỳ - MAE đơn giản theo số lượng.
        $forecastStmt = $pdo->prepare(
            "SELECT df.product_id, df.suggested_qty,
                    COALESCE((
                        SELECT SUM(std.quantity_sold)
                        FROM sales_transaction_details std
                        JOIN sales_transactions st ON st.transaction_id = std.transaction_id
                        WHERE std.product_id = df.product_id
                          AND st.transaction_time BETWEEN :from_date AND :to_date
                    ), 0) AS actual_sold
             FROM demand_forecasts df
             WHERE df.api_status = 'success'
               AND df.requested_at BETWEEN :from_date2 AND :to_date2"
        );
        $forecastStmt->execute([
            ':from_date'  => $fromDate,
            ':to_date'    => $toDate,
            ':from_date2' => $fromDate,
            ':to_date2'   => $toDate,
        ]);
        $forecastRows = $forecastStmt->fetchAll();

        $forecastError = null;
        if (!empty($forecastRows)) {
            $totalAbsError = 0;
            foreach ($forecastRows as $row) {
                $totalAbsError += abs((int) $row['suggested_qty'] - (int) $row['actual_sold']);
            }
            $forecastError = round($totalAbsError / count($forecastRows), 2);
        }

        return [
            'from_date'             => $fromDate,
            'to_date'               => $toDate,
            'stockout_rate_percent' => $stockoutRate,
            'turnover_ratio'        => $turnoverRatio,
            'forecast_mae'          => $forecastError, // null nếu kỳ đó không có forecast nào api_status='success'
        ];
    }

    // =====================================================================
    // 8B. INVENTORY OVERVIEW (FR-ADM-01, FR-ADM-05, BR-04)
    // =====================================================================
    // Dữ liệu phục vụ frontend/admin/inventory/inventory_overview.php -
    // danh sách sản phẩm kèm tồn kho tổng hợp, ngưỡng reorder/safety hiệu
    // lực, và KPI snapshot (tổng SKU/NCC/kho/inactive/low stock).

    /**
     * FR-ADM-01: danh sách sản phẩm kèm tồn kho tổng (SUM quantity_on_hand),
     * ngưỡng Safety/Reorder hiệu lực (product rule ưu tiên hơn category rule),
     * kho chính (kho có SL tồn cao nhất) và số kho đang giữ hàng.
     *
     * @return array<int, array{
     *   product_id:int, sku_code:string, product_name:string, category_name:string,
     *   is_active:int, current_stock:int, reorder_point:int, safety_stock:int,
     *   unit_cost:float, selling_price:float,
     *   primary_warehouse_name:?string, warehouse_count:int
     * }>
     */
    public function getInventoryStockOverview(
        bool $includeInactive = false,
        ?int $categoryId = null,
        ?string $keyword = null
    ): array {
        $pdo = Database::getConnection();

        $sql = "
            SELECT
                p.product_id,
                p.sku_code,
                p.product_name,
                p.is_active,
                p.unit_cost,
                p.selling_price,
                c.category_name,
                COALESCE(SUM(st.quantity_on_hand), 0) AS current_stock,
                COALESCE((
                    SELECT rr.reorder_point FROM reorder_rules rr
                    WHERE rr.product_id = p.product_id
                    LIMIT 1
                ), (
                    SELECT rr2.reorder_point FROM reorder_rules rr2
                    WHERE rr2.category_id = p.category_id AND rr2.product_id IS NULL
                    LIMIT 1
                ), 0) AS reorder_point,
                COALESCE((
                    SELECT rr.safety_stock FROM reorder_rules rr
                    WHERE rr.product_id = p.product_id
                    LIMIT 1
                ), (
                    SELECT rr2.safety_stock FROM reorder_rules rr2
                    WHERE rr2.category_id = p.category_id AND rr2.product_id IS NULL
                    LIMIT 1
                ), 0) AS safety_stock,
                (
                    SELECT w.warehouse_name
                    FROM stock s2
                    JOIN warehouses w ON w.warehouse_id = s2.warehouse_id
                    WHERE s2.product_id = p.product_id AND s2.quantity_on_hand > 0
                    ORDER BY s2.quantity_on_hand DESC
                    LIMIT 1
                ) AS primary_warehouse_name,
                (
                    SELECT COUNT(DISTINCT s3.warehouse_id)
                    FROM stock s3
                    WHERE s3.product_id = p.product_id AND s3.quantity_on_hand > 0
                ) AS warehouse_count
            FROM products p
            JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN stock st ON st.product_id = p.product_id
            WHERE 1=1";

        $params = [];

        if (!$includeInactive) {
            $sql .= " AND p.is_active = 1";
        }

        if ($categoryId !== null) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        if ($keyword !== null && $keyword !== '') {
            $sql .= " AND (p.product_name LIKE :keyword OR p.sku_code LIKE :keyword)";
            $params[':keyword'] = '%' . $keyword . '%';
        }

        $sql .= "
            GROUP BY p.product_id, p.sku_code, p.product_name, p.is_active,
                     p.unit_cost, p.selling_price, p.category_id, c.category_name
            ORDER BY p.product_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * FR-ADM-01: KPI snapshot cho panel "Inventory Snapshot" trên Inventory Overview.
     *
     * @return array{
     *   total_products:int, total_suppliers:int, total_warehouses:int,
     *   inactive_count:int, low_stock_count:int
     * }
     */
    public function getInventoryOverviewSummary(): array
    {
        $pdo = Database::getConnection();

        $totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $totalSuppliers = (int) $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
        $totalWarehouses = (int) $pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
        $inactiveCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM products WHERE is_active = 0"
        )->fetchColumn();

        $lowStockCount = (int) $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT
                    p.product_id,
                    COALESCE(SUM(st.quantity_on_hand), 0) AS current_stock,
                    COALESCE((
                        SELECT rr.reorder_point FROM reorder_rules rr
                        WHERE rr.product_id = p.product_id
                        LIMIT 1
                    ), (
                        SELECT rr2.reorder_point FROM reorder_rules rr2
                        WHERE rr2.category_id = p.category_id AND rr2.product_id IS NULL
                        LIMIT 1
                    ), 0) AS reorder_point
                FROM products p
                LEFT JOIN stock st ON st.product_id = p.product_id
                WHERE p.is_active = 1
                GROUP BY p.product_id, p.category_id
            ) AS ranked
            WHERE ranked.current_stock <= ranked.reorder_point
        ")->fetchColumn();

        return [
            'total_products'   => $totalProducts,
            'total_suppliers'  => $totalSuppliers,
            'total_warehouses' => $totalWarehouses,
            'inactive_count'   => $inactiveCount,
            'low_stock_count'  => $lowStockCount,
        ];
    }

    // =====================================================================
    // 9. INVENTORY COUNT HISTORY (FR-ADM-09)
    // =====================================================================

    /** FR-ADM-09: lịch sử kiểm kê toàn hệ thống, kèm số dòng lệch mỗi phiên. */
    public function getInventoryCountHistory(): array
    {
        return $this->stockCountModel->getHistory();
    }

    public function getInventoryCountDetail(int $countId): array|false
    {
        return $this->stockCountModel->getSessionById($countId);
    }

    // =====================================================================
    // 10. DATA BACKUP / RESTORE (FR-ADM-10)
    // =====================================================================
    // ⚠️ GHI CHÚ HẠ TẦNG: mysqldump/mysql binary được gọi qua shell_exec() -
    // đây là hành động cấp hệ điều hành, không chỉ là 1 SQL query. Yêu cầu
    // để chạy được thật trên máy:
    //   1. PHP không bị chặn shell_exec() qua disable_functions trong php.ini
    //      (mặc định XAMPP KHÔNG chặn, nhưng 1 số shared hosting có chặn).
    //   2. Đường dẫn mysqldump.exe/mysql.exe đúng với BACKUP_MYSQL_BIN_DIR
    //      trong app_config.php (mặc định XAMPP Windows) - đổi hằng số đó
    //      nếu máy bạn cài khác chuẩn, KHÔNG sửa code trong Service này.
    //   3. Thư mục BACKUP_STORAGE_DIR (backend/storage/backups/) có quyền
    //      ghi cho user chạy PHP-CGI/Apache.
    // File backup được đặt tên theo timestamp để không tự ghi đè lẫn nhau.

    /**
     * FR-ADM-10: sao lưu toàn bộ CSDL ra file .sql qua mysqldump.
     * Ghi 1 dòng backup_history TRƯỚC khi chạy lệnh (status='running'), rồi
     * cập nhật lại kết quả thật (thành công/thất bại kèm stderr) sau khi
     * tiến trình kết thúc - đảm bảo có bản ghi audit trail kể cả khi
     * mysqldump bị crash/timeout giữa chừng.
     *
     * @return array{success: bool, message: string, backup_id?: int, file_size_bytes?: int}
     */
    public function backupDatabase(int $actorId): array
    {
        if (!is_dir(BACKUP_STORAGE_DIR) && !mkdir(BACKUP_STORAGE_DIR, 0755, true) && !is_dir(BACKUP_STORAGE_DIR)) {
            return ['success' => false, 'message' => 'Cannot create backup storage directory (' . BACKUP_STORAGE_DIR . ') - check write permissions.'];
        }

        try {
            $backupId = $this->backupHistoryModel->createRunning('full', $actorId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Không thể ghi lịch sử backup: ' . $e->getMessage()];
        }

        $fileName = 'backup_' . date('Y-m-d_His') . '.sql';
        $filePath = BACKUP_STORAGE_DIR . DIRECTORY_SEPARATOR . $fileName;

        $mysqldumpBin = BACKUP_MYSQL_BIN_DIR . 'mysqldump';
        $passArg = BACKUP_DB_PASS !== '' ? '-p' . escapeshellarg(BACKUP_DB_PASS) : '';

        // 2>&1 để bắt được cả stderr (thông báo lỗi thật của mysqldump) vào cùng output,
        // dùng để ghi lại error_message khi thất bại - không đoán mò lý do lỗi.
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s %s > %s 2> %s',
            escapeshellarg($mysqldumpBin),
            escapeshellarg(BACKUP_DB_HOST),
            escapeshellarg(BACKUP_DB_PORT),
            escapeshellarg(BACKUP_DB_USER),
            $passArg,
            escapeshellarg(BACKUP_DB_NAME),
            escapeshellarg($filePath),
            escapeshellarg($filePath . '.err')
        );

        exec($command, $outputLines, $exitCode);

        $stderr = file_exists($filePath . '.err') ? trim((string) file_get_contents($filePath . '.err')) : '';
        if (file_exists($filePath . '.err')) {
            unlink($filePath . '.err'); // file lỗi tạm chỉ dùng để đọc stderr, không cần giữ lại
        }

        // exit code 0 VÀ file .sql thực sự tồn tại + có nội dung mới coi là thành công -
        // không tin riêng exit code, vì 1 số bản mysqldump vẫn trả 0 dù output rỗng do lỗi quyền ghi file đích.
        $fileSizeBytes = file_exists($filePath) ? filesize($filePath) : false;
        $success = $exitCode === 0 && $fileSizeBytes !== false && $fileSizeBytes > 0;

        if (!$success && file_exists($filePath)) {
            unlink($filePath); // dọn file rỗng/lỗi, không để lại rác trong thư mục backup
        }

        $errorMessage = $success ? null : ($stderr !== '' ? $stderr : 'mysqldump finished with exit code ' . $exitCode . ' but no detailed error message.');

        $this->backupHistoryModel->markFinished(
            $backupId,
            $success,
            $success ? $filePath : null,
            $success ? (int) $fileSizeBytes : null,
            $errorMessage
        );

        if ($success) {
            Logger::log($actorId, 'CREATE_BACKUP', 'backup_history', $backupId);
            return [
                'success'         => true,
                'message'         => 'Database backup completed successfully.',
                'backup_id'       => $backupId,
                'file_size_bytes' => (int) $fileSizeBytes,
            ];
        }

        return [
            'success'   => false,
            'message'   => 'Backup failed: ' . $errorMessage,
            'backup_id' => $backupId,
        ];
    }

    /**
     * FR-ADM-10: phục hồi dữ liệu từ 1 file .sql đã backup trước đó qua mysql CLI.
     * BẮT BUỘC $sourceBackupId phải trỏ tới 1 dòng backup_history có
     * status='success' VÀ backup_type='full' - không cho phục hồi từ file
     * tùy ý người dùng tải lên (rủi ro command injection/restore nhầm file
     * rác), chỉ cho chọn trong số các bản backup THẬT đã tạo qua chính hệ
     * thống này.
     *
     * @return array{success: bool, message: string}
     */
    public function restoreDatabase(int $sourceBackupId, int $actorId): array
    {
        $source = $this->backupHistoryModel->getById($sourceBackupId);

        if ($source === false || $source['backup_type'] !== 'full' || $source['status'] !== 'success') {
            return ['success' => false, 'message' => 'Can only restore from a backup that was created successfully in the list.'];
        }

        $filePath = $source['file_path'];
        if ($filePath === null || !file_exists($filePath)) {
            return ['success' => false, 'message' => 'Backup file no longer exists on the server (it may have been manually deleted).'];
        }

        try {
            $restoreId = $this->backupHistoryModel->createRunning('restore', $actorId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Không thể ghi lịch sử restore: ' . $e->getMessage()];
        }

        $mysqlBin = BACKUP_MYSQL_BIN_DIR . 'mysql';
        $passArg = BACKUP_DB_PASS !== '' ? '-p' . escapeshellarg(BACKUP_DB_PASS) : '';
        $errFile = $filePath . '.restore.err';

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s %s < %s 2> %s',
            escapeshellarg($mysqlBin),
            escapeshellarg(BACKUP_DB_HOST),
            escapeshellarg(BACKUP_DB_PORT),
            escapeshellarg(BACKUP_DB_USER),
            $passArg,
            escapeshellarg(BACKUP_DB_NAME),
            escapeshellarg($filePath),
            escapeshellarg($errFile)
        );

        exec($command, $outputLines, $exitCode);

        $stderr = file_exists($errFile) ? trim((string) file_get_contents($errFile)) : '';
        if (file_exists($errFile)) {
            unlink($errFile);
        }

        $success = $exitCode === 0;
        $errorMessage = $success ? null : ($stderr !== '' ? $stderr : 'mysql (restore) finished with exit code ' . $exitCode);

        $this->backupHistoryModel->markFinished($restoreId, $success, $filePath, null, $errorMessage);

        if ($success) {
            Logger::log($actorId, 'RESTORE_BACKUP', 'backup_history', $sourceBackupId);
            return ['success' => true, 'message' => 'Database restored from backup #' . $sourceBackupId . '.'];
        }

        return ['success' => false, 'message' => 'Restore failed: ' . $errorMessage];
    }

    /** FR-ADM-10: lịch sử backup/restore cho bảng "Recent Backups" trên UI. */
    public function listBackupHistory(int $limit = 50): array
    {
        return $this->backupHistoryModel->getAll($limit);
    }

    /** FR-ADM-10: thống kê THẬT cho các KPI card (Latest Backup, Total Backups, Storage Used, Success Rate). */
    public function getBackupStats(): array
    {
        return $this->backupHistoryModel->getStats();
    }
}