from pydantic import BaseModel
from typing import Optional
from datetime import datetime

class OrderCreate(BaseModel):
    order_number: str
    customer_name: str
    panel_number: Optional[int] = None  # Если не указан - автоматически назначится

class OrderResponse(BaseModel):
    id: int
    order_number: str
    customer_name: str
    status: str
    
    class Config:
        from_attributes = True

class PanelRegister(BaseModel):
    panel_number: int
    name: Optional[str] = None

class StatusUpdate(BaseModel):
    order_id: Optional[int] = None
    status: str
    weight: Optional[float] = None