import React, { useState } from 'react';
import { InventoryItem } from '../types';
import { getHoursUntilExpiry, getFreshFoodDiscountRecommendation, formatCurrency } from '../utils/inventoryUtils';
import { ShieldAlert, Clock, Tag, Trash2, Printer, CheckCircle2, AlertTriangle, Sparkles } from 'lucide-react';

interface FreshGuardExpiryRadarProps {
  items: InventoryItem[];
  currency: 'KRW' | 'VND' | 'USD';
  onApplyMarkdown: (itemId: string, discountPercent: number) => void;
  onDisposeItem: (itemId: string, reason: string) => void;
}

export const FreshGuardExpiryRadar: React.FC<FreshGuardExpiryRadarProps> = ({
  items,
  currency,
  onApplyMarkdown,
  onDisposeItem,
}) => {
  const [selectedTagItem, setSelectedTagItem] = useState<InventoryItem | null>(null);
  const [selectedDiscountPercent, setSelectedDiscountPercent] = useState<number>(30);

  // Filter fresh food and perishable products
  const perishableItems = items.filter((item) => {
    return item.category === 'Fresh Food' || item.category === 'Bakery & Desserts' || item.shelfLifeHours !== undefined;
  });

  const sortedPerishables = [...perishableItems].sort((a, b) => {
    return new Date(a.expiryDate).getTime() - new Date(b.expiryDate).getTime();
  });

  const criticalItems = sortedPerishables.filter((item) => {
    const h = getHoursUntilExpiry(item.expiryDate);
    return h !== null && h <= 3;
  });

  const warningItems = sortedPerishables.filter((item) => {
    const h = getHoursUntilExpiry(item.expiryDate);
    return h !== null && h > 3 && h <= 8;
  });

  const safeItems = sortedPerishables.filter((item) => {
    const h = getHoursUntilExpiry(item.expiryDate);
    return h === null || h > 8;
  });

  const handlePrintTag = (item: InventoryItem, discount: number) => {
    setSelectedTagItem(item);
    setSelectedDiscountPercent(discount);
  };

  return (
    <div className="space-y-6">
      {/* FreshGuard Header */}
      <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <div className="p-2 bg-emerald-50 text-emerald-700 rounded-lg border border-emerald-200">
                <ShieldAlert className="w-5 h-5" />
              </div>
              <div>
                <h2 className="text-lg font-bold text-slate-900">FreshGuard™ Expiry & Markdown Radar</h2>
                <p className="text-xs text-slate-500">
                  Automated shelf-life tracking for Kimbap, Dosirak, Sandwiches, and Fresh Bakery.
                </p>
              </div>
            </div>
          </div>

          {/* Quick Metrics */}
          <div className="flex items-center gap-3 text-xs">
            <div className="bg-rose-50 border border-rose-200 rounded-lg px-3 py-2 text-rose-800">
              <span className="font-bold text-base block">{criticalItems.length}</span>
              <span>Urgent (&lt;3h)</span>
            </div>
            <div className="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-amber-800">
              <span className="font-bold text-base block">{warningItems.length}</span>
              <span>Warning (3-8h)</span>
            </div>
            <div className="bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2 text-emerald-800">
              <span className="font-bold text-base block">{safeItems.length}</span>
              <span>Safe (&gt;8h)</span>
            </div>
          </div>
        </div>
      </div>

      {/* Fresh Food Priority Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {sortedPerishables.map((item) => {
          const hoursLeft = getHoursUntilExpiry(item.expiryDate);
          const recommendation = getFreshFoodDiscountRecommendation(item.expiryDate);
          const isCritical = hoursLeft !== null && hoursLeft <= 3;
          const isWarning = hoursLeft !== null && hoursLeft > 3 && hoursLeft <= 8;

          const discountedPrice30 = Math.round(item.sellingPrice * 0.7);
          const discountedPrice50 = Math.round(item.sellingPrice * 0.5);

          return (
            <div
              key={item.id}
              id={`freshguard-card-${item.id}`}
              className={`border rounded-xl p-4 transition-all flex flex-col justify-between shadow-xs ${
                isCritical
                  ? 'border-rose-300 bg-rose-50/30'
                  : isWarning
                  ? 'border-amber-300 bg-amber-50/30'
                  : 'border-slate-200 bg-white'
              }`}
            >
              <div>
                <div className="flex items-start justify-between gap-2">
                  <span className="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">
                    {item.category}
                  </span>
                  <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full border ${recommendation.badgeColor}`}>
                    {recommendation.label}
                  </span>
                </div>

                <h3 className="font-bold text-slate-900 text-sm mt-2">{item.name}</h3>
                {item.nameLocal && (
                  <p className="text-[11px] text-slate-500">{item.nameLocal}</p>
                )}

                <div className="mt-3 bg-white/80 p-2.5 rounded-lg border border-slate-200/80 space-y-1 text-xs">
                  <div className="flex items-center justify-between">
                    <span className="text-slate-500">Current Stock:</span>
                    <strong className="text-slate-900 font-bold">{item.currentStock} units</strong>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-500">Standard Price:</span>
                    <span className="font-semibold text-slate-800">{formatCurrency(item.sellingPrice, currency)}</span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-slate-500">Batch Code:</span>
                    <span className="font-mono text-slate-600">{item.batchNumber}</span>
                  </div>
                  <div className="flex items-center justify-between pt-1 border-t border-slate-100">
                    <span className="text-slate-500 flex items-center gap-1">
                      <Clock className="w-3 h-3 text-[#0075c9]" /> Expiry Timestamp:
                    </span>
                    <span className="font-mono text-slate-700">
                      {new Date(item.expiryDate).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} ({new Date(item.expiryDate).toLocaleDateString()})
                    </span>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="mt-4 pt-3 border-t border-slate-200/60 space-y-2">
                <div className="flex items-center gap-2">
                  <button
                    id={`btn-markdown-30-${item.id}`}
                    onClick={() => {
                      onApplyMarkdown(item.id, 30);
                      handlePrintTag(item, 30);
                    }}
                    className="flex-1 py-1.5 px-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 transition-colors"
                  >
                    <Tag className="w-3 h-3 text-amber-600" />
                    <span>-30% Sticker ({formatCurrency(discountedPrice30, currency)})</span>
                  </button>

                  <button
                    id={`btn-markdown-50-${item.id}`}
                    onClick={() => {
                      onApplyMarkdown(item.id, 50);
                      handlePrintTag(item, 50);
                    }}
                    className="flex-1 py-1.5 px-2 bg-rose-50 hover:bg-rose-100 text-rose-800 border border-rose-300 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 transition-colors"
                  >
                    <Tag className="w-3 h-3 text-rose-600" />
                    <span>-50% Clearance ({formatCurrency(discountedPrice50, currency)})</span>
                  </button>
                </div>

                <div className="flex items-center justify-between gap-2 pt-1">
                  <button
                    id={`btn-preview-tag-${item.id}`}
                    onClick={() => handlePrintTag(item, 30)}
                    className="text-xs text-slate-600 hover:text-slate-900 flex items-center gap-1 py-1 px-2 rounded hover:bg-slate-100 transition-colors"
                  >
                    <Printer className="w-3 h-3 text-slate-500" />
                    <span>Print Label</span>
                  </button>

                  <button
                    id={`btn-discard-${item.id}`}
                    onClick={() => onDisposeItem(item.id, 'Freshness standard elapsed')}
                    className="text-xs text-rose-600 hover:text-rose-800 flex items-center gap-1 py-1 px-2 rounded hover:bg-rose-50 transition-colors"
                  >
                    <Trash2 className="w-3 h-3" />
                    <span>Write-off / Dispose</span>
                  </button>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* Printable Discount Sticker Preview Modal */}
      {selectedTagItem && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-sm w-full p-5 shadow-2xl border border-slate-200 animate-in fade-in zoom-in duration-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div className="flex items-center gap-2">
                <div className="h-6 px-1.5 bg-[#0075c9] text-white rounded font-black text-xs flex items-center">
                  GS25
                </div>
                <h3 className="font-bold text-slate-900 text-sm">Green Price Sticker Label</h3>
              </div>
              <button
                onClick={() => setSelectedTagItem(null)}
                className="text-slate-400 hover:text-slate-600 font-bold text-lg"
              >
                ✕
              </button>
            </div>

            {/* Sticker physical preview */}
            <div className="my-4 p-4 border-2 border-dashed border-emerald-500 rounded-xl bg-emerald-50/40 text-center space-y-2">
              <div className="inline-block bg-emerald-600 text-white text-[11px] font-black uppercase tracking-widest px-3 py-1 rounded-full">
                GS25 Green Price • {selectedDiscountPercent}% OFF
              </div>
              <h4 className="font-bold text-slate-900 text-base">{selectedTagItem.name}</h4>
              <div className="flex items-center justify-center gap-3">
                <span className="line-through text-slate-400 text-xs">
                  {formatCurrency(selectedTagItem.sellingPrice, currency)}
                </span>
                <span className="text-emerald-700 font-black text-xl">
                  {formatCurrency(Math.round(selectedTagItem.sellingPrice * ((100 - selectedDiscountPercent) / 100)), currency)}
                </span>
              </div>
              <div className="font-mono text-[10px] text-slate-500 bg-white py-1 px-2 rounded border border-slate-200 inline-block">
                BARCODE: {selectedTagItem.barcode}-MD{selectedDiscountPercent}
              </div>
              <p className="text-[10px] text-emerald-800 font-medium">
                FreshGuard Guarantee: Scan at POS for instant promotional deduction
              </p>
            </div>

            <div className="flex items-center gap-2">
              <button
                onClick={() => {
                  alert(`Sent label print job to GS25 Thermal Sticker Printer for: ${selectedTagItem.name}`);
                  setSelectedTagItem(null);
                }}
                className="flex-1 py-2 bg-[#0075c9] hover:bg-[#0062a8] text-white font-bold rounded-lg text-xs flex items-center justify-center gap-2 shadow-xs transition-colors"
              >
                <Printer className="w-3.5 h-3.5" />
                <span>Send to POS Label Printer</span>
              </button>
              <button
                onClick={() => setSelectedTagItem(null)}
                className="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
