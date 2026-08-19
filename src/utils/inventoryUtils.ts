import { InventoryItem, StockStatus, StoreBranch } from '../types';

export function getStockStatus(item: InventoryItem): StockStatus {
  const hoursLeft = getHoursUntilExpiry(item.expiryDate);
  if (hoursLeft !== null && hoursLeft <= 6 && hoursLeft > 0) {
    return 'expiring';
  }
  if (item.currentStock === 0) {
    return 'critical';
  }
  if (item.currentStock <= item.minThreshold) {
    return 'low';
  }
  if (item.currentStock >= item.maxCapacity * 0.9) {
    return 'overstocked';
  }
  return 'optimal';
}

export function getHoursUntilExpiry(expiryIso: string): number | null {
  try {
    const diffMs = new Date(expiryIso).getTime() - Date.now();
    return Math.round((diffMs / (1000 * 60 * 60)) * 10) / 10;
  } catch {
    return null;
  }
}

export function getFreshFoodDiscountRecommendation(expiryIso: string): {
  discountPercent: number;
  label: string;
  badgeColor: string;
  actionRequired: 'None' | 'Apply Green Price Sticker (30%)' | 'Apply Urgent Clearance (50%)' | 'Dispose / Write-off';
} {
  const hours = getHoursUntilExpiry(expiryIso);
  if (hours === null || hours > 24) {
    return { discountPercent: 0, label: 'Fresh & Safe', badgeColor: 'bg-emerald-50 text-emerald-700 border-emerald-200', actionRequired: 'None' };
  }
  if (hours > 8) {
    return { discountPercent: 0, label: `${Math.round(hours)}h shelf-life`, badgeColor: 'bg-blue-50 text-blue-700 border-blue-200', actionRequired: 'None' };
  }
  if (hours > 3) {
    return { discountPercent: 30, label: `Expiring in ${hours}h (-30%)`, badgeColor: 'bg-amber-50 text-amber-700 border-amber-300', actionRequired: 'Apply Green Price Sticker (30%)' };
  }
  if (hours > 0) {
    return { discountPercent: 50, label: `Urgent: ${hours}h (-50%)`, badgeColor: 'bg-rose-50 text-rose-700 border-rose-300', actionRequired: 'Apply Urgent Clearance (50%)' };
  }
  return { discountPercent: 100, label: 'Expired', badgeColor: 'bg-slate-900 text-white border-slate-900', actionRequired: 'Dispose / Write-off' };
}

export function calculateDemandForecast(
  item: InventoryItem,
  branch: StoreBranch,
  weatherImpact: boolean = true
): {
  forecastUnitsNext7Days: number;
  recommendedOrder: number;
  confidenceScore: number;
  keyDrivers: string[];
} {
  let multiplier = 1.0;
  const drivers: string[] = [];

  // Weather influence
  if (weatherImpact) {
    if (branch.weatherCondition.includes('Hot') || branch.weatherCondition.includes('Sunny')) {
      if (item.category === 'Beverages & Dairy' || item.temperatureZone === 'Chilled (0-4°C)') {
        multiplier *= 1.35;
        drivers.push('+35% High Temperature Beverage & Chilled surge');
      }
    } else if (branch.weatherCondition.includes('Rain') || branch.weatherCondition.includes('Cold')) {
      if (item.category === 'Instant Noodles & Ready Meals' || item.category === 'YOUUS Brand' || item.temperatureZone === 'Hot Warmer') {
        multiplier *= 1.4;
        drivers.push('+40% Rainy/Cold weather hot meal & ramen spike');
      }
    }
  }

  // Traffic surge influence
  if (branch.footTrafficLevel === 'Peak Commuter Surge') {
    if (item.category === 'Fresh Food' || item.category === 'Bakery & Desserts' || item.sku.includes('BV')) {
      multiplier *= 1.25;
      drivers.push('+25% Peak commuter grab-and-go demand');
    }
  }

  // Popular trend factor
  if (item.isPopularTrend) {
    multiplier *= 1.2;
    drivers.push('+20% Viral SNS trend & promotion item');
  }

  const baseDemand = item.dailyVelocity * 7;
  const forecastedDemand = Math.round(baseDemand * multiplier);

  // Recommended order calculation
  // Target stock = 3-day buffer for fresh foods, 7-day buffer for ambient
  const safetyDays = item.category === 'Fresh Food' ? 2 : 5;
  const targetBuffer = Math.round((forecastedDemand / 7) * safetyDays);
  const needed = Math.max(0, targetBuffer + item.minThreshold - item.currentStock);
  const recommendedOrder = Math.min(needed, Math.max(0, item.maxCapacity - item.currentStock));

  return {
    forecastUnitsNext7Days: forecastedDemand,
    recommendedOrder: Math.max(0, recommendedOrder),
    confidenceScore: 94,
    keyDrivers: drivers.length > 0 ? drivers : ['Standard daily historical velocity baseline'],
  };
}

export function formatCurrency(amount: number, currency: 'KRW' | 'VND' | 'USD' = 'KRW'): string {
  if (currency === 'KRW') {
    return `₩${amount.toLocaleString()}`;
  } else if (currency === 'VND') {
    return `${(amount * 18).toLocaleString()} ₫`;
  } else {
    return `$${(amount / 1300).toFixed(2)}`;
  }
}

export function exportInventoryToCsv(items: InventoryItem[], branchName: string) {
  const headers = ['SKU', 'Barcode', 'Product Name', 'Category', 'Current Stock', 'Min Threshold', 'Max Capacity', 'Unit Cost (KRW)', 'Selling Price (KRW)', 'Daily Velocity', 'Expiry Date', 'Batch', 'Supplier'];
  const rows = items.map(item => [
    item.sku,
    item.barcode,
    `"${item.name.replace(/"/g, '""')}"`,
    item.category,
    item.currentStock,
    item.minThreshold,
    item.maxCapacity,
    item.unitCost,
    item.sellingPrice,
    item.dailyVelocity,
    item.expiryDate,
    item.batchNumber,
    `"${item.supplier.replace(/"/g, '""')}"`,
  ]);

  const csvContent = [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', `GS25_Inventory_${branchName.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0, 10)}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
