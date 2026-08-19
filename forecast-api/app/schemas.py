from datetime import date
from typing import Optional, List

try:
    from pydantic import BaseModel, Field, field_validator

    class SalesHistoryPoint(BaseModel):
        sale_date: date
        quantity_sold: float = Field(..., ge=0)

    class ForecastRequest(BaseModel):
        """The PHP application supplies a consecutive daily sales series."""

        product_id: int = Field(..., gt=0)
        category_type: Optional[str] = None
        sales_history: List[SalesHistoryPoint] = Field(...)
        forecast_horizon_days: int = Field(default=7, ge=1, le=30)
        current_stock: int = Field(default=0, ge=0)
        safety_stock: int = Field(default=0, ge=0)
        max_stock: int = Field(default=0, ge=0)

        @field_validator("sales_history")
        @classmethod
        def must_have_enough_history(cls, value: List[SalesHistoryPoint]) -> List[SalesHistoryPoint]:
            if len(value) < 14:
                raise ValueError("At least 14 days of daily sales history are required.")
            return value

except ImportError:
    from pydantic import BaseModel, Field, validator

    class SalesHistoryPoint(BaseModel):
        sale_date: date
        quantity_sold: float = Field(..., ge=0)

    class ForecastRequest(BaseModel):
        """The PHP application supplies a consecutive daily sales series."""

        product_id: int = Field(..., gt=0)
        category_type: Optional[str] = None
        sales_history: List[SalesHistoryPoint] = Field(...)
        forecast_horizon_days: int = Field(default=7, ge=1, le=30)
        current_stock: int = Field(default=0, ge=0)
        safety_stock: int = Field(default=0, ge=0)
        max_stock: int = Field(default=0, ge=0)

        @validator("sales_history")
        def must_have_enough_history(cls, value: List[SalesHistoryPoint]) -> List[SalesHistoryPoint]:
            if len(value) < 14:
                raise ValueError("At least 14 days of daily sales history are required.")
            return value


class ForecastPoint(BaseModel):
    forecast_date: date
    predicted_quantity: float = Field(..., ge=0)
    lower_bound: float = Field(..., ge=0)
    upper_bound: float = Field(..., ge=0)


class ForecastResponse(BaseModel):
    product_id: int
    forecast: List[ForecastPoint]
    forecasted_demand: float = Field(..., ge=0)
    suggested_reorder_quantity: int = Field(..., ge=0)
    model_used: str
