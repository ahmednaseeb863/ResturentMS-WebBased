import { router } from '@inertiajs/react';
import { DataTable, FilterBar, FilterSelect, PageBody, PageStatus, PageToolbar, SearchInput, StatCard, Tabs, Tag } from '@/components/ui';
import useListQuery from '@/hooks/useListQuery';
import { date, dateTime, money, number } from '@/lib/format';

/** Money received and given back (pos-react Payments): stats, payments / refunds, filters by business day, method, account. */
export default function PaymentsIndex({ tab, rows, filters, stats, methods, banks, businessDate }) {
    const [query, setQuery] = useListQuery({ tab, ...filters });
    const refunds = tab === 'refunds';

    const order = {
        key: 'order',
        label: 'Order',
        className: 'mono',
        render: (r) => (
            <>
                <span className="si-id-link">{r.order?.code}</span>
                <span className="cell-sub">{r.order?.label}</span>
            </>
        ),
    };
    const method = {
        key: 'method',
        label: 'Method',
        render: (r) => (
            <>
                <Tag tone={r.method.tone}>{r.method.label}</Tag>
                {r.bank && <span className="cell-sub">{r.bank.name}</span>}
            </>
        ),
    };
    const when = {
        key: 'when',
        label: 'Business Day',
        render: (r) => (
            <>
                {date(r.business_date)}
                <span className="cell-sub mono">{dateTime(r.created_at)}</span>
            </>
        ),
    };

    const columns = refunds
        ? [
              when,
              order,
              method,
              { key: 'reason', label: 'Reason', className: 'cell-muted', render: (r) => r.reason },
              { key: 'by', label: 'By', render: (r) => r.refunded_by?.name },
              { key: 'amount', label: 'Amount', align: 'right', className: 'mono pay-amount-out', render: (r) => `−${number(r.amount)}` },
          ]
        : [
              when,
              order,
              method,
              { key: 'ref', label: 'Reference', className: 'mono', render: (r) => r.reference_no ?? '—' },
              {
                  key: 'shift',
                  label: 'Taken By',
                  render: (r) => (
                      <>
                          {r.received_by?.name}
                          {r.shift && <span className="cell-sub mono">{r.shift.code}</span>}
                      </>
                  ),
              },
              {
                  key: 'amount',
                  label: 'Amount',
                  align: 'right',
                  className: 'mono pay-amount-in',
                  render: (r) => (
                      <>
                          {number(r.amount)}
                          {Number(r.refunded_total) > 0 && <span className="cell-sub">−{number(r.refunded_total)} refunded</span>}
                      </>
                  ),
              },
          ];

    const range = query.from || query.to ? [query.from && date(query.from), query.to && date(query.to)].filter(Boolean).join(' – ') : 'all time';

    return (
        <PageBody>
            <PageToolbar title="Payments" />
            <PageStatus>
                <span>Business day {date(businessDate)}</span>
                <span>
                    {stats.payments} payments · {stats.refunds} refunds · {range}
                </span>
            </PageStatus>

            <div className="pay-stat-grid">
                <StatCard label="Cash Received" value={money(stats.cash)} sub={range} />
                <StatCard label="Bank Transfers" value={money(stats.bank)} sub={range} />
                <StatCard label="Refunded" value={money(stats.refunded)} tone={stats.refunded ? 'danger' : 'neutral'} sub={`${stats.refunds} refunds`} />
                <StatCard label="Net Received" value={money(stats.net)} sub="received − refunded" />
            </div>

            <Tabs
                tabs={[
                    { key: 'payments', label: 'Payments', count: stats.payments },
                    { key: 'refunds', label: 'Refunds', count: stats.refunds },
                ]}
                value={tab}
                onChange={(t) => setQuery('tab', t)}
            />

            <FilterBar count={`${rows.meta.total} ${refunds ? 'refunds' : 'payments'}`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Order no. or reference…" />
                <FilterSelect label="Method" value={query.method} onChange={(v) => setQuery('method', v)} options={[{ value: '', label: 'All' }, ...methods]} />
                {banks.length > 0 && (
                    <FilterSelect label="Account" value={query.bank} onChange={(v) => setQuery('bank', v)} options={[{ value: '', label: 'All' }, ...banks]} />
                )}
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={rows.data}
                meta={rows.meta}
                noun={refunds ? 'refunds' : 'payments'}
                empty={refunds ? 'No refunds in this range' : 'No payments in this range'}
                onRowClick={(r) => r.order && router.visit(route('orders.show', r.order.id))}
                stack
            />
        </PageBody>
    );
}
