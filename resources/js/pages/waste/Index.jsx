import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button, DataTable, Drawer, Field, FilterBar, FilterSelect, FormGrid, Input, PageBody, PageStatus, PageToolbar, SearchInput, Select, Tag } from '@/components/ui';
import StockLinesEditor, { newStockLine, stockLinesPayload } from '@/components/inventory/StockLinesEditor';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { date, dateTime, money, qty } from '@/lib/format';

/** Waste / damage written off (PLAN §4.16), valued at the average cost. */
export default function WasteIndex({ entries, filters, total, types, items, units }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [adding, setAdding] = useState(false);

    const columns = [
        {
            key: 'code',
            label: 'Entry',
            render: (e) => (
                <>
                    <span className="cell-strong mono">{e.code}</span>
                    <span className="cell-sub">{date(e.business_date)}</span>
                </>
            ),
        },
        { key: 'type', label: 'Type', render: (e) => <Tag tone={e.type.value === 'damage' ? 'warn' : 'neutral'}>{e.type.label}</Tag> },
        {
            key: 'items',
            label: 'Items',
            render: (e) => (
                <>
                    <span>{e.items.map((i) => `${qty(i.quantity, i.unit)} ${i.name}`).join(', ')}</span>
                    <span className="cell-sub">{e.reason}</span>
                </>
            ),
        },
        {
            key: 'by',
            label: 'By',
            render: (e) => (
                <>
                    {e.admin}
                    <span className="cell-sub">{dateTime(e.created_at)}</span>
                </>
            ),
        },
        { key: 'value', label: 'Value', align: 'right', className: 'mono', render: (e) => money(e.total_cost) },
    ];

    return (
        <PageBody>
            <PageToolbar
                title="Waste"
                primary={
                    can('waste.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setAdding(true)}>
                            Write Off
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {entries.meta.total} entries · {money(total)} written off in the filter
                </span>
            </PageStatus>

            <FilterBar count={`${entries.meta.total} entries`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Item or reason…" />
                <FilterSelect label="Type" value={query.type} onChange={(v) => setQuery('type', v)} options={[{ value: '', label: 'All' }, ...types]} />
                <span className="flabel">From:</span>
                <input type="date" className="fselect ledger-date" value={query.from} onChange={(e) => setQuery('from', e.target.value)} />
                <span className="flabel">To:</span>
                <input type="date" className="fselect ledger-date" value={query.to} onChange={(e) => setQuery('to', e.target.value)} />
            </FilterBar>

            <DataTable columns={columns} rows={entries.data} meta={entries.meta} noun="entries" empty="Nothing written off yet" stack />

            {adding && <WasteDrawer types={types} items={items} units={units} onClose={() => setAdding(false)} />}
        </PageBody>
    );
}

function WasteDrawer({ types, items, units, onClose }) {
    const { data, setData, post, processing, errors, transform } = useForm({ type: 'waste', reason: '', lines: [newStockLine()] });

    function submit() {
        transform((d) => ({ ...d, lines: stockLinesPayload(d.lines) }));
        post(route('waste.store'), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title="Write Off Stock"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : 'Write Off'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Type" required error={errors.type}>
                    <Select value={data.type} options={types} onChange={(e) => setData('type', e.target.value)} />
                </Field>
                <Field label="Reason" required error={errors.reason}>
                    <Input value={data.reason} invalid={errors.reason} placeholder="Expired, dropped, spoiled…" onChange={(e) => setData('reason', e.target.value)} autoFocus />
                </Field>
                <div className="cust-field cust-field-full">
                    <label>Items</label>
                    <StockLinesEditor lines={data.lines} onChange={(lines) => setData('lines', lines)} items={items} units={units} errors={errors} />
                    {errors.quantity && <div className="field-error">{errors.quantity}</div>}
                </div>
            </FormGrid>
        </Drawer>
    );
}
