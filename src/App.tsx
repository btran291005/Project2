import React, { useState } from 'react';
import { InventoryItem, StoreBranch, StockMovementLog, PurchaseOrder } from './types';
import { INITIAL_BRANCHES, INITIAL_INVENTORY, INITIAL_MOVEMENT_LOGS, INITIAL_PURCHASE_ORDERS } from './data/mockData';
import { exportInventoryToCsv, getStockStatus, getHoursUntilExpiry } from './utils/inventoryUtils';
import { Header } from './components/Header';
import { DashboardStats } from './components/DashboardStats';
import { InventoryTable } from './components/InventoryTable';
import { DemandForecastPanel } from './components/DemandForecastPanel';
import { FreshGuardExpiryRadar } from './components/FreshGuardExpiryRadar';
import { PurchaseOrdersView } from './components/PurchaseOrdersView';
import { AuditLogView } from './components/AuditLogView';
import { BarcodeScannerModal } from './components/BarcodeScannerModal';
import { AddItemModal } from './components/AddItemModal';

export const App: React.FC = () => {
  const [branches] = useState<StoreBranch[]>(INITIAL_BRANCHES);
  const [currentBranch, setCurrentBranch] = useState<StoreBranch>(INITIAL_BRANCHES[0]);
  const [items, setItems] = useState<InventoryItem[]>(INITIAL_INVENTORY);
  const [movementLogs, setMovementLogs] = useState<StockMovementLog[]>(INITIAL_MOVEMENT_LOGS);
  const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrder[]>(INITIAL_PURCHASE_ORDERS);

  // App UI State
  const [activeTab, setActiveTab] = useState<'inventory' | 'forecast' | 'freshguard' | 'orders' | 'logs'>('inventory');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [currency, setCurrency] = useState<'KRW' | 'VND' | 'USD'>('KRW');

  // Modals
  const [isScannerOpen, setIsScannerOpen] = useState(false);
  const [isAddItemOpen, setIsAddItemOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<InventoryItem | null>(null);

  // Notification Toast
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => {
      setToastMessage((prev) => (prev === msg ? null : prev));
    }, 4000);
  };

  // Stock Delta Update Handler
  const handleUpdateStock = (itemId: string, delta: number, reason: string = 'Stock Adjustment') => {
    setItems((prevItems) =>
      prevItems.map((item) => {
        if (item.id === itemId) {
          const newStock = Math.max(0, Math.min(item.maxCapacity, item.currentStock + delta));
          const actualDelta = newStock - item.currentStock;

          // Log movement
          const newLog: StockMovementLog = {
            id: `log-${Date.now()}-${Math.random().toString(36).substring(2, 5)}`,
            timestamp: new Date().toISOString(),
            itemId: item.id,
            itemName: item.name,
            type: actualDelta < 0 ? 'POS_SALE' : 'MANUAL_ADJUSTMENT',
            quantityDelta: actualDelta,
            balanceAfter: newStock,
            operator: 'POS / Terminal Operator',
            reason,
          };
          setMovementLogs((prevLogs) => [newLog, ...prevLogs]);

          return {
            ...item,
            currentStock: newStock,
          };
        }
        return item;
      })
    );
  };

  // Quick Restock Handler
  const handleRestockItem = (itemId: string, amount: number) => {
    setItems((prevItems) =>
      prevItems.map((item) => {
        if (item.id === itemId) {
          const newStock = Math.min(item.maxCapacity, item.currentStock + amount);
          const actualDelta = newStock - item.currentStock;

          const newLog: StockMovementLog = {
            id: `log-${Date.now()}`,
            timestamp: new Date().toISOString(),
            itemId: item.id,
            itemName: item.name,
            type: 'RECEIVING',
            quantityDelta: actualDelta,
            balanceAfter: newStock,
            operator: 'Store Manager',
            reason: 'Shelf Rapid Restocking',
          };
          setMovementLogs((prevLogs) => [newLog, ...prevLogs]);
          showToast(`Restocked ${item.name} (+${actualDelta} units). New stock: ${newStock}`);

          return {
            ...item,
            currentStock: newStock,
            lastRestocked: new Date().toISOString(),
          };
        }
        return item;
      })
    );
  };

  // Markdown price discount handler
  const handleApplyMarkdown = (itemId: string, discountPercent: number) => {
    setItems((prevItems) =>
      prevItems.map((item) => {
        if (item.id === itemId) {
          const discountedPrice = Math.round(item.sellingPrice * ((100 - discountPercent) / 100));

          const newLog: StockMovementLog = {
            id: `log-${Date.now()}`,
            timestamp: new Date().toISOString(),
            itemId: item.id,
            itemName: item.name,
            type: 'MARKDOWN_SALE',
            quantityDelta: 0,
            balanceAfter: item.currentStock,
            operator: 'FreshGuard Scanner',
            reason: `Applied -${discountPercent}% Green Price Markdown (${discountedPrice} KRW)`,
          };
          setMovementLogs((prevLogs) => [newLog, ...prevLogs]);
          showToast(`Applied ${discountPercent}% Green Price Markdown for ${item.name}`);

          return {
            ...item,
            sellingPrice: discountedPrice,
          };
        }
        return item;
      })
    );
  };

  // Expired / Waste disposal handler
  const handleDisposeItem = (itemId: string, reason: string) => {
    setItems((prevItems) =>
      prevItems.map((item) => {
        if (item.id === itemId) {
          const qty = item.currentStock;
          const newLog: StockMovementLog = {
            id: `log-${Date.now()}`,
            timestamp: new Date().toISOString(),
            itemId: item.id,
            itemName: item.name,
            type: 'WASTE_DISPOSAL',
            quantityDelta: -qty,
            balanceAfter: 0,
            operator: 'Store Staff Audit',
            reason: `Write-off discard: ${reason}`,
          };
          setMovementLogs((prevLogs) => [newLog, ...prevLogs]);
          showToast(`Logged disposal of ${qty} units of ${item.name}`);

          return {
            ...item,
            currentStock: 0,
          };
        }
        return item;
      })
    );
  };

  // Save (Create or Edit) SKU
  const handleSaveItem = (savedItem: InventoryItem) => {
    setItems((prevItems) => {
      const exists = prevItems.some((i) => i.id === savedItem.id);
      if (exists) {
        showToast(`Updated SKU: ${savedItem.name}`);
        return prevItems.map((i) => (i.id === savedItem.id ? savedItem : i));
      } else {
        showToast(`Registered new SKU: ${savedItem.name}`);
        return [savedItem, ...prevItems];
      }
    });
    setEditingItem(null);
  };

  // Delete SKU
  const handleDeleteItem = (itemId: string) => {
    const item = items.find((i) => i.id === itemId);
    if (!item) return;
    if (confirm(`Are you sure you want to remove SKU "${item.name}" from catalog?`)) {
      setItems((prev) => prev.filter((i) => i.id !== itemId));
      showToast(`Removed SKU ${item.name} from catalog.`);
    }
  };

  // Generate Single PO from inventory table
  const handleGenerateSinglePO = (item: InventoryItem) => {
    const qty = Math.max(item.minThreshold * 2, 20);
    const newPO: PurchaseOrder = {
      id: `po-${Date.now()}`,
      orderNumber: `PO-GS-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}-${Math.floor(100 + Math.random() * 900)}`,
      createdAt: new Date().toISOString(),
      expectedDelivery: new Date(Date.now() + 24 * 3600 * 1000).toISOString(),
      supplier: item.supplier,
      items: [
        {
          itemId: item.id,
          sku: item.sku,
          name: item.name,
          category: item.category,
          quantity: qty,
          unitCost: item.unitCost,
          totalCost: qty * item.unitCost,
        },
      ],
      totalAmount: qty * item.unitCost,
      status: 'Sent to GS Logistics',
      notes: `Single SKU replenishment for ${item.name}`,
    };

    setPurchaseOrders((prev) => [newPO, ...prev]);
    setActiveTab('orders');
    showToast(`Created Purchase Order ${newPO.orderNumber} for ${item.name}`);
  };

  // Batch PO generated from AI Forecast
  const handleGenerateBatchPO = (po: PurchaseOrder) => {
    setPurchaseOrders((prev) => [po, ...prev]);
    setActiveTab('orders');
    showToast(`Successfully created AI Forecast Master Purchase Order (${po.items.length} SKUs)!`);
  };

  // Receive & Auto-Stock entire PO
  const handleReceiveAndStockOrder = (order: PurchaseOrder) => {
    // Increment stock for all items in order
    setItems((prevItems) =>
      prevItems.map((item) => {
        const orderLine = order.items.find((oi) => oi.itemId === item.id || oi.sku === item.sku);
        if (orderLine) {
          const newStock = Math.min(item.maxCapacity, item.currentStock + orderLine.quantity);
          return {
            ...item,
            currentStock: newStock,
            lastRestocked: new Date().toISOString(),
          };
        }
        return item;
      })
    );

    // Update order status
    setPurchaseOrders((prevOrders) =>
      prevOrders.map((o) => (o.id === order.id ? { ...o, status: 'Received & Stocked' } : o))
    );

    // Log receipts in audit
    const newLogs: StockMovementLog[] = order.items.map((line) => ({
      id: `log-${Date.now()}-${line.itemId}`,
      timestamp: new Date().toISOString(),
      itemId: line.itemId,
      itemName: line.name,
      type: 'RECEIVING',
      quantityDelta: line.quantity,
      balanceAfter: (items.find((i) => i.id === line.itemId)?.currentStock || 0) + line.quantity,
      operator: 'GS Logistics Delivery Inbound',
      reason: `Received Purchase Order ${order.orderNumber}`,
    }));

    setMovementLogs((prev) => [...newLogs, ...prev]);
    showToast(`Received & stocked Pallet for Order ${order.orderNumber}!`);
  };

  const handleUpdateOrderStatus = (orderId: string, status: PurchaseOrder['status']) => {
    setPurchaseOrders((prev) => prev.map((o) => (o.id === orderId ? { ...o, status } : o)));
    showToast(`Order status updated to: ${status}`);
  };

  // Counts for Badges
  const nearExpiryCount = items.filter((i) => {
    const h = getHoursUntilExpiry(i.expiryDate);
    return h !== null && h <= 8 && h > 0;
  }).length;

  const lowStockCount = items.filter((i) => {
    const s = getStockStatus(i);
    return s === 'low' || s === 'critical';
  }).length;

  return (
    <div className="min-h-screen bg-slate-100/70 text-slate-900 flex flex-col font-sans">
      {/* Top Application Header */}
      <Header
        branches={branches}
        currentBranch={currentBranch}
        onSelectBranch={(b) => {
          setCurrentBranch(b);
          showToast(`Switched active store to: ${b.name}`);
        }}
        currency={currency}
        onToggleCurrency={setCurrency}
        onOpenAddItem={() => {
          setEditingItem(null);
          setIsAddItemOpen(true);
        }}
        onOpenScanner={() => setIsScannerOpen(true)}
        onExportCsv={() => exportInventoryToCsv(items, currentBranch.name)}
        activeTab={activeTab}
        onChangeTab={setActiveTab}
        nearExpiryCount={nearExpiryCount}
        lowStockCount={lowStockCount}
      />

      {/* Main Content Area */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Top KPIs */}
        <DashboardStats
          items={items}
          currency={currency}
          onFilterStatus={(st) => {
            setStatusFilter(st);
            setActiveTab('inventory');
          }}
        />

        {/* Tab Switcher Content */}
        {activeTab === 'inventory' && (
          <InventoryTable
            items={items}
            currency={currency}
            statusFilter={statusFilter}
            onSelectStatusFilter={setStatusFilter}
            onUpdateStock={handleUpdateStock}
            onRestockItem={handleRestockItem}
            onEditItem={(item) => {
              setEditingItem(item);
              setIsAddItemOpen(true);
            }}
            onDeleteItem={handleDeleteItem}
            onGenerateSinglePO={handleGenerateSinglePO}
          />
        )}

        {activeTab === 'forecast' && (
          <DemandForecastPanel
            items={items}
            currentBranch={currentBranch}
            currency={currency}
            onGenerateBatchPO={handleGenerateBatchPO}
          />
        )}

        {activeTab === 'freshguard' && (
          <FreshGuardExpiryRadar
            items={items}
            currency={currency}
            onApplyMarkdown={handleApplyMarkdown}
            onDisposeItem={handleDisposeItem}
          />
        )}

        {activeTab === 'orders' && (
          <PurchaseOrdersView
            orders={purchaseOrders}
            items={items}
            currency={currency}
            onUpdateOrderStatus={handleUpdateOrderStatus}
            onReceiveAndStockOrder={handleReceiveAndStockOrder}
            onCreateCustomPO={(newPo) => {
              setPurchaseOrders((prev) => [newPo, ...prev]);
              showToast(`Created order: ${newPo.orderNumber}`);
            }}
          />
        )}

        {activeTab === 'logs' && <AuditLogView logs={movementLogs} />}
      </main>

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl text-xs font-semibold flex items-center gap-2 border border-slate-800 animate-in fade-in slide-in-from-bottom-3 duration-200">
          <span className="w-2 h-2 rounded-full bg-cyan-400 animate-ping" />
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Handheld Scanner Modal */}
      <BarcodeScannerModal
        isOpen={isScannerOpen}
        onClose={() => setIsScannerOpen(false)}
        items={items}
        currency={currency}
        onUpdateStock={handleUpdateStock}
        onApplyMarkdown={handleApplyMarkdown}
      />

      {/* Register / Edit SKU Modal */}
      <AddItemModal
        isOpen={isAddItemOpen}
        onClose={() => {
          setIsAddItemOpen(false);
          setEditingItem(null);
        }}
        onSaveItem={handleSaveItem}
        editingItem={editingItem}
      />

      {/* Footer */}
      <footer className="mt-auto border-t border-slate-200 bg-white py-4 text-center text-xs text-slate-500">
        <div className="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <span className="font-bold text-[#0075c9]">GS25 IntelliStock</span>
            <span>• Convenience Store Smart Retail & Replenishment Intelligence</span>
          </div>
          <p className="text-[11px] text-slate-400">
            Automated Inventory Control System • Synchronized with GS Retail Logistics EDI
          </p>
        </div>
      </footer>
    </div>
  );
};

export default App;
