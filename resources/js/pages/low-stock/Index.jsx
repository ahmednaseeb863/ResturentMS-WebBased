import { useState } from 'react';
import { PackagePlus, ShoppingBag } from 'lucide-react';
import { Button, DataTable, PageBody, PageStatus, PageToolbar, Tag } from '@/components/ui';
import AddStockDialog from '@/components/menu/AddStockDialog';
import useCan from '@/hooks/useCan';
import { money, qty } from '@/lib/format';

/** Raw materials and ready items at or below their alert level (PLAN §4.16). */
export default function LowStockIndex({ items, enabled, units }) {
    const can = useCan();
    const [adding, setAdding] = useState(null);

    const columns = [
        {
            key: 'name',
            label: 'Item',
            render: (i) => (
                <>
                    <span className="cell-strong">{i.name}</span>
                    <span className="cell-sub">
                        {i.group}
                        {i.code && ` · ${i.code}`}
                    </span>
                </>
            ),
        },
        {
            key: 'stock',
            label: 'In Stock',
            align: 'right',
            render: (i) => (i.out ? <Tag tone="danger">Out · {qty(i.current_stock, i.stock_unit?.short_name)}</Tag> : <span className="mono">{qty(i.current_stock, i.stock_unit?.short_name)}</span>),
        },
        { key: 'alert', label: 'Alert At', align: 'right', className: 'mono', render: (i) => qty(i.alert_level, i.stock_unit?.short_name) },
        { key: 'short', label: 'Short By', align: 'right', className: 'mono', render: (i) => i.short_text },
        { key: 'cost', label: 'Avg Cost', align: 'right', className: 'mono', render: (i) => money(i.avg_cost) },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (i) =>
                can('stock.add') && (
                    <Button variant="ghost" className="btn-xs" icon={PackagePlus} onClick={() => setAdding(i)}>
                        Add stock
                    </Button>
                ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Low Stock"
                primary={
                    can('purchases.create') && (
                        <Button variant="primary" icon={ShoppingBag} href={route('purchases.create')}>
                            Receive Purchase
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {items.length} items at or below their alert level{!enabled && ' · alerts are switched off in Settings → Inventory'}
                </span>
            </PageStatus>

            <DataTable
                columns={columns}
                rows={items}
                noun="items"
                empty="Nothing is running low — set alert levels on raw materials and ready items"
                rowClassName={(i) => (i.out ? 'row-warn' : undefined)}
                stack
            />

            {adding && <AddStockDialog item={adding} units={units} onClose={() => setAdding(null)} />}
        </PageBody>
    );
}
