<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class Order
{
    private PDO $conn;
    private string $table = 'purchase_orders';

    /* BR-20: chỉ các trạng thái này cho phép sửa qty/product của các dòng PO. */
    private const EDITABLE_STATUSES = ['Draft'];

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // purchase_orders - CRUD & truy vấn

    public function getById(int $poId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT po.po_id, po.supplier_id, s.supplier_name, po.created_by,
                    creator.full_name AS created_by_name, po.status, po.approved_by,
                    approver.full_name AS approved_by_name, po.created_at, po.approved_at
             FROM {$this->table} po
             JOIN suppliers s ON s.supplier_id = po.supplier_id
             JOIN accounts creator ON creator.account_id = po.created_by
             LEFT JOIN accounts approver ON approver.account_id = po.approved_by
             WHERE po.po_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $poId]);
        return $stmt->fetch();
    }

    /* FR-MGR-06 / FR-ADM-06: danh sách PO, lọc theo status/created_by nếu cần; total_amount = tổng giá trị NHẬP HÀNG của cả đơn (SUM approved_qty × unit_cost từng dòng) - đây là giá trị đặt hàng với NCC, KHÔNG phải doanh thu bán (schema chưa có giá bán lẻ, xem ghi chú trong Product.php). */
    public function getAll(?string $status = null, ?int $createdBy = null): array
    {
        $sql = "SELECT po.po_id, s.supplier_name, po.status, po.created_at, po.approved_at,
                       creator.full_name AS created_by_name,
                       (SELECT COALESCE(SUM(pod.approved_qty * p.unit_cost), 0)
                        FROM purchase_order_details pod
                        JOIN products p ON p.product_id = pod.product_id
                        WHERE pod.po_id = po.po_id) AS total_amount
                FROM {$this->table} po
                JOIN suppliers s ON s.supplier_id = po.supplier_id
                JOIN accounts creator ON creator.account_id = po.created_by
                WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND po.status = :status";
            $params[':status'] = $status;
        }
        if ($createdBy !== null) {
            $sql .= " AND po.created_by = :created_by";
            $params[':created_by'] = $createdBy;
        }

        $sql .= " ORDER BY po.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /* FR-ADM-06: danh sách PO đang chờ Admin duyệt. */
    public function getPendingApproval(): array
    {
        return $this->getAll('Pending');
    }

    /* FR-STF-05/07 / FR-ADM-06: chi tiết dòng PO, kèm unit_cost (giá nhập) và line_cost (approved_qty × unit_cost) đã tính sẵn.
     * Dùng để hiển thị/tính tổng "Amount" của cả đơn PO ở AdminService::getPurchaseOrderDetail() và PurchaseOrderService, tránh lặp phép tính ở nhiều nơi. */
    public function getDetails(int $poId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT pod.po_detail_id, pod.po_id, pod.product_id, p.product_name, p.sku_code,
                    p.unit_cost, c.category_name, pod.suggested_qty, pod.approved_qty, pod.received_qty,
                    pod.discrepancy_reason, (pod.approved_qty * p.unit_cost) AS line_cost
             FROM purchase_order_details pod
             JOIN products p ON p.product_id = pod.product_id
             JOIN categories c ON c.category_id = p.category_id
             WHERE pod.po_id = :po_id"
        );
        $stmt->execute([':po_id' => $poId]);
        return $stmt->fetchAll();
    }

    /* FR-MGR-04: Manager tạo PO mới ở trạng thái 'Draft', kèm chi tiết dòng SP.
     * $lines: [['product_id'=>int,'suggested_qty'=>int,'approved_qty'=>int], ...] approved_qty ở bước này là số lượng Manager đã override 
     * (BR-06); nếu không truyền, mặc định = suggested_qty. */
    public function createDraft(int $supplierId, int $createdBy, array $lines): array
    {
        if (empty($lines)) {
            return ['success' => false, 'message' => 'Purchase order must have at least 1 product line.'];
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table} (supplier_id, created_by, status, created_at)
                 VALUES (:supplier_id, :created_by, 'Draft', NOW())"
            );
            $stmt->execute([':supplier_id' => $supplierId, ':created_by' => $createdBy]);
            $poId = $this->conn->lastInsertId();

            $detailStmt = $this->conn->prepare(
                "INSERT INTO purchase_order_details (po_id, product_id, suggested_qty, approved_qty)
                 VALUES (:po_id, :product_id, :suggested_qty, :approved_qty)"
            );

            foreach ($lines as $line) {
                $detailStmt->execute([
                    ':po_id'         => $poId,
                    ':product_id'    => (int) $line['product_id'],
                    ':suggested_qty' => (int) $line['suggested_qty'],
                    ':approved_qty'  => (int) ($line['approved_qty'] ?? $line['suggested_qty']),
                ]);
            }

            $this->conn->commit();
            return ['success' => true, 'po_id' => $poId, 'message' => 'Purchase order created (Draft).'];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[Order::createDraft] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while creating the purchase order.'];
        }
    }

    /* BR-06: Manager chỉnh sửa approved_qty của 1 dòng PO - chỉ được phép khi PO còn 'Draft'.
     * Ghi Logger::log('OVERRIDE_PO_QTY') để lưu vết thay đổi (khớp mẫu đã có trong audit_logs seed data). */
    public function updateLineQuantity(int $poId, int $poDetailId, int $newQty, int $updatedBy): array
    {
        $po = $this->getById($poId);
        if ($po === false) {
            return ['success' => false, 'message' => 'Purchase order not found.'];
        }
        if (!in_array($po['status'], self::EDITABLE_STATUSES, true)) {
            return [
                'success' => false,
                'message' => "Cannot edit an order with status '{$po['status']}' (BR-20: submitted orders are locked).",
            ];
        }

        $stmt = $this->conn->prepare(
            "UPDATE purchase_order_details SET approved_qty = :qty
             WHERE po_detail_id = :detail_id AND po_id = :po_id"
        );
        $ok = $stmt->execute([':qty' => $newQty, ':detail_id' => $poDetailId, ':po_id' => $poId]);

        if ($ok) {
            Logger::log($updatedBy, 'OVERRIDE_PO_QTY', 'purchase_order_details', $poDetailId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Quantity updated.' : 'An error occurred while updating.'];
    }

    /* FR-MGR-04: Manager submit PO để Admin duyệt - Draft -> Pending.
     * BR-20: sau bước này, purchase_order_details bị khóa cho tới khi Admin approve/reject (enforce qua updateLineQuantity() kiểm tra status ở trên). */
    public function submitForApproval(int $poId): array
    {
        $po = $this->getById($poId);
        if ($po === false) {
            return ['success' => false, 'message' => 'Purchase order not found.'];
        }
        if ($po['status'] !== 'Draft') {
            return ['success' => false, 'message' => "Only orders with status 'Draft' can be submitted."];
        }

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET status = 'Pending' WHERE po_id = :id AND status = 'Draft'"
        );
        $ok = $stmt->execute([':id' => $poId]);

        return ['success' => $ok, 'message' => $ok ? 'Order sent to Admin for approval.' : 'An error occurred.'];
    }

    /* FR-ADM-06 / BR-07: Admin duyệt PO - Pending -> Approved.
     * Đây là thời điểm DUY NHẤT PO được phép gửi tới nhà cung cấp về mặt nghiệp vụ (việc gọi API/gửi thông báo cho NCC thực tế nằm ở Service, không phải Model). */
    public function approve(int $poId, int $approvedBy): array
    {
        $po = $this->getById($poId);
        if ($po === false) {
            return ['success' => false, 'message' => 'Purchase order not found.'];
        }
        if ($po['status'] !== 'Pending') {
            return ['success' => false, 'message' => "Only orders with status 'Pending' can be approved."];
        }

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = 'Approved', approved_by = :approved_by, approved_at = NOW()
             WHERE po_id = :id AND status = 'Pending'"
        );
        $ok = $stmt->execute([':approved_by' => $approvedBy, ':id' => $poId]);

        if ($ok) {
            Logger::log($approvedBy, 'APPROVE_PO', 'purchase_orders', $poId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Purchase order approved.' : 'An error occurred while approving.'];
    }

    /* FR-ADM-06 / BR-20: Admin từ chối PO - Pending -> Rejected.
     * Sau khi Rejected, PO KHÔNG tự mở khóa để sửa lại - Manager tạo lại 1 Draft mới (createDraft()) nếu muốn gửi lại, giữ audit trail rõ ràng (1 po_id = đúng 1 lần submit). */
    public function reject(int $poId, int $rejectedBy): array
    {
        $po = $this->getById($poId);
        if ($po === false) {
            return ['success' => false, 'message' => 'Purchase order not found.'];
        }
        if ($po['status'] !== 'Pending') {
            return ['success' => false, 'message' => "Only orders with status 'Pending' can be rejected."];
        }

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table}
             SET status = 'Rejected', approved_by = :rejected_by, approved_at = NOW()
             WHERE po_id = :id AND status = 'Pending'"
        );
        $ok = $stmt->execute([':rejected_by' => $rejectedBy, ':id' => $poId]);

        if ($ok) {
            Logger::log($rejectedBy, 'REJECT_PO', 'purchase_orders', $poId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Purchase order rejected.' : 'An error occurred.'];
    }

    // GOODS RECEIPT - BR-08/BR-09/BR-10 (FR-STF-05/06/07)

    /* FR-STF-05/07: ghi nhận số lượng thực nhận cho 1 dòng PO khi hàng về.
     * BR-08: giả định Staff đã đối chiếu bằng mắt số lượng thực tế với PO ở UI trước khi gọi hàm này.
     * BR-09: chỉ received_qty ghi ở đây mới được cộng vào stock - việc cộng stock (gọi Inventory::stockIn()) PHẢI do Service gọi RIÊNG sau khi hàm này chạy thành công, để giữ Order.php không phụ thuộc chéo Inventory.php.
     * BR-10: nếu receivedQty != approved_qty, $discrepancyReason bắt buộc. */
    public function recordReceipt(int $poDetailId, int $receivedQty, ?string $discrepancyReason): array
    {
        $stmt = $this->conn->prepare(
            "SELECT approved_qty FROM purchase_order_details WHERE po_detail_id = :id"
        );
        $stmt->execute([':id' => $poDetailId]);
        $detail = $stmt->fetch();

        if ($detail === false) {
            return ['success' => false, 'message' => 'Order line not found.'];
        }

        if ($receivedQty !== (int) $detail['approved_qty'] && empty($discrepancyReason)) {
            return [
                'success' => false,
                'message' => 'Received quantity differs from the purchase order - please provide a discrepancy reason (BR-10).',
            ];
        }

        $update = $this->conn->prepare(
            "UPDATE purchase_order_details
             SET received_qty = :received_qty, discrepancy_reason = :reason
             WHERE po_detail_id = :id"
        );
        $ok = $update->execute([
            ':received_qty' => $receivedQty,
            ':reason'       => $discrepancyReason,
            ':id'           => $poDetailId,
        ]);

        return ['success' => $ok, 'message' => $ok ? 'Received quantity recorded.' : 'An error occurred.'];
    }

    /* FR-MGR-06: sau khi toàn bộ dòng của 1 PO đã có received_qty, Service gọi hàm này để đóng đơn - Approved -> Delivered. */
    public function markDelivered(int $poId): array
    {
        $po = $this->getById($poId);
        if ($po === false) {
            return ['success' => false, 'message' => 'Purchase order not found.'];
        }
        if ($po['status'] !== 'Approved') {
            return ['success' => false, 'message' => "Only orders with status 'Approved' can be marked 'Delivered'."];
        }

        $stmt = $this->conn->prepare(
            "UPDATE {$this->table} SET status = 'Delivered' WHERE po_id = :id AND status = 'Approved'"
        );
        $ok = $stmt->execute([':id' => $poId]);

        return ['success' => $ok, 'message' => $ok ? 'Order delivery completed.' : 'An error occurred.'];
    }
}