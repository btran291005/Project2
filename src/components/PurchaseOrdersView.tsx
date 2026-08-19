import React, { useState } from 'react';
import { PurchaseOrder, InventoryItem } from '../types';
import { formatCurrency } from '../utils/inventoryUtils';
import { ShoppingBag, Truck, CheckCircle2, Clock, Send, Eye, FileText, Plus, ChevronDown, ChevronUp } from 'lucide-react';

interface PurchaseOrdersViewProps {
  orders: PurchaseOrder[];
  items: InventoryItem[];
  currency: 'KRW' | 'VND' | 'USD';
  onUpdateOrderStatus: (orderId: string, status: PurchaseOrder['status']) => void;
  onReceiveAndStockOrder: (order: PurchaseOrder) => void;
  onCreateCustomPO: (po: PurchaseOrder) => void;
}

export const PurchaseOrdersView: React.FC<PurchaseOrdersViewProps> = ({
  orders,
  items,
  currency,
  onUpdateOrderStatus,
  onReceiveAndStockOrder,
  onCreateCustomPO,
}) => {
  const [expandedOrderId, setExpandedOrderId] = useState<string | null>(orders[0]?.id || null);
  const [filterStatus, setFilterStatus] = useState<string>('all');
  const [showNewPOModal, setShowNewPOModal] = useState(false);

  // Simple state for creating a new custom PO
  const [selectedSupplier, setSelectedSupplier] = useState('GS Retail Central Logistics');
  const [selectedSkuId, setSelectedSkuId] = useState(items[0]?.id || '');
  const [orderQty, setOrderQty] = useState(20);

  const filteredOrders = orders.filter((o) => {
    if (filterStatus === 'all') return true;
    return o.status === filterStatus;
  });

  const handleCreateSimplePO = (e: React.FormEvent) => {
    e.preventDefault();
    const item = items.find((i) => i.id === selectedSkuId);
    if (!item) return;

    const newPO: PurchaseOrder = {
      id: `po-${Date.now()}`,
      orderNumber: `PO-GS-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}-${Math.floor(100 + Math.random() * 900)}`,
      createdAt: new Date().toISOString(),
      expectedDelivery: new Date(Date.now() + 24 * 3600 * 1000).toISOString(),
      supplier: selectedSupplier,
      items: [
        {
          itemId: item.id,
          sku: item.sku,
          name: item.name,
          category: item.category,
          quantity: orderQty,
          unitCost: item.unitCost,
          totalCost: orderQty * item.unitCost,
        },
      ],
      totalAmount: orderQty * item.unitCost,
      status: 'Sent to GS Logistics',
      notes: 'Store manager manual direct order',
    };

    onCreateCustomPO(newPO);
    setShowNewPOModal(false);
  };

  return (
    <div className="space-y-6">
      {/* Header & Controls */}
      <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <div className="p-2 bg-blue-50 text-[#0075c9] rounded-lg border border-blue-200">
              <Truck className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-lg font-bold text-slate-900">GS Logistics EDI & Purchase Orders</h2>
              <p className="text-xs text-slate-500">
                Automated replenishment routing, EDI status tracking, and 1-click pallet receipt.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2.5">
          <select
            id="order-status-filter"
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}
            className="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-800 focus:outline-hidden cursor-pointer"
          >
            <option value="all">All Orders ({orders.length})</option>
            <option value="Draft">Drafts</option>
            <option value="Sent to GS Logistics">Sent to Logistics</option>
            <option value="In Transit">In Transit</option>
            <option value="Received & Stocked">Received & Stocked</option>
          </select>

          <button
            id="btn-create-manual-po"
            onClick={() => setShowNewPOModal(true)}
            className="px-3 py-1.5 bg-[#0075c9] hover:bg-[#0062a8] text-white text-xs font-semibold rounded-lg flex items-center gap-1.5 shadow-xs transition-colors"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>New Purchase Order</span>
          </button>
        </div>
      </div>

      {/* Orders List */}
      <div className="space-y-3">
        {filteredOrders.length === 0 ? (
          <div className="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-400">
            <ShoppingBag className="w-8 h-8 mx-auto text-slate-300 mb-2" />
            <p className="font-semibold text-slate-700">No purchase orders found.</p>
            <p className="text-xs text-slate-500 mt-1">
              Generate purchase orders from the AI Forecast tab or create one manually.
            </p>
          </div>
        ) : (
          filteredOrders.map((order) => {
            const isExpanded = expandedOrderId === order.id;

            const getStatusBadge = (status: PurchaseOrder['status']) => {
              switch (status) {
                case 'Draft':
                  return 'bg-slate-100 text-slate-700 border-slate-300';
                case 'Sent to GS Logistics':
                  return 'bg-blue-50 text-[#0075c9] border-blue-300';
                case 'In Transit':
                  return 'bg-amber-50 text-amber-800 border-amber-300 animate-pulse';
                case 'Received & Stocked':
                  return 'bg-emerald-50 text-emerald-800 border-emerald-300';
              }
            };

            return (
              <div
                key={order.id}
                id={`po-card-${order.id}`}
                className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-xs transition-all"
              >
                {/* PO Summary Header Bar */}
                <div className="p-4 sm:p-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 bg-white">
                  <div className="flex items-start sm:items-center gap-3">
                    <button
                      onClick={() => setExpandedOrderId(isExpanded ? null : order.id)}
                      className="p-1 text-slate-400 hover:text-slate-700 rounded bg-slate-50 hover:bg-slate-100 transition-colors"
                    >
                      {isExpanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
                    </button>

                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-bold text-slate-900 font-mono text-sm">
                          {order.orderNumber}
                        </span>
                        <span
                          className={`text-[11px] font-bold px-2 py-0.5 rounded-full border ${getStatusBadge(
                            order.status
                          )}`}
                        >
                          {order.status}
                        </span>
                      </div>
                      <p className="text-xs text-slate-500 mt-0.5">
                        Supplier: <strong className="text-slate-700">{order.supplier}</strong> • Placed:{' '}
                        {new Date(order.createdAt).toLocaleDateString()}
                      </p>
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center justify-between lg:justify-end gap-3 sm:gap-4 pt-2 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                    <div className="text-left lg:text-right">
                      <span className="text-[11px] text-slate-400 block">Total Order Amount</span>
                      <strong className="text-base font-bold text-slate-900">
                        {formatCurrency(order.totalAmount, currency)}
                      </strong>
                      <span className="text-[11px] text-slate-500 block">
                        {order.items.reduce((acc, i) => acc + i.quantity, 0)} units total
                      </span>
                    </div>

                    {/* Status Action Buttons */}
                    <div className="flex items-center gap-2">
                      {order.status === 'Draft' && (
                        <button
                          onClick={() => onUpdateOrderStatus(order.id, 'Sent to GS Logistics')}
                          className="px-3 py-1.5 bg-[#0075c9] hover:bg-[#0062a8] text-white text-xs font-semibold rounded-lg flex items-center gap-1 transition-colors"
                        >
                          <Send className="w-3.5 h-3.5" />
                          <span>Dispatch EDI</span>
                        </button>
                      )}

                      {order.status === 'Sent to GS Logistics' && (
                        <button
                          onClick={() => onUpdateOrderStatus(order.id, 'In Transit')}
                          className="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg flex items-center gap-1 transition-colors"
                        >
                          <Truck className="w-3.5 h-3.5" />
                          <span>Mark In-Transit</span>
                        </button>
                      )}

                      {order.status === 'In Transit' && (
                        <button
                          onClick={() => onReceiveAndStockOrder(order)}
                          className="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg flex items-center gap-1 shadow-xs transition-colors"
                        >
                          <CheckCircle2 className="w-3.5 h-3.5" />
                          <span>Receive & Auto-Stock Pallet</span>
                        </button>
                      )}

                      {order.status === 'Received & Stocked' && (
                        <span className="inline-flex items-center gap-1 text-xs text-emerald-700 font-semibold bg-emerald-50 px-2.5 py-1 rounded-md border border-emerald-200">
                          <CheckCircle2 className="w-3.5 h-3.5" /> Fully Stocked
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Expanded Itemized Breakdown */}
                {isExpanded && (
                  <div className="border-t border-slate-100 bg-slate-50/70 p-4 sm:p-5">
                    {order.notes && (
                      <p className="text-xs text-slate-600 mb-3 italic">
                        <strong>Note:</strong> {order.notes}
                      </p>
                    )}

                    <div className="bg-white rounded-lg border border-slate-200 overflow-hidden">
                      <table className="w-full text-left text-xs">
                        <thead>
                          <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase text-[10px]">
                            <th className="py-2.5 px-3">SKU & Item Name</th>
                            <th className="py-2.5 px-3">Category</th>
                            <th className="py-2.5 px-3 text-right">Order Qty</th>
                            <th className="py-2.5 px-3 text-right">Unit Cost</th>
                            <th className="py-2.5 px-3 text-right">Line Total</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                          {order.items.map((it, idx) => (
                            <tr key={idx} className="hover:bg-slate-50">
                              <td className="py-2 px-3">
                                <div className="font-semibold text-slate-900">{it.name}</div>
                                <span className="font-mono text-[10px] text-slate-400">{it.sku}</span>
                              </td>
                              <td className="py-2 px-3 text-slate-600">{it.category}</td>
                              <td className="py-2 px-3 text-right font-bold text-slate-900">{it.quantity}</td>
                              <td className="py-2 px-3 text-right text-slate-600">
                                {formatCurrency(it.unitCost, currency)}
                              </td>
                              <td className="py-2 px-3 text-right font-bold text-slate-900">
                                {formatCurrency(it.totalCost, currency)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
              </div>
            );
          })
        )}
      </div>

      {/* Manual PO Modal */}
      {showNewPOModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 animate-in fade-in zoom-in duration-200">
            <h3 className="font-bold text-slate-900 text-base mb-1">Create Manual Purchase Order</h3>
            <p className="text-xs text-slate-500 mb-4">Direct replenishment from GS Logistics supplier.</p>

            <form onSubmit={handleCreateSimplePO} className="space-y-3.5 text-xs">
              <div>
                <label className="font-semibold text-slate-700 block mb-1">Select Supplier</label>
                <select
                  value={selectedSupplier}
                  onChange={(e) => setSelectedSupplier(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:outline-hidden font-medium"
                >
                  <option value="GS Retail Central Logistics Center">GS Retail Central Logistics Center</option>
                  <option value="GS Fresh Food Logistics Center">GS Fresh Food Logistics Center</option>
                  <option value="Binggrae Dairy Corp">Binggrae Dairy Corp</option>
                  <option value="Samyang Foods Co.">Samyang Foods Co.</option>
                  <option value="GS Breadique Bakery Center">GS Breadique Bakery Center</option>
                </select>
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">Target Product / SKU</label>
                <select
                  value={selectedSkuId}
                  onChange={(e) => setSelectedSkuId(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:outline-hidden font-medium"
                >
                  {items.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name} ({item.sku}) — Stock: {item.currentStock}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">Order Quantity (Units)</label>
                <input
                  type="number"
                  min="1"
                  max="500"
                  value={orderQty}
                  onChange={(e) => setOrderQty(parseInt(e.target.value) || 1)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold text-sm focus:outline-hidden"
                />
              </div>

              <div className="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowNewPOModal(false)}
                  className="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-[#0075c9] hover:bg-[#0062a8] text-white rounded-lg font-bold shadow-xs"
                >
                  Create & Send Order
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
