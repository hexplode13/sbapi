from sqlalchemy import Column, Integer, String, Boolean, DateTime, ForeignKey, Enum
from sqlalchemy.orm import relationship
from datetime import datetime
from database import Base
import enum

class OrderStatus(str, enum.Enum):
    QUEUED = "queued"
    ASSIGNED = "assigned"
    WAITING_WEIGHT = "waiting_weight"
    WEIGHT_DETECTED = "weight_detected"
    COMPLETED = "completed"
    CANCELLED = "cancelled"

class Panel(Base):
    __tablename__ = "panels"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    panel_number = Column(Integer, unique=True, nullable=False)
    name = Column(String(100), nullable=True)
    is_online = Column(Boolean, default=False)
    last_seen = Column(DateTime, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    
    orders = relationship("Order", back_populates="panel")

class Order(Base):
    __tablename__ = "orders"
    
    id = Column(Integer, primary_key=True, autoincrement=True)
    order_number = Column(String(50), nullable=False)
    customer_name = Column(String(100), nullable=False)
    panel_id = Column(Integer, ForeignKey("panels.id"), nullable=True)
    status = Column(Enum(OrderStatus), default=OrderStatus.QUEUED)
    created_at = Column(DateTime, default=datetime.utcnow)
    assigned_at = Column(DateTime, nullable=True)
    completed_at = Column(DateTime, nullable=True)
    
    panel = relationship("Panel", back_populates="orders")