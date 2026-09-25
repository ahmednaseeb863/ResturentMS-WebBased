import { X } from 'lucide-react';
import {
    Button,
    DataTable,
    FilterBar,
    FilterSelect,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Tag,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { date, dateTime, money, qty } from '@/lib/format';

const KINDS = [
    { value: '', label: 'All' },
    { value: 'raw_material', label: 'Raw materials' },
    { value: 'ready_item', label: 'Ready items' },
];

/** Every stock in / out with who, why and the balance after it (append-only). */
export default function StockLedgerIndex({ movements, filters, item, types }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);

    const columns = [
        {
            key: 'when',
            label: 'When',
            className: 'mono',
            render: (r) => (
                <>
                    {dateTime(r.created_at)}
                    <span className="cell-sub">Business day {date(r.business_date)}</span>
                </>
            ),
        },
        ...(item
            ? []
            : [
                  {
                      key: 'item',
                      label: 'Item',
                      className: 'cell-strong',
                      render: (r) => (
                          <>
                              {r.item?.name}
                              <span className="cell-sub">{r.item?.kind === 'ready_item' ? 'Ready item' : 'Raw material'}</span>
                          </>
                      ),
                  },
              ]),
        { key: 'type', label: 'Type', render: (r) => <Tag tone={r.type.tone}>{r.type.label}</Tag> },
        {
            key: 'in',
            label: 'In',
            align: 'right',
            className: 'mono ledger-in',
            render: (r) => (Number(r.quantity) > 0 ? `+${qty(r.quantity, r.item?.unit)}` : ''),
        },
        {
            key: 'out',
            label: 'Out',
            align: 'right',
            className: 'mono ledger-out',
            render: (r) => (Number(r.quantity) < 0 ? qty(r.quantity, r.item?.unit) : ''),
        },
        {
            key: 'balance',
            label: 'Balance',
            align: 'right',
            className: 'mono cell-strong',
            render: (r) => qty(r.balance_after, r.item?.unit),
        },
        {
            key: 'unit_cost',
            label: 'Unit cost',
            align: 'right',
            className: 'mono',
            render: (r) => (r.unit_cost !== null ? money(r.unit_cost) : ''),
        },
        { key: 'admin', label: 'By', render: (r) => r.admin ?? <span className="cell-muted">System</span> },
        { key: 'note', label: 'Note', render: (r) => r.note ?? <span className="cell-muted">—</span> },
    ];

    const back = item?.kind === 'ready_item' ? 'ready-items.index' : 'raw-materials.index';

    return (
        <PageBody>
            <PageToolbar title={item ? `Stock Ledger — ${item.name}` : 'Stock Ledger'}>
                {item && can(back) && (
                    <Button variant="secondary" href={route(back)}>
                        {item.kind === 'ready_item' ? 'Ready Items' : 'Raw Materials'}
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                {item ? (
                    <>
                        <span>
                            In stock {qty(item.current_stock, item.unit)} · avg cost {money(item.avg_cost)} / {item.unit}
                        </span>
                        {item.is_trashed && <span>In trash</span>}
                    </>
                ) : (
                    <span>Every stock movement of this branch · entries are never edited</span>
                )}
            </PageStatus>

            <FilterBar count={`${movements.meta.total} entries`}>
                {item ? (
                    <button type="button" className="ledger-chip" onClick={() => setQuery('item', '')}>
                        {item.name} <X size={12} />
                    </button>
                ) : (
                    <>
                        <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search item…" />
                        <FilterSelect label="Items" value={query.kind} onChange={(v) => setQuery('kind', v)} options={KINDS} />
                    </>
                )}
                <FilterSelect
                    label="Type"
                    value={query.type}
                    onChange={(v) => setQuery('type', v)}
                    options={[{ value: '', label: 'All' }, ...types]}
                />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable columns={columns} rows={movements.data} meta={movements.meta} noun="entries" empty="No stock movements yet" stack />
        </PageBody>
    );
}
