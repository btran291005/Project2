<?php
/**
 * File: backend/services/IntegrationService.php
 * Purpose: Bọc các lời gọi API bên ngoài (AI Demand Forecast, Notification).
 *          Chứa logic fallback BẮT BUỘC: nếu Forecast API lỗi/timeout, TỰ ĐỘNG
 *          quay về công thức rule-based (ReorderService::suggestQuantityForProduct(),
 *          đã code sẵn ở phase trước) thay vì ném lỗi ra ngoài.
 * Related: FR-SYS-02, FR-SYS-03, FR-SYS-04, FR-MGR-10, BR-18, NFR-06
 * Warning: KHÔNG BAO GIỜ để 1 lời gọi API bên ngoài thất bại làm sập luồng
 *          nghiệp vụ reorder - luôn trả về array có 'success' => true kèm
 *          gợi ý (dù là fallback), CHỈ 'success' => false khi CẢ 2 nguồn
 *          (API + rule-based) đều không tính được (VD: chưa cấu hình reorder rule).
 *
 * PHẠM VI CỦA FILE NÀY (điểm nối mà ManagerService::getForecastSuggestion() đã
 * chờ sẵn - xem ManagerService.php mục 4, CHỮ KÝ HÀM PHẢI GIỮ ĐÚNG như dưới đây):
 *   - getForecastForProduct(int $productId): array
 *   - sendLowStockAlerts(array $alerts, ?int $triggeredBy = null): array
 *
 * Bảng liên quan (đã có trong DB, xem db.sql):
 *   demand_forecasts(forecast_id, product_id, suggested_qty, api_status, requested_at)
 *     - api_status ENUM('success','fallback_used') - PHẢI ghi đúng 1 dòng vào
 *       bảng này MỖI LẦN gọi getForecastForProduct(), dù kết quả từ API thật
 *       hay từ fallback (đây là cách duy nhất để NFR-09/audit truy vết được
 *       lần nào hệ thống phải dùng fallback theo BR-18).
 *
 * QUY ƯỚC CHUNG: giống ManagerService.php/ReorderService.php - Service không
 * tự check role (đã chặn ở Middleware trước khi vào Controller), input được
 * ép kiểu trước khi xuống Model, return value dạng array ['success'=>bool,...].
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/ForecastAPI.php';
require_once __DIR__ . '/../api/NotificationAPI.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Sales.php';
require_once __DIR__ . '/ReorderService.php';

class IntegrationService
{
    private PDO $conn;
    private ForecastAPI $forecastApi;
    private NotificationAPI $notificationApi;
    private Product $productModel;
    private Inventory $inventoryModel;
    private Sales $salesModel;
    private ReorderService $reorderService;

    public function __construct()
    {
        $this->conn            = Database::getConnection();
        $this->forecastApi     = new ForecastAPI();
        $this->notificationApi = new NotificationAPI();
        $this->productModel    = new Product();
        $this->inventoryModel  = new Inventory();
        $this->salesModel      = new Sales();
        $this->reorderService  = new ReorderService();
    }

    // =====================================================================
    // 1. DEMAND FORECAST (FR-SYS-04, FR-MGR-10, BR-18, NFR-06)
    // =====================================================================

    /**
     * FR-MGR-10 / BR-18: lấy gợi ý số lượng đặt hàng từ AI Forecast API cho
     * 1 sản phẩm. Nếu API lỗi/timeout, TỰ ĐỘNG fallback về công thức
     * rule-based của ReorderService (BR-05) - ĐÂY LÀ ĐIỂM NỐI mà
     * ManagerService::getForecastSuggestion() đang chờ (thay thế placeholder
     * hiện tại trả về success=false).
     *
     * Luôn ghi 1 dòng vào demand_forecasts (api_status = 'success' hoặc
     * 'fallback_used') để phục vụ audit/traceability (NFR-09).
     *
     * @return array{
     *   success: bool,
     *   source: string,        // 'ai_forecast' | 'rule_based_fallback'
     *   suggested_qty?: int,
     *   confidence?: float|null,
     *   message: string
     * }
     */
    public function getForecastForProduct(int $productId): array
    {
        $product = $this->productModel->getById($productId);
        if ($product === false) {
            return [
                'success' => false,
                'source'  => 'none',
                'message' => 'Product not found.',
            ];
        }

        $stockRows = $this->inventoryModel->getStockByProduct($productId);
        $currentStock = !empty($stockRows) ? (int) $stockRows[0]['total_quantity'] : 0;

        $ruleBased = $this->reorderService->suggestQuantityForProduct($productId);
        $rule = $ruleBased['success'] ? $ruleBased['suggestion'] : null;

        // Bước 1: thử gọi AI Forecast API thật (timeout đã xử lý bên trong ForecastAPI).
        $apiResult = $this->forecastApi->getForecast([
            'product_id' => $productId,
            'category_type' => $product['category_type'] ?: null,
            'sales_history' => $this->salesModel->getDailyForecastHistory($productId),
            'forecast_horizon_days' => FORECAST_HORIZON_DAYS,
            'current_stock' => $currentStock,
            'safety_stock' => $rule['safety_stock'] ?? 0,
            'max_stock' => $rule['max_stock'] ?? 0,
        ]);

        if ($apiResult['success']) {
            $this->recordForecastResult($productId, $apiResult['suggested_qty'], 'success');

            return [
                'success'       => true,
                'source'        => 'ai_forecast',
                'suggested_qty' => $apiResult['suggested_qty'],
                'forecasted_demand' => $apiResult['forecasted_demand'] ?? null,
                'forecast' => $apiResult['forecast'] ?? [],
                'model_used' => $apiResult['model_used'] ?? 'forecast_api',
                'rule_based_suggestion' => $rule,
                'message'       => 'Suggestion retrieved from AI Demand Forecast API.',
            ];
        }

        // Bước 2 (BR-18): API lỗi/timeout/chưa cấu hình -> fallback về rule-based.
        error_log(
            "[IntegrationService] Forecast API thất bại cho product_id={$productId}, "
            . "fallback sang rule-based (BR-18). Lý do: " . ($apiResult['error'] ?? 'không rõ')
        );

        // Reuse the rule result gathered before the API call. This keeps the
        // fallback decision consistent with the rule values sent to the API.
        $fallback = $ruleBased;

        if (!$fallback['success']) {
            // Cả API lẫn rule-based đều không tính được (VD: chưa có reorder rule).
            return [
                'success' => false,
                'source'  => 'none',
                'code'    => 'forecast_and_reorder_rule_unavailable',
                'api_error' => $apiResult['error'] ?? null,
                'message' => 'Forecast API unavailable and ' . lcfirst($fallback['message']),
            ];
        }

        $suggestedQty = $fallback['suggestion']['suggested_qty'];
        $this->recordForecastResult($productId, $suggestedQty, 'fallback_used');

        return [
            'success'       => true,
            'source'        => 'rule_based_fallback',
            'suggested_qty' => $suggestedQty,
            'rule_based_suggestion' => $fallback['suggestion'],
            'message'       => 'AI Forecast API unavailable - fallback Reorder Point formula used (BR-18).',
        ];
    }

    /**
     * FR-SYS-04: gọi forecast hàng loạt cho toàn bộ sản phẩm đang cần đặt hàng
     * (đã chạm/dưới Reorder Point) - dùng khi Manager bấm "làm mới gợi ý AI"
     * trên dashboard, hoặc cron job định kỳ gọi lại. Tái sử dụng
     * ReorderService::suggestQuantity() chỉ để lấy DANH SÁCH product_id cần
     * xét (không dùng số suggested_qty rule-based của nó ở đây, vì mỗi sản
     * phẩm sẽ được gọi lại getForecastForProduct() để có cơ hội dùng AI thật).
     *
     * @return array{success: bool, results: array, ai_count: int, fallback_count: int, message: string}
     */
    public function refreshForecastForAllLowStock(): array
    {
        $lowStockList = $this->reorderService->suggestQuantity();

        $results = [];
        $aiCount = 0;
        $fallbackCount = 0;

        foreach ($lowStockList as $item) {
            $forecast = $this->getForecastForProduct((int) $item['product_id']);

            $results[] = array_merge(
                ['product_id' => $item['product_id'], 'sku_code' => $item['sku_code']],
                $forecast
            );

            if ($forecast['success'] && $forecast['source'] === 'ai_forecast') {
                $aiCount++;
            } elseif ($forecast['success'] && $forecast['source'] === 'rule_based_fallback') {
                $fallbackCount++;
            }
        }

        return [
            'success'        => true,
            'results'        => $results,
            'ai_count'       => $aiCount,
            'fallback_count' => $fallbackCount,
            'message'        => "Refreshed suggestions for " . count($results) . " products "
                               . "({$aiCount} from AI, {$fallbackCount} using rule-based fallback).",
        ];
    }

    /**
     * Ghi 1 dòng vào demand_forecasts - tách private vì được gọi ở cả 2 nhánh
     * (success/fallback) của getForecastForProduct(), tránh lặp code SQL.
     * Lỗi ghi log KHÔNG được làm sập luồng chính (giống quy ước ở Logger::log()).
     */
    private function recordForecastResult(int $productId, int $suggestedQty, string $apiStatus): void
    {
        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO demand_forecasts (product_id, suggested_qty, api_status, requested_at)
                 VALUES (:product_id, :suggested_qty, :api_status, NOW())'
            );
            $stmt->execute([
                ':product_id'     => $productId,
                ':suggested_qty'  => $suggestedQty,
                ':api_status'     => $apiStatus,
            ]);
        } catch (PDOException $e) {
            error_log('[IntegrationService::recordForecastResult] ' . $e->getMessage());
        }
    }

    // =====================================================================
    // 2. NOTIFICATION (FR-SYS-02, BR-04)
    // =====================================================================

    /**
     * FR-SYS-02 / BR-04: gửi cảnh báo cho danh sách sản phẩm đã chạm/dưới
     * Reorder Point. Nhận $alerts từ ManagerService::getLowStockAlerts() (đã
     * có sẵn định dạng ['product_id','product_name','current_quantity',
     * 'reorder_point',...]) - KHÔNG tự query lại danh sách, tránh 2 nơi cùng
     * sở hữu logic xác định "sản phẩm nào cần cảnh báo" (đó vẫn là BR-04/
     * BR-13 ở ManagerService).
     *
     * @param array    $alerts      Kết quả từ ManagerService::getLowStockAlerts()
     * @param int|null $triggeredBy account_id gây ra cảnh báo (null = hệ thống/cron)
     *
     * @return array{success: bool, sent_count: int, failed_count: int, details: array, message: string}
     */
    public function sendLowStockAlerts(array $alerts, ?int $triggeredBy = null): array
    {
        if (empty($alerts)) {
            return [
                'success'      => true,
                'sent_count'   => 0,
                'failed_count' => 0,
                'details'      => [],
                'message'      => 'No products require an alert.',
            ];
        }

        $details = [];
        $sentCount = 0;
        $failedCount = 0;

        foreach ($alerts as $alert) {
            $result = $this->notificationApi->sendLowStockAlert(
                (int) $alert['product_id'],
                (string) $alert['product_name'],
                (int) $alert['current_quantity'],
                (int) $alert['reorder_point'],
                $triggeredBy
            );

            $details[] = array_merge(['product_id' => $alert['product_id']], $result);

            if ($result['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'success'      => $failedCount === 0,
            'sent_count'   => $sentCount,
            'failed_count' => $failedCount,
            'details'      => $details,
            'message'      => $failedCount === 0
                ? "Sent alerts for {$sentCount} products."
                : "Sent {$sentCount} alerts, {$failedCount} alerts failed (see 'details').",
        ];
    }
}
