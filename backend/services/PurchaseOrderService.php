<?php
/**
 * File: backend/services/PurchaseOrderService.php
 * Purpose: Điều phối luồng NHẬN HÀNG (goods receipt) của Purchase Order -
 *          nối Order.php (trạng thái PO/PO detail) với Inventory.php (cộng
 *          tồn kho thực tế) thành 1 luồng nghiệp vụ hoàn chỉnh. Đây CHÍNH LÀ
 *          Service mà Order::recordReceipt() đã ghi chú "PHẢI do Service gọi
 *          RIÊNG sau khi hàm này chạy thành công" (xem Order.php dòng
 *          280-282: "việc cộng stock (gọi Inventory::stockIn()) PHẢI do
 *          Service gọi RIÊNG... để giữ Order.php không phụ thuộc chéo
 *          Inventory.php").
 * Related: BR-08, BR-09, BR-10, FR-STF-05, FR-STF-06, FR-STF-07
 *
 * PHẠM VI CỦA FILE NÀY:
 *   - Nhận hàng theo PO đã 'Approved' (BR-07: chỉ PO đã Admin duyệt mới được
 *     nhận hàng thực tế).
 *   - BR-08: giả định Staff đã đối chiếu bằng mắt số lượng thực tế với PO ở
 *     UI trước khi gọi các hàm dưới đây (đúng ghi chú trong Order::recordReceipt()).
 *   - BR-09: CHỈ received_qty đã ghi nhận qua recordReceipt() mới được cộng
 *     vào stock - file này đảm bảo 2 bước (ghi received_qty + cộng stock)
 *     luôn đi cùng nhau theo đúng thứ tự, không bao giờ cộng stock mà không
 *     có received_qty tương ứng trên purchase_order_details.
 *   - BR-10: discrepancy_reason bắt buộc nếu received_qty khác approved_qty -
 *     validate đã có sẵn trong Order::recordReceipt(), Service ở đây không
 *     lặp lại, chỉ truyền input xuống đúng thứ tự.
 *   - KHÔNG xử lý tạo Draft/submit/approve/reject (thuộc về
 *     ManagerService::createPurchaseOrderDraft()/submitPurchaseOrder() và
 *     AdminService::approvePurchaseOrder()/rejectPurchaseOrder() - đã code ở
 *     2 file trước, không lặp lại ở đây để tránh 2 nơi cùng sở hữu 1 luồng
 *     nghiệp vụ).
 *   - warehouse_id nhận hàng: schema hiện tại KHÔNG có cột "kho đích" mặc
 *     định trên purchase_orders/purchase_order_details, nên $warehouseId
 *     PHẢI được UI/Controller truyền vào tường minh khi gọi
 *     receiveLine()/receiveFullOrder() (Staff chọn kho nhận hàng trên form -
 *     giống cách Sales::createTransaction() cũng nhận $warehouseId từ ngoài).
 *
 * QUY ƯỚC CHUNG: giống AdminService.php/ManagerService.php - Service không tự
 * check role (đã chặn ở Middleware trước khi vào Controller), input được ép
 * kiểu trước khi xuống Model, return value dạng array ['success'=>bool,...].
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Product.php';

class PurchaseOrderService
{
    private Order $orderModel;
    private Inventory $inventoryModel;
    private Product $productModel;

    public function __construct()
    {
        $this->orderModel     = new Order();
        $this->inventoryModel = new Inventory();
        $this->productModel   = new Product();
    }

    // =====================================================================
    // 1. XEM PO SẴN SÀNG NHẬN HÀNG (BR-07: chỉ PO đã Approved)
    // =====================================================================

    /** FR-STF-05: danh sách PO đã được duyệt, đang chờ nhận hàng tại cửa hàng. */
    public function listOrdersAwaitingReceipt(): array
    {
        return $this->orderModel->getAll('Approved');
    }

    /** FR-STF-05: chi tiết 1 PO kèm từng dòng sản phẩm - dùng để Staff đối chiếu khi hàng về (BR-08). */
    public function getOrderForReceiving(int $poId): array|false
    {
        $po = $this->orderModel->getById($poId);
        if ($po === false) {
            return false;
        }

        if ($po['status'] !== 'Approved') {
            // Vẫn trả về dữ liệu (không phải lỗi cứng) nhưng đánh dấu rõ để UI
            // không hiển thị nút "Xác nhận nhận hàng" cho PO sai trạng thái.
            $po['can_receive'] = false;
        } else {
            $po['can_receive'] = true;
        }

        $po['details'] = $this->orderModel->getDetails($poId);
        return $po;
    }

    // =====================================================================
    // 2. NHẬN HÀNG THEO TỪNG DÒNG (BR-08, BR-09, BR-10, FR-STF-05/07)
    // =====================================================================

    /**
     * FR-STF-05 / FR-STF-07: xác nhận 1 dòng sản phẩm của PO đã về hàng.
     * Điều phối ĐÚNG THỨ TỰ: (1) Order::recordReceipt() ghi received_qty +
     * discrepancy_reason lên purchase_order_details (validate BR-10 xảy ra
     * ở bước này), (2) NẾU thành công MỚI gọi Inventory::stockIn() để cộng
     * tồn kho thực tế (BR-09: chỉ số lượng đã xác nhận mới được cộng kho).
     * Nếu bước (1) thất bại (VD: thiếu discrepancy_reason theo BR-10), bước
     * (2) KHÔNG được gọi - tồn kho không thay đổi.
     *
     * @param int      $poDetailId         po_detail_id của dòng cần nhận
     * @param int      $productId          product_id tương ứng dòng đó (để gọi Inventory::stockIn())
     * @param int      $receivedQty        Số lượng thực nhận (BR-08: Staff đã đối chiếu trên UI)
     * @param int      $warehouseId        Kho nhận hàng (Staff chọn trên form, không có mặc định trong schema)
     * @param int      $performedBy        account_id của Staff thực hiện
     * @param string|null $discrepancyReason Bắt buộc nếu $receivedQty khác approved_qty (BR-10)
     *
     * @return array{success: bool, message: string}
     */
    public function receiveLine(
        int $poDetailId,
        int $productId,
        int $receivedQty,
        int $warehouseId,
        int $performedBy,
        ?string $discrepancyReason = null
    ): array {
        if ($receivedQty < 0) {
            return ['success' => false, 'message' => 'Received quantity cannot be negative.'];
        }

        // Bước 1: ghi nhận số lượng thực tế lên purchase_order_details (BR-08, BR-10).
        // Order::recordReceipt() tự kiểm tra discrepancy_reason bắt buộc khi lệch số.
        $receiptResult = $this->orderModel->recordReceipt($poDetailId, $receivedQty, $discrepancyReason);

        if (!$receiptResult['success']) {
            return $receiptResult; // Dừng ở đây - KHÔNG cộng kho nếu bước ghi nhận thất bại.
        }

        // BR-09: chỉ số lượng ĐÃ XÁC NHẬN (received_qty > 0) mới cộng vào stock.
        // received_qty = 0 hợp lệ về nghiệp vụ (VD: hàng không về đủ, discrepancy
        // đã ghi lý do) - không cần cộng kho trong trường hợp đó.
        if ($receivedQty === 0) {
            return [
                'success' => true,
                'message' => 'Line recorded (received quantity = 0, nothing to add to stock).',
            ];
        }

        // Bước 2: cộng tồn kho thực tế, gắn reference_id = po_detail_id để truy vết nguồn gốc.
        $stockInResult = $this->inventoryModel->stockIn(
            $productId,
            $warehouseId,
            $receivedQty,
            $performedBy,
            $poDetailId
        );

        if (!$stockInResult['success']) {
            // Bước 1 đã ghi thành công nhưng bước 2 lỗi - KHÔNG tự rollback bước 1
            // (2 model độc lập, không chia sẻ transaction) - ghi rõ tình trạng
            // không nhất quán này để Staff/Admin biết cần xử lý thủ công.
            error_log(
                "[PurchaseOrderService::receiveLine] Đã ghi received_qty cho po_detail_id={$poDetailId} "
                . "nhưng cộng tồn kho thất bại: " . $stockInResult['message']
            );
            return [
                'success' => false,
                'message' => 'Received quantity recorded, but an error occurred while updating stock. '
                            . 'Please contact the Manager/Admin to check the stock manually.',
            ];
        }

        return ['success' => true, 'message' => 'Goods received and stock updated successfully.'];
    }

    /**
     * FR-STF-05/06/07: nhận hàng cho TOÀN BỘ các dòng của 1 PO trong 1 lượt -
     * dùng khi hàng về đủ và khớp hoàn toàn với PO (trường hợp phổ biến nhất,
     * giúp Staff không phải xác nhận từng dòng một trên UI - NFR-05: tối đa
     * 3 bước để hoàn tất giao dịch xuất/nhập kho).
     *
     * $lines: [['po_detail_id'=>int,'product_id'=>int,'received_qty'=>int,
     *           'discrepancy_reason'=>string|null], ...]
     * Mỗi dòng được xử lý độc lập qua receiveLine() - nếu 1 dòng lỗi, các
     * dòng khác VẪN được xử lý tiếp (không rollback toàn bộ đơn), vì mỗi
     * dòng sản phẩm là 1 giao dịch tồn kho độc lập về nghiệp vụ. Kết quả trả
     * về liệt kê rõ dòng nào thành công/thất bại để UI hiển thị.
     *
     * Sau khi xử lý xong, nếu TẤT CẢ các dòng đã có received_qty (không còn
     * dòng nào NULL), tự động gọi Order::markDelivered() để đóng đơn
     * (Approved -> Delivered, FR-MGR-06).
     *
     * @return array{success: bool, results: array, all_delivered: bool, message: string}
     */
    public function receiveFullOrder(int $poId, array $lines, int $warehouseId, int $performedBy): array
    {
        $po = $this->orderModel->getById($poId);
        if ($po === false) {
            return ['success' => false, 'results' => [], 'all_delivered' => false, 'message' => 'Purchase order not found.'];
        }
        if ($po['status'] !== 'Approved') {
            return [
                'success' => false,
                'results' => [],
                'all_delivered' => false,
                'message' => "Goods can only be received for orders with status 'Approved' (current status: '{$po['status']}').",
            ];
        }
        if (empty($lines)) {
            return ['success' => false, 'results' => [], 'all_delivered' => false, 'message' => 'No product lines to receive.'];
        }

        $results = [];
        $hasFailure = false;

        foreach ($lines as $line) {
            $poDetailId = (int) ($line['po_detail_id'] ?? 0);
            $productId  = (int) ($line['product_id'] ?? 0);
            $receivedQty = (int) ($line['received_qty'] ?? -1);
            $discrepancyReason = $line['discrepancy_reason'] ?? null;

            if ($poDetailId <= 0 || $productId <= 0 || $receivedQty < 0) {
                $results[] = [
                    'po_detail_id' => $poDetailId,
                    'success'      => false,
                    'message'      => 'Invalid line data (missing po_detail_id/product_id or negative quantity).',
                ];
                $hasFailure = true;
                continue;
            }

            $lineResult = $this->receiveLine($poDetailId, $productId, $receivedQty, $warehouseId, $performedBy, $discrepancyReason);

            $results[] = array_merge(['po_detail_id' => $poDetailId], $lineResult);

            if (!$lineResult['success']) {
                $hasFailure = true;
            }
        }

        // Kiểm tra xem toàn bộ dòng của PO đã có received_qty chưa (kể cả các
        // dòng không nằm trong $lines lần này, nếu trước đó đã nhận riêng lẻ).
        $allDelivered = $this->isFullyReceived($poId);
        if ($allDelivered) {
            $this->orderModel->markDelivered($poId);
        }

        return [
            'success'       => !$hasFailure,
            'results'       => $results,
            'all_delivered' => $allDelivered,
            'message'       => $hasFailure
                ? 'Some product lines had errors while receiving - see details per line.'
                : ($allDelivered ? 'Order fully received, order status changed to Delivered.' : 'Goods received for the selected lines.'),
        ];
    }

    /**
     * Kiểm tra 1 PO đã nhận đủ tất cả các dòng chưa (received_qty không còn
     * NULL ở bất kỳ dòng nào) - điều kiện để tự động markDelivered().
     */
    private function isFullyReceived(int $poId): bool
    {
        $details = $this->orderModel->getDetails($poId);
        if (empty($details)) {
            return false;
        }

        foreach ($details as $detail) {
            if ($detail['received_qty'] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * FR-MGR-06: Manager/Admin xem lại toàn bộ lịch sử sai lệch (discrepancy)
     * của 1 PO đã nhận hàng - dùng cho báo cáo hiệu suất nhà cung cấp
     * (FR-MGR-11, đã có Supplier::getPerformanceStats() ở tầng Model).
     */
    public function getDiscrepancySummary(int $poId): array
    {
        $details = $this->orderModel->getDetails($poId);
        $discrepancies = [];

        foreach ($details as $detail) {
            if ($detail['received_qty'] !== null && (int) $detail['received_qty'] !== (int) $detail['approved_qty']) {
                $discrepancies[] = [
                    'product_id'         => $detail['product_id'],
                    'product_name'       => $detail['product_name'],
                    'sku_code'           => $detail['sku_code'],
                    'approved_qty'       => (int) $detail['approved_qty'],
                    'received_qty'       => (int) $detail['received_qty'],
                    'difference'         => (int) $detail['received_qty'] - (int) $detail['approved_qty'],
                    'discrepancy_reason' => $detail['discrepancy_reason'],
                ];
            }
        }

        return $discrepancies;
    }
}