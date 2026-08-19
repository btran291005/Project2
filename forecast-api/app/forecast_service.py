"""Demand forecasting with a robust, dependency-light fallback model.

Architecture
------------
Sales History
     |
     v
Forecast Module
  +-------------------------------+
  | Default: Holt-Winters          |
  | Optional: Prophet              |
  | Fallback: Weighted Moving Avg  |
  +-------------------------------+
     |
     v
Predicted Demand
     |
     v
Rule Engine (safety stock, ROP, lead time)
     |
     v
Suggested Reorder Quantity

The default model is Holt-Winters (Exponential Smoothing) because it captures
trend and weekly seasonality while remaining lightweight and interpretable for
a single-store deployment. Prophet is opt-in via FORECAST_USE_PROPHET=true.
If Holt-Winters cannot fit (e.g. degenerate/constant series), we fall back to
a weighted moving average so the endpoint always returns a useful forecast.
"""

from __future__ import annotations

import logging
import math
import os

import numpy as np
import pandas as pd
from statsmodels.tsa.holtwinters import ExponentialSmoothing

from app.schemas import ForecastPoint, ForecastRequest, ForecastResponse

logger = logging.getLogger("forecast_service")


def _build_prophet_model(category_type: str | None):
    from prophet import Prophet

    return Prophet(
        daily_seasonality=False,
        yearly_seasonality=False,
        weekly_seasonality=category_type != "Imported_Korean",
        seasonality_mode="multiplicative" if category_type == "Fresh_Food" else "additive",
        changepoint_prior_scale=0.1 if category_type == "Fresh_Food" else 0.05,
    )


def _clamped_point(day, prediction: float, spread: float) -> ForecastPoint:
    prediction = max(0.0, prediction)
    return ForecastPoint(
        forecast_date=day,
        predicted_quantity=round(prediction, 1),
        lower_bound=round(max(0.0, prediction - spread), 1),
        upper_bound=round(max(0.0, prediction + spread), 1),
    )


def _holtwinters_forecast(df: pd.DataFrame, request: ForecastRequest) -> list[ForecastPoint]:
    """Holt-Winters Exponential Smoothing.

    Uses additive trend + additive weekly seasonality (7-day period). This
    captures recent trends and the weekday pattern (e.g. Sat/Sun selling more)
    which plain weighted moving averages miss. If the series is constant or too
    short to estimate seasonality reliably, we fall back to a degenerate fit.
    """
    series = df.set_index("ds")["y"].astype(float)
    horizon = request.forecast_horizon_days

    # statsmodels requires a regular DateTimeIndex with a frequency.
    series = series.asfreq("D")
    series = series.fillna(0.0)

    if len(series) < 4:
        # Too little data -> simply repeat the most recent demand.
        level = float(series.iloc[-1]) if len(series) > 0 else 0.0
        spread = max(0.5, float(series.std(ddof=0)) if len(series) > 1 else 0.5)
        last_day = df["ds"].max()
        return [
            _clamped_point((last_day + pd.Timedelta(days=off)).date(), level, spread)
            for off in range(1, horizon + 1)
        ]

    # Try to detect if there is enough signal for a 7-day seasonal model.
    # A constant series (std == 0) cannot be fit with a seasonal model.
    nonzero = series[series > 0]
    # weekly seasonality only makes sense with >= 2 full weeks of data.
    use_seasonal = len(series) >= 14 and len(nonzero) >= 2

    try:
        if use_seasonal:
            model = ExponentialSmoothing(
                series,
                trend="add",
                seasonal="add",
                seasonal_periods=7,
                initialization_method="estimated",
            ).fit()
        else:
            model = ExponentialSmoothing(
                series,
                trend="add",
                seasonal=None,
                initialization_method="heuristic",
            ).fit()
    except Exception as e:  # noqa: BLE001 - statsmodels can raise on degenerate data
        logger.warning(
            "Holt-Winters fit failed for product_id=%s (%s) - falling back to "
            "weighted moving average. Reason: %s: %s",
            request.product_id,
            request.category_type,
            type(e).__name__,
            e,
        )
        return _moving_average_forecast(df, request)

    # Confidence spread: use the residual std of fitted values vs actuals.
    try:
        fitted = model.fittedvalues.reindex(series.index)
        residuals = (series - fitted).dropna()
        spread = max(0.5, float(residuals.std(ddof=0)) if len(residuals) >= 2 else 0.5)
    except Exception:  # noqa: BLE001
        spread = max(0.5, float(series.std(ddof=0)) if len(series) > 1 else 0.5)

    # Forecast horizon steps.
    forecast_idx = pd.date_range(start=series.index[-1] + pd.Timedelta(days=1), periods=horizon, freq="D")
    predictions = model.forecast(horizon)

    points: list[ForecastPoint] = []
    for i, day in enumerate(forecast_idx):
        pred = float(predictions.iloc[i])
        points.append(_clamped_point(day.date(), pred, spread))

    return points


def _moving_average_forecast(df: pd.DataFrame, request: ForecastRequest) -> list[ForecastPoint]:
    values = df["y"].astype(float)
    recent_7 = values.tail(7).mean()
    recent_28 = values.tail(min(28, len(values))).mean()
    baseline = 0.65 * recent_7 + 0.35 * recent_28

    # Robust spread for sparse data: use std of nonzero values first, falling
    # back to a small constant so the confidence band never collapses to zero
    # width when all recent history is zero (e.g. a brand-new slow item).
    nonzero = values[values > 0]
    if len(nonzero) >= 2:
        spread = max(0.5, float(nonzero.std(ddof=0)))
    else:
        spread = max(0.5, float(values.tail(min(28, len(values))).std(ddof=0) or 0.0))
    spread = max(0.5, spread)

    last_day = df["ds"].max()
    points: list[ForecastPoint] = []
    for offset in range(1, request.forecast_horizon_days + 1):
        day = last_day + pd.Timedelta(days=offset)
        same_weekday = df.loc[df["ds"].dt.dayofweek == day.dayofweek, "y"].tail(8)
        weekday_value = same_weekday.mean() if not same_weekday.empty else baseline
        points.append(_clamped_point(day.date(), 0.55 * weekday_value + 0.45 * baseline, spread))
    return points


def _prophet_forecast(df: pd.DataFrame, request: ForecastRequest) -> list[ForecastPoint]:
    model = _build_prophet_model(request.category_type)
    model.fit(df)
    result = model.predict(model.make_future_dataframe(periods=request.forecast_horizon_days)).tail(request.forecast_horizon_days)
    return [
        _clamped_point(row["ds"].date(), float(row["yhat"]), max(0.0, float(row["yhat_upper"] - row["yhat"])))
        for _, row in result.iterrows()
    ]


def run_forecast(request: ForecastRequest) -> ForecastResponse:
    df = pd.DataFrame([{"ds": item.sale_date, "y": item.quantity_sold} for item in request.sales_history])
    df["ds"] = pd.to_datetime(df["ds"])
    df = df.groupby("ds", as_index=False)["y"].sum().sort_values("ds")

    # Default model: Holt-Winters (Exponential Smoothing).
    model_used = "holtwinters"
    points = _holtwinters_forecast(df, request)

    # Optional: Prophet overrides the default when explicitly enabled.
    if os.getenv("FORECAST_USE_PROPHET", "false").lower() in {"1", "true", "yes"}:
        try:
            points = _prophet_forecast(df, request)
            model_used = "prophet"
        except Exception as e:  # noqa: BLE001 - any fit/predict failure -> fallback
            logger.warning(
                "Prophet fit/predict failed for product_id=%s (%s) - falling back to "
                "Holt-Winters. Reason: %s: %s",
                request.product_id,
                request.category_type,
                type(e).__name__,
                e,
            )

    forecasted_demand = round(sum(point.predicted_quantity for point in points), 1)
    suggested_qty = max(0, math.ceil(forecasted_demand + request.safety_stock - request.current_stock))
    if request.max_stock > 0:
        suggested_qty = min(suggested_qty, max(0, request.max_stock - request.current_stock))

    return ForecastResponse(
        product_id=request.product_id,
        forecast=points,
        forecasted_demand=forecasted_demand,
        suggested_reorder_quantity=suggested_qty,
        model_used=model_used,
    )
