import React, { useState } from 'react';
import { InventoryItem, CategoryType, StockStatus } from '../types';
import { getStockStatus, getHoursUntilExpiry, formatCurrency, getFreshFoodDiscountRecommendation } from '../utils/inventoryUtils';
import { Search, Filter, Plus, Minus, PackagePlus, AlertCircle, Sparkles, Thermometer, ShieldAlert, Edit, Trash2 } from 'lucide-react';

interface InventoryTableProps {
  items: InventoryItem[];
  currency: 'KRW' | 'VND' | 'USD';
  statusFilter: string;
  onSelectStatusFilter: (status: string) => void;
  onUpdateStock: (itemId: string, delta: number, reason?: string) => void;
  onRestockItem: (itemId: string, amount: number) => void;
  onEditItem: (item: InventoryItem) => void;
  onDeleteItem: (itemId: string) => void;
  onGenerateSinglePO: (item: InventoryItem) => void;
}

const CATEGORIES: ('All' | CategoryType)[] = [
  'All',
  'Fresh Food',
  'Beverages & Dairy',
  'Instant Noodles & Ready Meals',
  'Snacks & Confectionery',
  'Bakery & Desserts',
  'YOUUS Brand',
  'Daily Essentials',
];

export const InventoryTable: React.FC<InventoryTableProps> = ({
  items,
  currency,
  statusFilter,
  onSelectStatusFilter,
  onUpdateStock,
  onRestockItem,
  onEditItem,
  onDeleteItem,
  onGenerateSinglePO,
}) => {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<'All' | CategoryType>('All');
  const [sortBy, setSortBy] = useState<'stockAsc' | 'stockDesc' | 'velocity' | 'expiry' | 'name'>('stockAsc');

  const filteredItems = items
    .filter((item) => {
      // Search
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchesName = item.name.toLowerCase().includes(q);
        const matchesLocal = item.nameLocal?.toLowerCase().includes(q) || false;
        const matchesSku = item.sku.toLowerCase().includes(q);
        const matchesBarcode = item.barcode.includes(q);
        if (!matchesName && !matchesLocal && !matchesSku && !matchesBarcode) {
          return false;
        }
      }
      // Category
      if (selectedCategory !== 'All' && item.category !== selectedCategory) {
        return false;
      }
      // Status Filter
      if (statusFilter !== 'all') {
        const itemStatus = getStockStatus(item);
        if (statusFilter === 'low' && itemStatus !== 'low' && itemStatus !== 'critical') return false;
        if (statusFilter === 'expiring' && itemStatus !== 'expiring') return false;
        if (statusFilter === 'optimal' && itemStatus !== 'optimal') return false;
        if (statusFilter === 'overstocked' && itemStatus !== 'overstocked') return false;
      }
      return true;
    })
    .sort((a, b) => {
      if (sortBy === 'stockAsc') return a.currentStock - b.currentStock;
      if (sortBy === 'stockDesc') return b.currentStock - a.currentStock;
      if (sortBy === 'velocity') return b.dailyVelocity - a.dailyVelocity;
      if (sortBy === 'name') return a.name.localeCompare(b.name);
      if (sortBy === 'expiry') {
        return new Date(a.expiryDate).getTime() - new Date(b.expiryDate).getTime();
      }
      return 0;
    });

  return (
    <div className="bg-white border border-slate-200 rounded-xl shadow-xs overflow-hidden">
      {/* Table Toolbar */}
      <div className="p-4 border-b border-slate-200 bg-slate-50/50 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
        {/* Search */}
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            id="inventory-search-input"
            type="text"
            placeholder="Search by SKU, product name, barcode..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full bg-white border border-slate-200 rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-hidden focus:border-[#0075c9] focus:ring-1 focus:ring-[#0075c9] transition-all"
          />
        </div>

        {/* Filter & Sort Controls */}
        <div className="flex flex-wrap items-center gap-2 text-xs">
          {/* Status Filter */}
          <div className="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-2 py-1.5">
            <span className="text-slate-500 font-medium">Status:</span>
            <select
              id="status-filter-select"
              value={statusFilter}
              onChange={(e) => onSelectStatusFilter(e.target.value)}
              className="font-semibold text-slate-800 focus:outline-hidden cursor-pointer"
            >
              <option value="all">All Statuses ({items.length})</option>
              <option value="low">Replenish Low / Out</option>
              <option value="expiring">FreshGuard Expiring</option>
              <option value="optimal">Optimal In-Stock</option>
              <option value="overstocked">Overstocked</option>
            </select>
          </div>

          {/* Sort By */}
          <div className="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-2 py-1.5">
            <span className="text-slate-500 font-medium">Sort:</span>
            <select
              id="sort-by-select"
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as any)}
              className="font-semibold text-slate-800 focus:outline-hidden cursor-pointer"
            >
              <option value="stockAsc">Stock: Lowest First</option>
              <option value="stockDesc">Stock: Highest First</option>
              <option value="velocity">Sales Velocity: Fast-Moving</option>
              <option value="expiry">Expiry: Earliest First</option>
              <option value="name">Product Name (A-Z)</option>
            </select>
          </div>
        </div>
      </div>

      {/* Category Pills */}
      <div className="px-4 py-2.5 border-b border-slate-100 bg-white flex items-center gap-1.5 overflow-x-auto scrollbar-none text-xs">
        {CATEGORIES.map((cat) => (
          <button
            key={cat}
            id={`category-pill-${cat.replace(/\s+/g, '-').toLowerCase()}`}
            onClick={() => setSelectedCategory(cat)}
            className={`px-2.5 py-1 rounded-full whitespace-nowrap font-medium transition-colors ${
              selectedCategory === cat
                ? 'bg-[#0075c9] text-white shadow-xs'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            }`}
          >
            {cat}
          </button>
        ))}
      </div>

      {/* Table Content */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs sm:text-sm border-collapse">
          <thead>
            <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
              <th className="py-3 px-4">Item & Code</th>
              <th className="py-3 px-3">Category / Zone</th>
              <th className="py-3 px-3">Stock Level & Cap</th>
              <th className="py-3 px-3">Price / Margin</th>
              <th className="py-3 px-3">Velocity & Forecast</th>
              <th className="py-3 px-3">Expiry / FreshGuard</th>
              <th className="py-3 px-4 text-right">Quick Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 font-normal text-slate-700">
            {filteredItems.length === 0 ? (
              <tr>
                <td colSpan={7} className="py-12 text-center text-slate-400">
                  <div className="flex flex-col items-center justify-center gap-2">
                    <AlertCircle className="w-6 h-6 text-slate-300" />
                    <p className="font-medium text-slate-600">No inventory items matched your criteria.</p>
                    <button
                      onClick={() => {
                        setSearchQuery('');
                        setSelectedCategory('All');
                        onSelectStatusFilter('all');
                      }}
                      className="text-xs text-[#0075c9] font-semibold hover:underline"
                    >
                      Reset all filters
                    </button>
                  </div>
                </td>
              </tr>
            ) : (
              filteredItems.map((item) => {
                const status = getStockStatus(item);
                const hoursLeft = getHoursUntilExpiry(item.expiryDate);
                const discount = getFreshFoodDiscountRecommendation(item.expiryDate);
                const fillPercent = Math.min(100, Math.round((item.currentStock / item.maxCapacity) * 100));

                return (
                  <tr
                    key={item.id}
                    id={`inventory-row-${item.id}`}
                    className={`hover:bg-slate-50/80 transition-colors ${
                      status === 'critical' ? 'bg-rose-50/20' : status === 'expiring' ? 'bg-amber-50/20' : ''
                    }`}
                  >
                    {/* Item & Code */}
                    <td className="py-3 px-4">
                      <div className="flex items-start gap-2.5">
                        <div className="flex-1 min-w-[180px]">
                          <div className="flex items-center gap-1.5">
                            <span className="font-bold text-slate-900 text-sm hover:text-[#0075c9] transition-colors">
                              {item.name}
                            </span>
                            {item.isPopularTrend && (
                              <span className="inline-flex items-center text-[10px] bg-rose-50 text-rose-600 border border-rose-200 px-1.5 py-0.2 rounded-sm font-bold">
                                HOT
                              </span>
                            )}
                          </div>
                          {item.nameLocal && (
                            <p className="text-[11px] text-slate-500">{item.nameLocal}</p>
                          )}
                          <div className="flex items-center gap-2 mt-1 text-[11px] text-slate-400">
                            <span className="font-mono bg-slate-100 px-1 py-0.5 rounded text-slate-600">
                              {item.sku}
                            </span>
                            <span>•</span>
                            <span className="font-mono">{item.barcode}</span>
                          </div>
                        </div>
                      </div>
                    </td>

                    {/* Category & Temp */}
                    <td className="py-3 px-3">
                      <div className="space-y-1">
                        <span className="inline-block text-[11px] font-semibold bg-slate-100 text-slate-700 px-2 py-0.5 rounded">
                          {item.category}
                        </span>
                        <div className="flex items-center gap-1 text-[11px] text-slate-500">
                          <Thermometer className="w-3 h-3 text-[#0075c9]" />
                          <span>{item.temperatureZone}</span>
                        </div>
                      </div>
                    </td>

                    {/* Stock Level & Visual Bar */}
                    <td className="py-3 px-3">
                      <div className="w-36 space-y-1">
                        <div className="flex items-center justify-between font-medium text-xs">
                          <span
                            className={`font-bold ${
                              item.currentStock === 0
                                ? 'text-rose-600'
                                : item.currentStock <= item.minThreshold
                                ? 'text-amber-600'
                                : 'text-slate-900'
                            }`}
                          >
                            {item.currentStock} in stock
                          </span>
                          <span className="text-[11px] text-slate-400">Max {item.maxCapacity}</span>
                        </div>
                        <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                          <div
                            className={`h-full rounded-full transition-all duration-300 ${
                              item.currentStock === 0
                                ? 'bg-rose-500'
                                : item.currentStock <= item.minThreshold
                                ? 'bg-amber-500'
                                : 'bg-[#0075c9]'
                            }`}
                            style={{ width: `${fillPercent}%` }}
                          />
                        </div>
                        <p className="text-[10px] text-slate-400">Safe Min: {item.minThreshold} units</p>
                      </div>
                    </td>

                    {/* Price & Margin */}
                    <td className="py-3 px-3">
                      <div>
                        <div className="font-semibold text-slate-900">
                          {formatCurrency(item.sellingPrice, currency)}
                        </div>
                        <div className="text-[11px] text-slate-500">
                          Cost: {formatCurrency(item.unitCost, currency)}
                        </div>
                        <div className="text-[10px] text-emerald-600 font-semibold">
                          +{Math.round(((item.sellingPrice - item.unitCost) / item.sellingPrice) * 100)}% margin
                        </div>
                      </div>
                    </td>

                    {/* Velocity & AI Forecast */}
                    <td className="py-3 px-3">
                      <div className="space-y-0.5">
                        <div className="font-semibold text-slate-800 text-xs">
                          {item.dailyVelocity} <span className="text-[11px] text-slate-500 font-normal">units/day</span>
                        </div>
                        <div className="inline-flex items-center gap-1 text-[10px] text-cyan-700 bg-cyan-50 px-1.5 py-0.5 rounded border border-cyan-200">
                          <Sparkles className="w-3 h-3 text-cyan-600" />
                          <span>7d Est: {item.forecastedDemandNext7Days}</span>
                        </div>
                      </div>
                    </td>

                    {/* Expiry & FreshGuard */}
                    <td className="py-3 px-3">
                      <div>
                        <span
                          className={`inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded border ${discount.badgeColor}`}
                        >
                          {discount.label}
                        </span>
                        <p className="text-[10px] text-slate-400 mt-1 font-mono">
                          Batch: {item.batchNumber}
                        </p>
                      </div>
                    </td>

                    {/* Actions */}
                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {/* Quick -1 / +1 */}
                        <div className="inline-flex items-center bg-slate-100 rounded-lg p-0.5 border border-slate-200">
                          <button
                            id={`btn-minus-${item.id}`}
                            onClick={() => onUpdateStock(item.id, -1, 'POS/Shelf Quick Decrease')}
                            disabled={item.currentStock <= 0}
                            title="Decrease Stock -1"
                            className="p-1 hover:bg-white text-slate-600 disabled:opacity-30 rounded transition-colors"
                          >
                            <Minus className="w-3 h-3" />
                          </button>
                          <button
                            id={`btn-plus-${item.id}`}
                            onClick={() => onUpdateStock(item.id, +1, 'Quick Stock Receipt +1')}
                            title="Increase Stock +1"
                            className="p-1 hover:bg-white text-slate-600 rounded transition-colors"
                          >
                            <Plus className="w-3 h-3" />
                          </button>
                        </div>

                        {/* Quick Restock (+10) */}
                        <button
                          id={`btn-restock-${item.id}`}
                          onClick={() => onRestockItem(item.id, 10)}
                          title="Restock +10 units"
                          className="p-1.5 text-[#0075c9] bg-blue-50 hover:bg-blue-100 rounded-lg border border-blue-200 transition-colors"
                        >
                          <PackagePlus className="w-3.5 h-3.5" />
                        </button>

                        {/* Auto-PO trigger */}
                        <button
                          id={`btn-po-${item.id}`}
                          onClick={() => onGenerateSinglePO(item)}
                          title="Create Purchase Order for this item"
                          className="px-2 py-1 text-[11px] font-semibold text-white bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors"
                        >
                          Order
                        </button>

                        {/* Edit */}
                        <button
                          id={`btn-edit-${item.id}`}
                          onClick={() => onEditItem(item)}
                          title="Edit Item Details"
                          className="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                        >
                          <Edit className="w-3.5 h-3.5" />
                        </button>

                        {/* Delete */}
                        <button
                          id={`btn-delete-${item.id}`}
                          onClick={() => onDeleteItem(item.id)}
                          title="Delete SKU"
                          className="p-1.5 text-slate-300 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};
