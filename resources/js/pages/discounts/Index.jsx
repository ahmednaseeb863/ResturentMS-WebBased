import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CalendarClock, Percent, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    FormSection,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    StatusDot,
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import OfferStats, { dateRange } from '@/components/menu/OfferStats';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { date, money, number } from '@/lib/format';

/** "10%" or "Rs 200" */
function valueText(d) {
    return d.type === 'percent' ? `${number(d.value)}%` : money(d.value);
}

function DiscountDrawer({ discount, types, scopes, approvalAbove, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(discount?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: discount?.name ?? '',
        type: discount?.type ?? 'percent',
        value: discount?.value ? String(Number(discount.value)) : '',
        applies_to: discount?.applies_to ?? 'order',
        max_amount: discount?.max_amount ? String(Number(discount.max_amount)) : '',
        min_order_amount: discount?.min_order_amount ? String(Number(discount.min_order_amount)) : '',
        starts_on: discount?.starts_on ?? '',
        ends_on: discount?.ends_on ?? '',
        requires_approval: discount?.requires_approval ?? false,
        is_active: discount?.is_active ?? true,
    });
    const percent = data.type === 'percent';
    // Settings → Security & Approvals: PIN for discounts above X% (0 = every discount)
    const pinAnyway = approvalAbove === 0 ? 'A manager PIN is asked for every discount' : percent && Number(data.value) > approvalAbove ? `Above ${approvalAbove}% a manager PIN is asked anyway` : null;

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('discounts.update', discount.id), opts);
        else post(route('discounts.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Discount — ${discount.name}` : 'Add Discount'}
            footer={
                <>
                    {isEdit && can('discounts.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Discount'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <FormSection icon={Percent} title="Discount" />
                <Field label="Name" required full error={errors.name} hint="Shown to the cashier, e.g. Staff meal, Student 10%">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Type" required error={errors.type}>
                    <Select
                        value={data.type}
                        invalid={errors.type}
                        options={types}
                        onChange={(e) => setData((d) => ({ ...d, type: e.target.value, max_amount: e.target.value === 'percent' ? d.max_amount : '' }))}
                    />
                </Field>
                <Field label={percent ? 'Discount %' : 'Amount off'} required error={errors.value}>
                    <div className="stg-number">
                        <Input
                            type="number"
                            min="0"
                            max={percent ? 100 : undefined}
                            step="any"
                            inputMode="decimal"
                            value={data.value}
                            invalid={errors.value}
                            onChange={(e) => setData('value', e.target.value)}
                        />
                        <span className="stg-unit">{percent ? '%' : ''}</span>
                    </div>
                </Field>
                <Field label="Taken off" required error={errors.applies_to}>
                    <Select value={data.applies_to} invalid={errors.applies_to} options={scopes} onChange={(e) => setData('applies_to', e.target.value)} />
                </Field>
                <Field
                    label={data.applies_to === 'order' ? 'Minimum bill' : 'Minimum item total'}
                    error={errors.min_order_amount}
                    hint="Empty = no minimum"
                >
                    <Input
                        type="number"
                        min="0"
                        step="any"
                        inputMode="decimal"
                        value={data.min_order_amount}
                        invalid={errors.min_order_amount}
                        onChange={(e) => setData('min_order_amount', e.target.value)}
                    />
                </Field>
                {percent && (
                    <Field label="Maximum discount" error={errors.max_amount} hint="Empty = no cap, e.g. 10% up to Rs 500">
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={data.max_amount}
                            invalid={errors.max_amount}
                            onChange={(e) => setData('max_amount', e.target.value)}
                        />
                    </Field>
                )}

                <FormSection icon={CalendarClock} title="Dates">
                    Empty = always available.
                </FormSection>
                <Field label="From" error={errors.starts_on}>
                    <Input type="date" value={data.starts_on} invalid={errors.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                </Field>
                <Field label="Until" error={errors.ends_on}>
                    <Input type="date" value={data.ends_on} invalid={errors.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                </Field>

                <FormSection icon={ShieldCheck} title="Control" />
                <Field
                    full
                    error={errors.requires_approval}
                    hint={
                        pinAnyway && !data.requires_approval ? `${pinAnyway} (Settings → Security & Approvals)` : undefined
                    }
                >
                    <div className="field-inline">
                        <div>
                            <div className="field-inline-label">Needs manager approval</div>
                            <div className="field-inline-sub">A manager enters their PIN when the cashier applies it</div>
                        </div>
                        <Toggle checked={data.requires_approval} onChange={(v) => setData('requires_approval', v)} label="Needs manager approval" />
                    </div>
                </Field>
                <Field full error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Available to cashiers' : 'Switched off'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Discount is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function DiscountsIndex({ discounts, filters, counts, statusCounts, types, scopes, approvalAbove }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'discount',
        destroy: 'discounts.destroy',
        restore: 'discounts.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Name', className: 'cell-strong' }];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('discounts.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'type',
                  label: 'Type',
                  render: (r) => <Tag tone={r.type === 'percent' ? 'accent' : 'info'}>{r.type === 'percent' ? 'Percent' : 'Fixed amount'}</Tag>,
              },
              {
                  key: 'value',
                  label: 'Value',
                  className: 'mono',
                  render: (r) => (
                      <>
                          <span className="promo-value">{valueText(r)}</span>
                          {r.max_amount && <span className="cell-sub">up to {money(r.max_amount)}</span>}
                      </>
                  ),
              },
              {
                  key: 'applies_to',
                  label: 'Applies to',
                  render: (r) => (
                      <>
                          {r.applies_to_label}
                          {r.min_order_amount && <span className="cell-sub">min {money(r.min_order_amount)}</span>}
                      </>
                  ),
              },
              { key: 'dates', label: 'Dates', render: (r) => dateRange(r.starts_on, r.ends_on, date) ?? <span className="cell-muted">Always</span> },
              {
                  key: 'approval',
                  label: 'Approval',
                  render: (r) => (r.requires_approval ? <Tag tone="warn">Manager PIN</Tag> : <span className="cell-muted">—</span>),
              },
              { key: 'status', label: 'Status', render: (r) => <StatusDot status={r.status.dot}>{r.status.label}</StatusDot> },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Discounts"
                primary={
                    can('discounts.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Discount
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} discounts · {statusCounts.active} available today
                </span>
            </PageStatus>

            {!inTrash && <OfferStats counts={statusCounts} value={query.status} onChange={(v) => setQuery('status', v)} />}

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('discounts.restore')} />

            <FilterBar count={`${discounts.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search discounts…" />
                {!inTrash && (
                    <FilterSelect
                        label="Status"
                        value={query.status}
                        onChange={(v) => setQuery('status', v)}
                        options={[
                            { value: '', label: 'All' },
                            { value: 'active', label: 'Active today' },
                            { value: 'scheduled', label: 'Scheduled' },
                            { value: 'expired', label: 'Expired' },
                            { value: 'inactive', label: 'Switched off' },
                        ]}
                    />
                )}
            </FilterBar>

            <DataTable
                columns={columns}
                rows={discounts.data}
                meta={discounts.meta}
                noun="discounts"
                empty={inTrash ? 'Trash is empty' : 'No discounts yet'}
                onRowClick={!inTrash && can('discounts.update') ? setEditing : undefined}
                rowClassName={(r) => (r.status.value === 'expired' || r.status.value === 'inactive' ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <DiscountDrawer
                    key={editing.id ?? 'new'}
                    discount={editing.id ? editing : null}
                    types={types}
                    scopes={scopes}
                    approvalAbove={approvalAbove}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
