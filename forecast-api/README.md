# Demand Forecast API

From this folder, create a virtual environment, install dependencies, then start the service:

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Set `FORECAST_API_KEY` in `.env` and configure the same key in `api_configs`.
The PHP app accepts either `http://127.0.0.1:8000` or the full
`http://127.0.0.1:8000/forecast` endpoint. The response includes a 7-day
forecast and a stock-aware suggested order quantity.

## Forecasting models

The service uses a two-tier model architecture:

```
Sales History
     |
     v
Forecast Module
  +------------------------------------------+
  | Default: Holt-Winters (Exponential Smooth)|
  | Optional: Prophet                        |
  | Fallback: Weighted Moving Average        |
  +------------------------------------------+
     |
     v
Predicted Demand
     |
     v
Rule Engine (safety stock, ROP, lead time)
     |
     v
Suggested Reorder Quantity
```

- **Default: Holt-Winters (Exponential Smoothing)** — captures trend and weekly
  seasonality (e.g. higher sales on weekends) while remaining lightweight and
  interpretable for a single-store deployment. Returned as `model_used: "holtwinters"`.
- **Optional: Prophet** — set `FORECAST_USE_PROPHET=true` to opt into the slower
  Prophet model. It overrides Holt-Winters when it fits successfully.
- **Fallback: Weighted Moving Average** — used automatically if Holt-Winters
  cannot fit degenerate/constant data, so the endpoint always returns a forecast.

The Rule Engine (safety stock, reorder point, max stock, current stock) then
converts the predicted demand into a suggested reorder quantity — matching the
architecture used by real-world DSS systems.
