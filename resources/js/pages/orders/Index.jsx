import { router } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import { Button, DataTable, FilterBar, FilterSelect, PageBody, PageStatus, PageToolbar, SearchInput, StatCard, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { date, dateTime, money, number } from '@/lib/format';

/** Orders of the branch (pos-react Sales Invoices): stats, filters, list. */
export default function OrdersIndex({ orders, filters, stats, statuses, types, businessDate }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);

    const columns = [
        {
            key: 'code',
            label: 'Order',
            className: 'mono',
            render: (r) => <span className="si-id-link">{r.is_draft ? 'Held' : r.code}</span>,
        },
        { key: 'date', label: 'Business Day', render: (r) => date(r.business_date) },
        {
            key: 'type',
            label: 'Type',
            render: (r) => (
                <>
                    {r.type.label}
                    {r.table && <span className="cell-sub">{r.table.name}</span>}
                </>
            ),
        },
        {
            key: 'customer',
            label: 'Customer',
            render: (r) =>
                r.customer ? (
                    <>
                        {r.customer.name}
                        <span className="cell-sub mono">{r.customer.phone}</span>
                    </>
                ) : (
                    <span className="cell-muted">—</span>
                ),
        },
        { key: 'items', label: 'Items', align: 'center', render: (r) => r.item_count },
        { key: 'total', label: 'Total', align: 'right', className: 'mono', render: (r) => number(r.grand_total) },
        { key: 'status', label: 'Status', render: (r) => <Tag tone={r.status.tone}>{r.status.label}</Tag> },
        { key: 'by', label: 'Taken By', render: (r) => r.created_by?.name },
        { key: 'time', label: 'Time', className: 'mono cell-muted', render: (r) => dateTime(r.placed_at ?? r.created_at) },
    ];

    const range = filters.from || filters.to ? 'in range' : 'all time';

    return (
        <PageBody>
            <PageToolbar
                title="Orders"
                primary={
                    can('pos.index') && (
                        <Button variant="primary" icon={ShoppingCart} href={route('pos.index')}>
                            Open POS
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>Business day {date(businessDate)}</span>
                <span>{stats.open} open</span>
            </PageStatus>

            <div className="pay-stat-grid">
                <StatCard label={`Orders (${range})`} value={stats.orders} sub="placed, not cancelled" />
                <StatCard label="Order Value" value={money(stats.total)} />
                <StatCard label="Open / Unpaid" value={stats.open} sub={`${stats.held} held`} tone="neutral" />
                <StatCard label="Cancelled" value={stats.cancelled} tone={stats.cancelled ? 'danger' : 'neutral'} />
            </div>

            <FilterBar count={`${orders.meta.total} orders`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Order no., customer, table…" />
                <FilterSelect label="Status" value={query.status} onChange={(v) => setQuery('status', v)} options={[{ value: '', label: 'All' }, ...statuses]} />
                <FilterSelect label="Type" value={query.type} onChange={(v) => setQuery('type', v)} options={[{ value: '', label: 'All' }, ...types]} />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={orders.data}
                meta={orders.meta}
                noun="orders"
                empty="No orders yet"
                onRowClick={(r) => router.visit(route('orders.show', r.id))}
                stack
            />
        </PageBody>
    );
}
