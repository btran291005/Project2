import React, { useState } from 'react';
import { InventoryItem } from '../types';
import { formatCurrency, getStockStatus, getHoursUntilExpiry } from '../utils/inventoryUtils';
import { Barcode, Search, CheckCircle, Plus, Minus, PackageCheck, AlertCircle, ScanLine, Tag } from 'lucide-react';

interface BarcodeScannerModalProps {
  isOpen: boolean;
  onClose: () => void;
  items: InventoryItem[];
  currency: 'KRW' | 'VND' | 'USD';
  onUpdateStock: (itemId: string, delta: number, reason: string) => void;
  onApplyMarkdown: (itemId: string, discount: number) => void;
}

export const BarcodeScannerModal: React.FC<BarcodeScannerModalProps> = ({
  isOpen,
  onClose,
  items,
  currency,
  onUpdateStock,
  onApplyMarkdown,
}) => {
  const [scannedCode, setScannedCode] = useState('');
  const [matchedItem, setMatchedItem] = useState<InventoryItem | null>(null);
  const [lastActionMsg, setLastActionMsg] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleScanInput = (code: string) => {
    setScannedCode(code);
    const found = items.find((i) => i.barcode === code.trim() || i.sku.toLowerCase() === code.trim().toLowerCase());
    if (found) {
      setMatchedItem(found);
      setLastActionMsg(`Found SKU: ${found.name}`);
    } else {
      setMatchedItem(null);
    }
  };

  const handleQuickPreset = (item: InventoryItem) => {
    setScannedCode(item.barcode);
    setMatchedItem(item);
    setLastActionMsg(`Scanned: ${item.name}`);
  };

  const handleAction = (delta: number, actionName: string) => {
    if (!matchedItem) return;
    onUpdateStock(matchedItem.id, delta, `Barcode Terminal: ${actionName}`);
    // update local preview stock
    setMatchedItem({
      ...matchedItem,
      currentStock: Math.max(0, matchedItem.currentStock + delta),
    });
    setLastActionMsg(`Updated ${matchedItem.name}: ${delta > 0 ? `+${delta}` : delta} units (${actionName})`);
  };

  return (
    <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl border border-slate-200 animate-in fade-in zoom-in duration-200">
        {/* Terminal Header */}
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-slate-900 text-cyan-400 rounded-lg">
              <Barcode className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">GS25 Handheld Scanner Terminal</h2>
              <p className="text-xs text-slate-500">Wireless inventory stocktaking & fast audit terminal</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-700 font-bold text-lg p-1"
          >
            ✕
          </button>
        </div>

        {/* Scanner Simulation Box */}
        <div className="my-4 bg-slate-900 rounded-xl p-4 text-white relative overflow-hidden border border-slate-800">
          <div className="flex items-center justify-between mb-2">
            <span className="text-[11px] text-cyan-400 font-mono flex items-center gap-1.5">
              <ScanLine className="w-3.5 h-3.5 animate-pulse" /> OPTICAL SCANNER ACTIVE (EAN-13 / CODE-128)
            </span>
            <span className="text-[10px] text-slate-400">GS-OS v4.2</span>
          </div>

          <div className="relative">
            <input
              id="terminal-barcode-input"
              type="text"
              placeholder="Scan or type barcode / SKU..."
              value={scannedCode}
              onChange={(e) => handleScanInput(e.target.value)}
              className="w-full bg-slate-800/90 text-white font-mono text-base border border-slate-700 rounded-lg pl-3 pr-24 py-2.5 focus:outline-hidden focus:border-cyan-400 transition-colors"
              autoFocus
            />
            {scannedCode && (
              <button
                onClick={() => {
                  setScannedCode('');
                  setMatchedItem(null);
                  setLastActionMsg(null);
                }}
                className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-white bg-slate-700 px-2 py-1 rounded"
              >
                Clear
              </button>
            )}
          </div>

          {/* Quick preset barcode buttons for fast test */}
          <div className="mt-3">
            <span className="text-[11px] text-slate-400 block mb-1.5">Quick Demo Barcode Triggers:</span>
            <div className="flex flex-wrap gap-1.5">
              {items.slice(0, 5).map((item) => (
                <button
                  key={item.id}
                  onClick={() => handleQuickPreset(item)}
                  className="text-[11px] bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 px-2 py-1 rounded transition-colors font-mono"
                >
                  {item.name.slice(0, 16)}...
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Scan Result Details */}
        {matchedItem ? (
          <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
            <div className="flex items-start justify-between">
              <div>
                <span className="text-[11px] font-bold text-[#0075c9] bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">
                  {matchedItem.category}
                </span>
                <h3 className="font-bold text-slate-900 text-base mt-1">{matchedItem.name}</h3>
                <p className="text-xs text-slate-500 font-mono">
                  SKU: {matchedItem.sku} | Barcode: {matchedItem.barcode}
                </p>
              </div>

              <div className="text-right">
                <span className="text-xs text-slate-500 block">Current Stock</span>
                <strong className="text-2xl font-black text-slate-900">
                  {matchedItem.currentStock}{' '}
                  <span className="text-xs font-normal text-slate-500">/ {matchedItem.maxCapacity}</span>
                </strong>
              </div>
            </div>

            {/* Quick Action Buttons */}
            <div className="pt-3 border-t border-slate-200 flex flex-wrap items-center gap-2">
              <span className="text-xs font-semibold text-slate-600 mr-1">Terminal Actions:</span>

              <button
                onClick={() => handleAction(-1, 'Customer POS Sale')}
                className="px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-100 text-slate-800 font-semibold rounded-lg text-xs flex items-center gap-1 shadow-2xs"
              >
                <Minus className="w-3.5 h-3.5 text-rose-500" />
                <span>Sell 1</span>
              </button>

              <button
                onClick={() => handleAction(+1, 'Single Inbound Scan')}
                className="px-3 py-1.5 bg-white border border-slate-200 hover:bg-slate-100 text-slate-800 font-semibold rounded-lg text-xs flex items-center gap-1 shadow-2xs"
              >
                <Plus className="w-3.5 h-3.5 text-emerald-600" />
                <span>Receive +1</span>
              </button>

              <button
                onClick={() => handleAction(+12, 'Full Pack / Case Inbound')}
                className="px-3 py-1.5 bg-[#0075c9] hover:bg-[#0062a8] text-white font-semibold rounded-lg text-xs flex items-center gap-1 shadow-2xs"
              >
                <PackageCheck className="w-3.5 h-3.5" />
                <span>Restock Case (+12)</span>
              </button>

              <button
                onClick={() => {
                  onApplyMarkdown(matchedItem.id, 30);
                  setLastActionMsg(`Applied -30% Markdown on ${matchedItem.name}`);
                }}
                className="px-3 py-1.5 bg-amber-50 border border-amber-300 hover:bg-amber-100 text-amber-800 font-semibold rounded-lg text-xs flex items-center gap-1"
              >
                <Tag className="w-3.5 h-3.5 text-amber-600" />
                <span>Apply -30% Markdown</span>
              </button>
            </div>
          </div>
        ) : (
          <div className="py-8 text-center text-slate-400 border border-dashed border-slate-200 rounded-xl">
            <p className="text-xs font-medium">Scan any product barcode or click a demo trigger above.</p>
          </div>
        )}

        {/* Action feedback message */}
        {lastActionMsg && (
          <div className="mt-3 p-2.5 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-lg text-xs font-medium flex items-center gap-2">
            <CheckCircle className="w-4 h-4 text-emerald-600 shrink-0" />
            <span>{lastActionMsg}</span>
          </div>
        )}

        {/* Modal Footer */}
        <div className="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
          <span>GS25 IntelliStock Terminal Mode</span>
          <button
            onClick={onClose}
            className="px-4 py-2 bg-slate-900 text-white rounded-lg font-semibold hover:bg-slate-800 transition-colors"
          >
            Done Stocktaking
          </button>
        </div>
      </div>
    </div>
  );
};
