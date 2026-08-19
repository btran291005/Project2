import React, { useState, useEffect } from 'react';
import { InventoryItem, CategoryType } from '../types';
import { PackagePlus, X, Save } from 'lucide-react';

interface AddItemModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSaveItem: (item: InventoryItem) => void;
  editingItem?: InventoryItem | null;
}

const CATEGORIES: CategoryType[] = [
  'Fresh Food',
  'Beverages & Dairy',
  'Instant Noodles & Ready Meals',
  'Snacks & Confectionery',
  'Bakery & Desserts',
  'YOUUS Brand',
  'Daily Essentials',
];

export const AddItemModal: React.FC<AddItemModalProps> = ({
  isOpen,
  onClose,
  onSaveItem,
  editingItem,
}) => {
  const [sku, setSku] = useState('');
  const [barcode, setBarcode] = useState('');
  const [name, setName] = useState('');
  const [nameLocal, setNameLocal] = useState('');
  const [category, setCategory] = useState<CategoryType>('Fresh Food');
  const [currentStock, setCurrentStock] = useState(10);
  const [minThreshold, setMinThreshold] = useState(5);
  const [maxCapacity, setMaxCapacity] = useState(40);
  const [unitCost, setUnitCost] = useState(1000);
  const [sellingPrice, setSellingPrice] = useState(1800);
  const [dailyVelocity, setDailyVelocity] = useState(10);
  const [expiryDays, setExpiryDays] = useState(2);
  const [temperatureZone, setTemperatureZone] = useState<InventoryItem['temperatureZone']>('Chilled (0-4°C)');
  const [supplier, setSupplier] = useState('GS Retail Central Logistics Center');
  const [isPopularTrend, setIsPopularTrend] = useState(false);

  useEffect(() => {
    if (editingItem) {
      setSku(editingItem.sku);
      setBarcode(editingItem.barcode);
      setName(editingItem.name);
      setNameLocal(editingItem.nameLocal || '');
      setCategory(editingItem.category);
      setCurrentStock(editingItem.currentStock);
      setMinThreshold(editingItem.minThreshold);
      setMaxCapacity(editingItem.maxCapacity);
      setUnitCost(editingItem.unitCost);
      setSellingPrice(editingItem.sellingPrice);
      setDailyVelocity(editingItem.dailyVelocity);
      setTemperatureZone(editingItem.temperatureZone);
      setSupplier(editingItem.supplier);
      setIsPopularTrend(!!editingItem.isPopularTrend);
    } else {
      // Defaults for new item
      const randomId = Math.floor(1000 + Math.random() * 9000);
      setSku(`GS-SKU-${randomId}`);
      setBarcode(`88010430${randomId}`);
      setName('');
      setNameLocal('');
      setCategory('Fresh Food');
      setCurrentStock(12);
      setMinThreshold(6);
      setMaxCapacity(30);
      setUnitCost(1200);
      setSellingPrice(2000);
      setDailyVelocity(8);
      setExpiryDays(2);
      setTemperatureZone('Chilled (0-4°C)');
      setSupplier('GS Retail Central Logistics Center');
      setIsPopularTrend(false);
    }
  }, [editingItem, isOpen]);

  if (!isOpen) return null;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;

    const expiryTimestamp = editingItem
      ? editingItem.expiryDate
      : new Date(Date.now() + expiryDays * 24 * 3600 * 1000).toISOString();

    const newItem: InventoryItem = {
      id: editingItem ? editingItem.id : `item-${Date.now()}`,
      sku: sku.trim() || `GS-SKU-${Date.now().toString().slice(-4)}`,
      barcode: barcode.trim() || `88010430${Math.floor(1000 + Math.random() * 9000)}`,
      name: name.trim(),
      nameLocal: nameLocal.trim() || undefined,
      category,
      currentStock,
      minThreshold,
      maxCapacity,
      unitCost,
      sellingPrice,
      dailyVelocity,
      expiryDate: expiryTimestamp,
      batchNumber: editingItem ? editingItem.batchNumber : `BT-${new Date().toISOString().slice(0, 10)}-${Math.floor(10 + Math.random() * 90)}`,
      supplier,
      temperatureZone,
      lastRestocked: new Date().toISOString(),
      forecastedDemandNext7Days: dailyVelocity * 7,
      recommendedOrderQty: Math.max(0, minThreshold * 2 - currentStock),
      isPopularTrend,
    };

    onSaveItem(newItem);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-slate-200 animate-in fade-in zoom-in duration-200 max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2">
            <div className="p-2 bg-blue-50 text-[#0075c9] rounded-lg">
              <PackagePlus className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-slate-900">
                {editingItem ? 'Edit Inventory SKU' : 'Register New GS25 SKU'}
              </h2>
              <p className="text-xs text-slate-500">Configure catalog metadata, shelf metrics, and baseline velocity.</p>
            </div>
          </div>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700 font-bold p-1">
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="mt-4 space-y-4 text-xs">
          {/* Names */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Product Title (English / Standard) *</label>
              <input
                id="input-item-name"
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Samgak Kimbap Tuna Mayo"
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium focus:outline-hidden focus:border-[#0075c9]"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Local Name / Subtitle</label>
              <input
                id="input-item-local-name"
                type="text"
                value={nameLocal}
                onChange={(e) => setNameLocal(e.target.value)}
                placeholder="e.g. 참치마요 삼각김밥"
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium focus:outline-hidden focus:border-[#0075c9]"
              />
            </div>
          </div>

          {/* Category & Temp */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Category *</label>
              <select
                id="select-item-category"
                value={category}
                onChange={(e) => setCategory(e.target.value as CategoryType)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium focus:outline-hidden"
              >
                {CATEGORIES.map((cat) => (
                  <option key={cat} value={cat}>
                    {cat}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Temperature Storage Zone</label>
              <select
                value={temperatureZone}
                onChange={(e) => setTemperatureZone(e.target.value as any)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium focus:outline-hidden"
              >
                <option value="Chilled (0-4°C)">Chilled (0-4°C)</option>
                <option value="Ambient">Ambient (Room Temp)</option>
                <option value="Frozen (-18°C)">Frozen (-18°C)</option>
                <option value="Hot Warmer">Hot Warmer / Heated Cabinet</option>
              </select>
            </div>
          </div>

          {/* Codes */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="font-semibold text-slate-700 block mb-1">SKU Code</label>
              <input
                type="text"
                value={sku}
                onChange={(e) => setSku(e.target.value)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-mono text-xs focus:outline-hidden"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Barcode (EAN-13)</label>
              <input
                type="text"
                value={barcode}
                onChange={(e) => setBarcode(e.target.value)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-mono text-xs focus:outline-hidden"
              />
            </div>
          </div>

          {/* Stock Quantities */}
          <div className="grid grid-cols-3 gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Current Stock</label>
              <input
                type="number"
                min="0"
                value={currentStock}
                onChange={(e) => setCurrentStock(parseInt(e.target.value) || 0)}
                className="w-full bg-white border border-slate-200 rounded-lg p-1.5 font-bold"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Min Threshold</label>
              <input
                type="number"
                min="1"
                value={minThreshold}
                onChange={(e) => setMinThreshold(parseInt(e.target.value) || 1)}
                className="w-full bg-white border border-slate-200 rounded-lg p-1.5 font-bold"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Max Capacity</label>
              <input
                type="number"
                min="5"
                value={maxCapacity}
                onChange={(e) => setMaxCapacity(parseInt(e.target.value) || 20)}
                className="w-full bg-white border border-slate-200 rounded-lg p-1.5 font-bold"
              />
            </div>
          </div>

          {/* Pricing & Velocity */}
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Unit Cost (KRW ₩)</label>
              <input
                type="number"
                min="0"
                step="50"
                value={unitCost}
                onChange={(e) => setUnitCost(parseInt(e.target.value) || 0)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Selling Price (KRW ₩)</label>
              <input
                type="number"
                min="0"
                step="50"
                value={sellingPrice}
                onChange={(e) => setSellingPrice(parseInt(e.target.value) || 0)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium"
              />
            </div>
            <div>
              <label className="font-semibold text-slate-700 block mb-1">Daily Velocity (Units/Day)</label>
              <input
                type="number"
                min="0"
                value={dailyVelocity}
                onChange={(e) => setDailyVelocity(parseInt(e.target.value) || 1)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-medium"
              />
            </div>
          </div>

          {/* Popular Tag Toggle */}
          <div className="flex items-center gap-2 pt-1">
            <input
              id="checkbox-is-hot-trend"
              type="checkbox"
              checked={isPopularTrend}
              onChange={(e) => setIsPopularTrend(e.target.checked)}
              className="rounded border-slate-300 text-[#0075c9] focus:ring-0"
            />
            <label htmlFor="checkbox-is-hot-trend" className="text-slate-700 font-medium cursor-pointer">
              Mark as Featured Trending / Promotion Item (+20% demand weighting)
            </label>
          </div>

          {/* Actions */}
          <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="px-5 py-2 bg-[#0075c9] hover:bg-[#0062a8] text-white rounded-lg font-bold flex items-center gap-1.5 shadow-xs"
            >
              <Save className="w-4 h-4" />
              <span>{editingItem ? 'Save SKU Changes' : 'Register SKU'}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
