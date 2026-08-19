import React from 'react';
import { InventoryItem } from '../types';
import { getStockStatus, getHoursUntilExpiry, formatCurrency } from '../utils/inventoryUtils';
import { Boxes, AlertTriangle, Clock, TrendingUp, DollarSign, CheckCircle2 } from 'lucide-react';

interface DashboardStatsProps {
  items: InventoryItem[];
  currency: 'KRW' | 'VND' | 'USD';
  onFilterStatus: (status: string) => void;
}

export const DashboardStats: React.FC<DashboardStatsProps> = ({
  items,
  currency,
  onFilterStatus,
}) => {
  const totalSkus = items.length;
  const totalStockUnits = items.reduce((acc, curr) => acc + curr.currentStock, 0);
  const totalValuation = items.reduce((acc, curr) => acc + curr.currentStock * curr.unitCost, 0);

  const lowStockItems = items.filter((item) => {
    const status = getStockStatus(item);
    return status === 'low' || status === 'critical';
  });

  const expiringItems = items.filter((item) => {
    const hours = getHoursUntilExpiry(item.expiryDate);
    return hours !== null && hours <= 8 && hours >= 0;
  });

  const optimalCount = items.filter((item) => getStockStatus(item) === 'optimal').length;
  const healthRate = totalSkus > 0 ? Math.round((optimalCount / totalSkus) * 100) : 100;

  return (
    <div className="grid grid-cols-2 lg:grid-cols-5 gap-3.5 sm:gap-4 mb-6">
      {/* 1. Total Stock & Valuation */}
      <div 
        id="stat-card-total-stock"
        onClick={() => onFilterStatus('all')}
        className="bg-white border border-slate-200 rounded-xl p-3.5 sm:p-4 shadow-xs hover:border-slate-300 cursor-pointer transition-all"
      >
        <div className="flex items-center justify-between text-slate-500 mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider">Total Inventory</span>
          <Boxes className="w-4 h-4 text-[#0075c9]" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-xl sm:text-2xl font-bold text-slate-900">{totalStockUnits}</span>
          <span className="text-xs text-slate-500 font-medium">units across {totalSkus} SKUs</span>
        </div>
        <p className="text-[11px] text-slate-500 mt-1.5 font-medium">
          Valuation: <strong className="text-slate-800">{formatCurrency(totalValuation, currency)}</strong>
        </p>
      </div>

      {/* 2. Stock Health Index */}
      <div 
        id="stat-card-health-rate"
        className="bg-white border border-slate-200 rounded-xl p-3.5 sm:p-4 shadow-xs"
      >
        <div className="flex items-center justify-between text-slate-500 mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider">Health Index</span>
          <CheckCircle2 className="w-4 h-4 text-emerald-600" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-xl sm:text-2xl font-bold text-slate-900">{healthRate}%</span>
          <span className="text-xs font-semibold text-emerald-600">Optimal Range</span>
        </div>
        <div className="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
          <div
            className="bg-emerald-500 h-full rounded-full transition-all duration-500"
            style={{ width: `${healthRate}%` }}
          />
        </div>
      </div>

      {/* 3. Low Stock & Reorder Alert */}
      <div 
        id="stat-card-low-stock"
        onClick={() => onFilterStatus('low')}
        className={`bg-white border rounded-xl p-3.5 sm:p-4 shadow-xs cursor-pointer transition-all ${
          lowStockItems.length > 0
            ? 'border-rose-200 bg-rose-50/30 hover:border-rose-300'
            : 'border-slate-200 hover:border-slate-300'
        }`}
      >
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider text-rose-700">
            Replenish Alert
          </span>
          <AlertTriangle className="w-4 h-4 text-rose-600" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-xl sm:text-2xl font-bold text-rose-600">{lowStockItems.length}</span>
          <span className="text-xs text-rose-700 font-medium">SKUs Below Safe Min</span>
        </div>
        <p className="text-[11px] text-slate-500 mt-1.5">
          {lowStockItems.length > 0 ? 'Click to inspect & reorder' : 'All stocks above threshold'}
        </p>
      </div>

      {/* 4. Expiry / FreshGuard Radar */}
      <div 
        id="stat-card-expiring"
        onClick={() => onFilterStatus('expiring')}
        className={`bg-white border rounded-xl p-3.5 sm:p-4 shadow-xs cursor-pointer transition-all ${
          expiringItems.length > 0
            ? 'border-amber-200 bg-amber-50/30 hover:border-amber-300'
            : 'border-slate-200 hover:border-slate-300'
        }`}
      >
        <div className="flex items-center justify-between mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider text-amber-800">
            FreshGuard Alert
          </span>
          <Clock className="w-4 h-4 text-amber-600" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-xl sm:text-2xl font-bold text-amber-700">{expiringItems.length}</span>
          <span className="text-xs text-amber-800 font-medium">Items &lt; 8h Left</span>
        </div>
        <p className="text-[11px] text-slate-500 mt-1.5">
          {expiringItems.length > 0 ? 'Auto-discount sticker recommended' : 'No urgent shelf-life risks'}
        </p>
      </div>

      {/* 5. Daily Sales Velocity */}
      <div 
        id="stat-card-velocity"
        className="bg-white border border-slate-200 rounded-xl p-3.5 sm:p-4 shadow-xs col-span-2 lg:col-span-1"
      >
        <div className="flex items-center justify-between text-slate-500 mb-2">
          <span className="text-xs font-semibold uppercase tracking-wider">Avg Daily Turn</span>
          <TrendingUp className="w-4 h-4 text-cyan-600" />
        </div>
        <div className="flex items-baseline gap-2">
          <span className="text-xl sm:text-2xl font-bold text-slate-900">
            {items.reduce((acc, c) => acc + c.dailyVelocity, 0)}
          </span>
          <span className="text-xs text-slate-500 font-medium">units / day</span>
        </div>
        <p className="text-[11px] text-cyan-700 font-medium mt-1.5 flex items-center gap-1">
          <span className="inline-block w-2 h-2 rounded-full bg-cyan-500 animate-ping" />
          Live POS synchronization active
        </p>
      </div>
    </div>
  );
};
