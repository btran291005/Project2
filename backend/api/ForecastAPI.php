<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';

/** HTTP client for the local Demand Forecast service. */
class ForecastAPI
{
    private const API_NAMES = ['AI_Demand_Forecast', 'Demand_Forecast_API'];

    private PDO $conn;

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    /**
     * @param array<string, mixed> $requestData Contract defined by forecast-api/app/schemas.py
     * @return array{success: bool, suggested_qty?: int, forecasted_demand?: float, forecast?: array, model_used?: string, error?: string}
     */
    public function getForecast(array $requestData): array
    {
        $config = $this->getApiConfig();
        if ($config === false) {
            return ['success' => false, 'error' => 'Chưa cấu hình Demand Forecast API.'];
        }

        $payload = json_encode($requestData, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['success' => false, 'error' => 'Không thể tạo dữ liệu gửi tới Forecast API.'];
        }
        $endpoint = $this->normaliseForecastEndpoint((string) $config['endpoint_url']);

        try {
            $ch = curl_init();
            if ($ch === false) {
                return ['success' => false, 'error' => 'Không thể khởi tạo Forecast API client.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-API-Key: ' . $config['api_key'],
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(3, FORECAST_API_TIMEOUT_SECONDS),
                CURLOPT_TIMEOUT => FORECAST_API_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => str_starts_with($endpoint, 'https://'),
            ]);

            $body = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } catch (Throwable $e) {
            error_log('[ForecastAPI] ' . $e->getMessage());
            return ['success' => false, 'error' => 'Không thể gọi Forecast API.'];
        }

        if ($curlErrno !== 0) {
            error_log("[ForecastAPI] cURL error {$curlErrno}: {$curlError}");
            return ['success' => false, 'error' => 'Không thể kết nối Forecast API.'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('[ForecastAPI] HTTP ' . $httpCode . ': ' . substr((string) $body, 0, 500));
            return ['success' => false, 'error' => "Forecast API trả về HTTP {$httpCode}."];
        }

        return $this->parseResponse($body);
    }

    private function getApiConfig(): array|false
    {
        $placeholders = implode(', ', array_fill(0, count(self::API_NAMES), '?'));
        $stmt = $this->conn->prepare(
            "SELECT endpoint_url, api_key FROM api_configs WHERE api_name IN ({$placeholders}) ORDER BY config_id ASC LIMIT 1"
        );
        $stmt->execute(self::API_NAMES);
        return $stmt->fetch();
    }

    private function normaliseForecastEndpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        return str_ends_with($endpoint, '/forecast') ? $endpoint : $endpoint . '/forecast';
    }

    private function parseResponse(string|false $rawResponse): array
    {
        if ($rawResponse === false || $rawResponse === '') {
            return ['success' => false, 'error' => 'Forecast API trả về dữ liệu rỗng.'];
        }

        try {
            $data = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['success' => false, 'error' => 'Forecast API trả về JSON không hợp lệ.'];
        }

        if (!is_array($data) || !isset($data['suggested_reorder_quantity']) || !is_numeric($data['suggested_reorder_quantity'])) {
            return ['success' => false, 'error' => 'Forecast API trả về dữ liệu không đúng định dạng.'];
        }

        return [
            'success' => true,
            'suggested_qty' => max(0, (int) ceil((float) $data['suggested_reorder_quantity'])),
            'forecasted_demand' => isset($data['forecasted_demand']) && is_numeric($data['forecasted_demand'])
                ? (float) $data['forecasted_demand'] : null,
            'forecast' => is_array($data['forecast'] ?? null) ? $data['forecast'] : [],
            'model_used' => is_string($data['model_used'] ?? null) ? $data['model_used'] : 'forecast_api',
        ];
    }
}
