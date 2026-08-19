# File: app/main.py
# Purpose: FastAPI app - định nghĩa endpoint /forecast và /health. Đây là
# service ĐỘC LẬP với hệ thống PHP chính - chạy trên port riêng (mặc định
# 8000), PHP gọi sang qua HTTP (xem IntegrationService.php phía PHP).
#
# Related: FR-SYS-03, FR-MGR-08, NFR-06 (PHP phải tự fallback nếu service
# này không phản hồi - không phải trách nhiệm của file này, nhưng thiết kế
# timeout/error response ở đây phải RÕ RÀNG để phía PHP dễ phát hiện lỗi
# và kích hoạt fallback thay vì treo chờ vô thời hạn).
#
# Chạy thử: uvicorn app.main:app --reload --port 8000
# Xem docs tự động (Swagger UI): http://localhost:8000/docs

import os
import time

from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse
from dotenv import load_dotenv

from app.schemas import ForecastRequest, ForecastResponse
from app.forecast_service import run_forecast

load_dotenv()

# API_KEY dùng để PHP xác thực khi gọi sang - khớp với giá trị Admin nhập ở
# frontend/admin/api-config.php (endpoint_url trỏ vào server này, api_key
# trỏ vào giá trị FORECAST_API_KEY dưới đây). Đọc từ biến môi trường, KHÔNG
# hardcode trong code - giữ đúng nguyên tắc đã thống nhất ở phần .gitignore/.env.
API_KEY = os.getenv("FORECAST_API_KEY")

app = FastAPI(
    title="GS25 Demand Forecast API",
    description="Category-aware demand forecasting service (Prophet) for the GS25 Inventory DSS project.",
    version="1.0.0",
)


@app.middleware("http")
async def add_process_time_header(request: Request, call_next):
    """Đo thời gian xử lý mỗi request - hữu ích khi debug nếu PHP timeout
    (NFR-06 yêu cầu fallback khi API lỗi/timeout - log này giúp xác định
    server có đang chậm bất thường không, hay lỗi nằm ở phía network)."""
    start = time.time()
    response = await call_next(request)
    response.headers["X-Process-Time"] = str(round(time.time() - start, 3))
    return response


def verify_api_key(x_api_key: str | None) -> None:
    """Xác thực đơn giản bằng header X-API-Key. Nếu FORECAST_API_KEY chưa
    được cấu hình trong .env (VD: môi trường dev), bỏ qua kiểm tra để nhóm
    dễ test cục bộ - PHẢI đặt FORECAST_API_KEY trước khi deploy thật."""
    if API_KEY is None:
        return
    if x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid or missing API key.")


@app.get("/health")
def health_check():
    """Endpoint đơn giản để PHP (hoặc nhóm) kiểm tra service có đang chạy
    không, trước khi gọi /forecast thật - có thể dùng trong healthcheck
    của IntegrationService.php để quyết định gọi thẳng fallback luôn mà
    không cần đợi timeout của endpoint chính."""
    return {"status": "ok", "service": "gs25-demand-forecast-api"}


@app.post("/forecast", response_model=ForecastResponse)
def forecast(payload: ForecastRequest, x_api_key: str | None = Header(default=None)):
    """Nhận lịch sử bán hàng + category_type, trả về dự báo N ngày tới.

    Response luôn có model_used='prophet' khi thành công - phía PHP
    (IntegrationService.php) dùng field này để phân biệt kết quả thật từ
    API với kết quả fallback rule-based (fallback không gọi tới đây, tự
    tính ở PHP nên sẽ không có field này - đây chính là "chữ ký" để phân
    biệt 2 nguồn dữ liệu).
    """
    verify_api_key(x_api_key)

    try:
        return run_forecast(payload)
    except ValueError as e:
        # Lỗi do dữ liệu đầu vào không đủ/không hợp lệ -> trả 422, PHP nên
        # coi đây là "dùng fallback" chứ không phải "thử lại request y hệt"
        raise HTTPException(status_code=422, detail=str(e)) from e
    except Exception as e:
        # Lỗi hệ thống không lường trước -> 500, PHP fallback ngay, không
        # retry (đúng tinh thần NFR-06: graceful degradation, không để
        # người dùng chờ đợi 1 service có vẻ đang gặp sự cố).
        raise HTTPException(status_code=500, detail=f"Internal forecasting error: {e}") from e


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    """Bắt mọi lỗi không lường trước còn sót lại, đảm bảo LUÔN trả về JSON
    hợp lệ (không bao giờ để PHP nhận HTML lỗi 500 mặc định của server -
    JSON parse sẽ fail và làm rối logic fallback phía PHP)."""
    return JSONResponse(
        status_code=500,
        content={"detail": f"Unhandled server error: {exc}"},
    )