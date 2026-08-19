import React from 'react';
import { StoreBranch } from '../types';
import { Store, Bell, Plus, Download, Sparkles, CloudSun, RefreshCw, Barcode } from 'lucide-react';

interface HeaderProps {
  branches: StoreBranch[];
  currentBranch: StoreBranch;
  onSelectBranch: (branch: StoreBranch) => void;
  currency: 'KRW' | 'VND' | 'USD';
  onToggleCurrency: (curr: 'KRW' | 'VND' | 'USD') => void;
  onOpenAddItem: () => void;
  onOpenScanner: () => void;
  onExportCsv: () => void;
  activeTab: 'inventory' | 'forecast' | 'freshguard' | 'orders' | 'logs';
  onChangeTab: (tab: 'inventory' | 'forecast' | 'freshguard' | 'orders' | 'logs') => void;
  nearExpiryCount: number;
  lowStockCount: number;
}

export const Header: React.FC<HeaderProps> = ({
  branches,
  currentBranch,
  onSelectBranch,
  currency,
  onToggleCurrency,
  onOpenAddItem,
  onOpenScanner,
  onExportCsv,
  activeTab,
  onChangeTab,
  nearExpiryCount,
  lowStockCount,
}) => {
  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        {/* Top bar with Branding & Store selector */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between py-3 gap-3 border-b border-slate-100">
          <div className="flex items-center gap-3">
            {/* GS25 Signature Badge */}
            <div className="flex items-center gap-2">
              <div className="h-9 px-2.5 bg-[#0075c9] text-white rounded-lg flex items-center justify-center font-extrabold tracking-tight text-lg shadow-sm">
                GS<span className="text-[#00c7e5] font-black">25</span>
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <h1 className="text-lg font-bold text-slate-900 leading-tight">IntelliStock</h1>
                  <span className="inline-flex items-center gap-1 text-[11px] font-semibold bg-cyan-50 text-[#0075c9] px-2 py-0.5 rounded-full border border-cyan-200">
                    <Sparkles className="w-3 h-3 text-[#00c7e5]" /> AI Inventory OS
                  </span>
                </div>
                <p className="text-xs text-slate-500 hidden sm:block">
                  Predictive Store Replenishment & Expiry Guard
                </p>
              </div>
            </div>
          </div>

          {/* Branch & Quick Status */}
          <div className="flex flex-wrap items-center gap-2.5 sm:gap-3">
            {/* Branch Selector */}
            <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-700">
              <Store className="w-4 h-4 text-[#0075c9]" />
              <select
                id="branch-selector"
                value={currentBranch.id}
                onChange={(e) => {
                  const b = branches.find((item) => item.id === e.target.value);
                  if (b) onSelectBranch(b);
                }}
                className="bg-transparent font-semibold text-slate-800 focus:outline-hidden cursor-pointer"
              >
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </div>

            {/* Weather condition indicator */}
            <div className="hidden lg:flex items-center gap-1.5 bg-amber-50 text-amber-800 border border-amber-200 rounded-lg px-2.5 py-1.5 text-xs font-medium">
              <CloudSun className="w-3.5 h-3.5 text-amber-600" />
              <span>{currentBranch.weatherCondition}</span>
            </div>

            {/* Currency switcher */}
            <div className="flex items-center bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-xs">
              {(['KRW', 'VND', 'USD'] as const).map((curr) => (
                <button
                  key={curr}
                  id={`curr-toggle-${curr}`}
                  onClick={() => onToggleCurrency(curr)}
                  className={`px-2 py-1 rounded-md font-semibold transition-colors ${
                    currency === curr
                      ? 'bg-white text-slate-900 shadow-xs'
                      : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  {curr === 'KRW' ? '₩ KRW' : curr === 'VND' ? '₫ VND' : '$ USD'}
                </button>
              ))}
            </div>

            {/* Action buttons */}
            <button
              id="header-scanner-btn"
              onClick={onOpenScanner}
              className="inline-flex items-center gap-1.5 bg-slate-900 text-white hover:bg-slate-800 text-xs font-semibold px-3 py-1.5 rounded-lg shadow-xs transition-colors"
            >
              <Barcode className="w-3.5 h-3.5 text-cyan-400" />
              <span>Barcode Terminal</span>
            </button>

            <button
              id="header-add-item-btn"
              onClick={onOpenAddItem}
              className="inline-flex items-center gap-1 bg-[#0075c9] text-white hover:bg-[#0062a8] text-xs font-semibold px-3 py-1.5 rounded-lg shadow-xs transition-colors"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Add SKU</span>
            </button>

            <button
              id="header-export-btn"
              onClick={onExportCsv}
              title="Export Inventory CSV"
              className="p-1.5 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg border border-slate-200 transition-colors"
            >
              <Download className="w-4 h-4" />
            </button>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex items-center space-x-1 sm:space-x-2 overflow-x-auto py-2 scrollbar-none text-xs sm:text-sm font-medium">
          <button
            id="nav-tab-inventory"
            onClick={() => onChangeTab('inventory')}
            className={`px-3 py-2 rounded-lg whitespace-nowrap transition-colors flex items-center gap-2 ${
              activeTab === 'inventory'
                ? 'bg-blue-50 text-[#0075c9] font-bold border border-blue-200'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>Inventory Catalog</span>
            {lowStockCount > 0 && (
              <span className="bg-rose-500 text-white text-[10px] font-bold px-1.5 py-0.2 rounded-full">
                {lowStockCount}
              </span>
            )}
          </button>

          <button
            id="nav-tab-forecast"
            onClick={() => onChangeTab('forecast')}
            className={`px-3 py-2 rounded-lg whitespace-nowrap transition-colors flex items-center gap-1.5 ${
              activeTab === 'forecast'
                ? 'bg-cyan-50 text-cyan-800 font-bold border border-cyan-200'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <Sparkles className="w-3.5 h-3.5 text-cyan-600" />
            <span>AI Demand Forecast & Auto-PO</span>
          </button>

          <button
            id="nav-tab-freshguard"
            onClick={() => onChangeTab('freshguard')}
            className={`px-3 py-2 rounded-lg whitespace-nowrap transition-colors flex items-center gap-2 ${
              activeTab === 'freshguard'
                ? 'bg-emerald-50 text-emerald-800 font-bold border border-emerald-200'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>FreshGuard™ Expiry Radar</span>
            {nearExpiryCount > 0 && (
              <span className="bg-amber-500 text-white text-[10px] font-bold px-1.5 py-0.2 rounded-full animate-pulse">
                {nearExpiryCount} Alert
              </span>
            )}
          </button>

          <button
            id="nav-tab-orders"
            onClick={() => onChangeTab('orders')}
            className={`px-3 py-2 rounded-lg whitespace-nowrap transition-colors flex items-center gap-1.5 ${
              activeTab === 'orders'
                ? 'bg-blue-50 text-[#0075c9] font-bold border border-blue-200'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>Logistics Orders (EDI)</span>
          </button>

          <button
            id="nav-tab-logs"
            onClick={() => onChangeTab('logs')}
            className={`px-3 py-2 rounded-lg whitespace-nowrap transition-colors flex items-center gap-1.5 ${
              activeTab === 'logs'
                ? 'bg-slate-200 text-slate-900 font-bold'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>Audit Trail</span>
          </button>
        </div>
      </div>
    </header>
  );
};
