import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button, DataTable, FilterBar, FilterSelect, PageBody, PageStatus, PageToolbar, SearchInput, StatCard, StatGrid, Tag } from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { date, money } from '@/lib/format';

/** Purchases of the branch: supplier invoices received into stock, what is still owed. */
export default function PurchasesIndex({ purchases, filters, stats, suppliers, statuses }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);

    const columns = [
        {
            key: 'code',
            label: 'Purchase',
            render: (p) => (
                <>
                    <span className="si-id-link mono">{p.code}</span>
                    {p.invoice_no && <span className="cell-sub mono">Inv {p.invoice_no}</span>}
                </>
            ),
        },
        { key: 'date', label: 'Business Day', render: (p) => date(p.business_date) },
        { key: 'supplier', label: 'Supplier', className: 'cell-strong', render: (p) => p.supplier?.name },
        { key: 'items', label: 'Lines', align: 'right', className: 'mono', render: (p) => p.items_count },
        {
            key: 'total',
            label: 'Total',
            align: 'right',
            render: (p) => (
                <>
                    <span className="mono">{money(Number(p.total) - Number(p.returned_total))}</span>
                    {Number(p.returned_total) > 0 && <span className="cell-sub">{money(p.returned_total)} returned</span>}
                </>
            ),
        },
        { key: 'due', label: 'Due', align: 'right', className: 'mono', render: (p) => (p.due > 0 ? money(p.due) : '—') },
        { key: 'status', label: 'Payment', render: (p) => <Tag tone={p.payment_status.tone}>{p.payment_status.label}</Tag> },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Purchases"
                primary={
                    can('purchases.create') && (
                        <Button variant="primary" icon={Plus} href={route('purchases.create')}>
                            Receive Purchase
                        </Button>
                    )
                }
            >
                {can('suppliers.index') && <Button href={route('suppliers.index')}>Suppliers</Button>}
            </PageToolbar>
            <PageStatus>
                <span>
                    {stats.count} purchases · {money(stats.billed)} billed · {money(stats.due)} to pay
                </span>
            </PageStatus>

            <StatGrid>
                <StatCard label="Purchases" value={stats.count} sub="in the filter" tone="neutral" />
                <StatCard label="Billed" value={money(stats.billed)} sub="after returns" tone="neutral" />
                <StatCard label="To pay" value={money(stats.due)} sub="still owed to suppliers" tone={stats.due > 0 ? 'danger' : 'neutral'} />
            </StatGrid>

            <FilterBar count={`${purchases.meta.total} purchases`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="PUR no., invoice no., supplier…" />
                <FilterSelect label="Supplier" value={query.supplier} onChange={(v) => setQuery('supplier', v)} options={[{ value: '', label: 'All' }, ...suppliers]} />
                <FilterSelect label="Payment" value={query.status} onChange={(v) => setQuery('status', v)} options={[{ value: '', label: 'All' }, ...statuses]} />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={purchases.data}
                meta={purchases.meta}
                noun="purchases"
                empty="No purchases yet"
                onRowClick={can('purchases.show') ? (p) => router.visit(route('purchases.show', p.id)) : undefined}
                stack
            />
        </PageBody>
    );
}
