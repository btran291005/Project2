import React, { useState } from 'react';
import { StockMovementLog } from '../types';
import { History, ArrowDownRight, ArrowUpRight, ShoppingCart, Tag, Trash2, SlidersHorizontal } from 'lucide-react';

interface AuditLogViewProps {
  logs: StockMovementLog[];
}

export const AuditLogView: React.FC<AuditLogViewProps> = ({ logs }) => {
  const [filterType, setFilterType] = useState<string>('all');

  const filteredLogs = logs.filter((log) => {
    if (filterType === 'all') return true;
    return log.type === filterType;
  });

  const getTypeBadge = (type: StockMovementLog['type']) => {
    switch (type) {
      case 'RECEIVING':
        return {
          icon: <ArrowDownRight className="w-3.5 h-3.5 text-emerald-600" />,
          label: 'Inbound Pallet Receipt',
          badge: 'bg-emerald-50 text-emerald-800 border-emerald-200',
        };
      case 'POS_SALE':
        return {
          icon: <ShoppingCart className="w-3.5 h-3.5 text-blue-600" />,
          label: 'POS Register Sale',
          badge: 'bg-blue-50 text-[#0075c9] border-blue-200',
        };
      case 'MARKDOWN_SALE':
        return {
          icon: <Tag className="w-3.5 h-3.5 text-amber-600" />,
          label: 'Green Price Markdown Sale',
          badge: 'bg-amber-50 text-amber-800 border-amber-200',
        };
      case 'WASTE_DISPOSAL':
        return {
          icon: <Trash2 className="w-3.5 h-3.5 text-rose-600" />,
          label: 'Expired Discard Write-Off',
          badge: 'bg-rose-50 text-rose-800 border-rose-200',
        };
      case 'MANUAL_ADJUSTMENT':
        return {
          icon: <SlidersHorizontal className="w-3.5 h-3.5 text-purple-600" />,
          label: 'Manual Terminal Audit',
          badge: 'bg-purple-50 text-purple-800 border-purple-200',
        };
    }
  };

  return (
    <div className="bg-white border border-slate-200 rounded-xl shadow-xs overflow-hidden">
      {/* Header */}
      <div className="p-4 sm:p-5 border-b border-slate-200 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="flex items-center gap-2">
          <div className="p-2 bg-slate-200 text-slate-800 rounded-lg">
            <History className="w-5 h-5" />
          </div>
          <div>
            <h2 className="text-base font-bold text-slate-900">Inventory Movement & Audit Ledger</h2>
            <p className="text-xs text-slate-500">Immutable chronological record of receipts, POS sales, and disposals.</p>
          </div>
        </div>

        {/* Filter */}
        <select
          id="audit-log-type-filter"
          value={filterType}
          onChange={(e) => setFilterType(e.target.value)}
          className="bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-800 focus:outline-hidden cursor-pointer"
        >
          <option value="all">All Movement Types ({logs.length})</option>
          <option value="RECEIVING">Inbound Receipts</option>
          <option value="POS_SALE">POS Sales</option>
          <option value="MARKDOWN_SALE">Green Price Markdowns</option>
          <option value="WASTE_DISPOSAL">Waste Discard</option>
          <option value="MANUAL_ADJUSTMENT">Manual Adjustments</option>
        </select>
      </div>

      {/* Logs Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs sm:text-sm">
          <thead>
            <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
              <th className="py-3 px-4">Timestamp</th>
              <th className="py-3 px-3">Product Name</th>
              <th className="py-3 px-3">Movement Type</th>
              <th className="py-3 px-3 text-right">Quantity Delta</th>
              <th className="py-3 px-3 text-right">Balance After</th>
              <th className="py-3 px-4">Operator / Station</th>
              <th className="py-3 px-4">Reason / Notes</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 font-normal text-slate-700">
            {filteredLogs.length === 0 ? (
              <tr>
                <td colSpan={7} className="py-10 text-center text-slate-400 text-xs">
                  No activity logged for this filter.
                </td>
              </tr>
            ) : (
              filteredLogs.map((log) => {
                const typeInfo = getTypeBadge(log.type);
                const isPositive = log.quantityDelta > 0;

                return (
                  <tr key={log.id} className="hover:bg-slate-50 transition-colors">
                    <td className="py-3 px-4 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                      {new Date(log.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })} •{' '}
                      {new Date(log.timestamp).toLocaleDateString()}
                    </td>
                    <td className="py-3 px-3 font-semibold text-slate-900">{log.itemName}</td>
                    <td className="py-3 px-3">
                      <span
                        className={`inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded border ${typeInfo.badge}`}
                      >
                        {typeInfo.icon}
                        <span>{typeInfo.label}</span>
                      </span>
                    </td>
                    <td className="py-3 px-3 text-right font-mono font-bold">
                      <span className={isPositive ? 'text-emerald-600' : 'text-rose-600'}>
                        {isPositive ? `+${log.quantityDelta}` : log.quantityDelta}
                      </span>
                    </td>
                    <td className="py-3 px-3 text-right font-mono font-bold text-slate-900">
                      {log.balanceAfter}
                    </td>
                    <td className="py-3 px-4 text-slate-600 font-medium">{log.operator}</td>
                    <td className="py-3 px-4 text-slate-500 text-xs">{log.reason || '—'}</td>
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
