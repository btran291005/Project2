export type CategoryType = 
  | 'Fresh Food' 
  | 'Beverages & Dairy' 
  | 'Instant Noodles & Ready Meals' 
  | 'Snacks & Confectionery' 
  | 'Bakery & Desserts' 
  | 'YOUUS Brand' 
  | 'Daily Essentials';

export type StockStatus = 'optimal' | 'low' | 'critical' | 'overstocked' | 'expiring';

export interface InventoryItem {
  id: string;
  sku: string;
  barcode: string;
  name: string;
  nameLocal?: string;
  category: CategoryType;
  currentStock: number;
  minThreshold: number;
  maxCapacity: number;
  unitCost: number;
  sellingPrice: number;
  dailyVelocity: number; // units sold per day average
  expiryDate: string; // ISO date or date string
  shelfLifeHours?: number;
  batchNumber: string;
  supplier: string;
  temperatureZone: 'Ambient' | 'Chilled (0-4°C)' | 'Frozen (-18°C)' | 'Hot Warmer';
  lastRestocked: string;
  forecastedDemandNext7Days: number;
  recommendedOrderQty: number;
  isPopularTrend?: boolean;
}

export interface PurchaseOrder {
  id: string;
  orderNumber: string;
  createdAt: string;
  expectedDelivery: string;
  supplier: string;
  items: {
    itemId: string;
    sku: string;
    name: string;
    category: CategoryType;
    quantity: number;
    unitCost: number;
    totalCost: number;
  }[];
  totalAmount: number;
  status: 'Draft' | 'Sent to GS Logistics' | 'In Transit' | 'Received & Stocked';
  notes?: string;
}

export interface StockMovementLog {
  id: string;
  timestamp: string;
  itemId: string;
  itemName: string;
  type: 'RECEIVING' | 'POS_SALE' | 'MARKDOWN_SALE' | 'WASTE_DISPOSAL' | 'MANUAL_ADJUSTMENT';
  quantityDelta: number;
  balanceAfter: number;
  operator: string;
  reason?: string;
}

export interface StoreBranch {
  id: string;
  name: string;
  code: string;
  address: string;
  manager: string;
  weatherCondition: 'Sunny & Hot (32°C)' | 'Heavy Rain (21°C)' | 'Cool & Clear (18°C)' | 'Cold & Snowy (1°C)';
  footTrafficLevel: 'Peak Commuter Surge' | 'Moderate Flow' | 'Night Owl Steady' | 'Quiet';
}

export interface ForecastFactor {
  weatherMultiplier: number;
  weekendMultiplier: number;
  promoMultiplier: number;
  trafficMultiplier: number;
}
