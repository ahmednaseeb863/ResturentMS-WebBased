import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { BadgePercent, CalendarClock, Package, Plus, Trash2, X } from 'lucide-react';
import {
    Button,
    CheckItem,
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
    PhotoUpload,
    SearchInput,
    Select,
    StatusDot,
    Textarea,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import OfferStats, { dateRange } from '@/components/menu/OfferStats';
import OrderTypePicker from '@/components/menu/OrderTypePicker';
import { Thumb } from '@/pages/categories/Index';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { date, money } from '@/lib/format';

const NEW_OPTION = { id: null, item: '', variant: '', extra_price: '0', is_default: false };
const newSlot = () => ({ id: null, name: '', quantity: 1, options: [{ ...NEW_OPTION, is_default: true }] });

/** Menu price of a pick: the fixed size, else the item's (default size) price. */
function pickPrice(option, sellables) {
    const item = sellables.find((s) => s.value === option.item);
    if (!item) return 0;
    const size = item.variants.find((v) => v.value === option.variant);
    return size ? size.price : item.price;
}

/** Regular price of the default picks × quantity. */
function regularTotal(slots, sellables) {
    return slots.reduce((sum, slot) => {
        const pick = slot.options.find((o) => o.is_default) ?? slot.options[0];
        return sum + (pick ? pickPrice(pick, sellables) * (Number(slot.quantity) || 0) : 0);
    }, 0);
}

function OptionLine({ option, index, slotIndex, sellables, errors, many, onChange, onDefault, onRemove }) {
    const item = sellables.find((s) => s.value === option.item);
    const err = (k) => errors[`slots.${slotIndex}.options.${index}.${k}`];

    return (
        <div className="deal-option">
            <div className="deal-option-main">
                <Select
                    value={option.item}
                    invalid={err('item')}
                    placeholder="Pick an item…"
                    options={sellables.map((s) => ({ value: s.value, label: `${s.label} · ${money(s.price)}` }))}
                    onChange={(e) => onChange({ item: e.target.value, variant: '' })}
                    aria-label="Item"
                />
                {item?.variants.length > 0 ? (
                    <Select
                        value={option.variant}
                        invalid={err('variant')}
                        placeholder="Any size"
                        options={item.variants.map((v) => ({ value: v.value, label: `${v.label} · ${money(v.price)}` }))}
                        onChange={(e) => onChange({ variant: e.target.value })}
                        aria-label="Size"
                    />
                ) : (
                    <span className="deal-option-kind">{item?.kind ?? ''}</span>
                )}
            </div>
            {many && (
                <div className="deal-option-extra">
                    <div className="stg-number">
                        <span className="stg-unit">Extra</span>
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={option.extra_price}
                            invalid={err('extra_price')}
                            onChange={(e) => onChange({ extra_price: e.target.value })}
                            aria-label="Extra charge when the customer picks this"
                        />
                    </div>
                    <div className="option-row-tools">
                        <button
                            type="button"
                            className={`address-default ${option.is_default ? 'on' : ''}`}
                            aria-pressed={option.is_default}
                            onClick={onDefault}
                        >
                            {option.is_default ? 'Default' : 'Make default'}
                        </button>
                        <button type="button" className="cust-close" onClick={onRemove} aria-label="Remove item">
                            <X size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </div>
            )}
            {(err('item') || err('variant') || err('extra_price')) && (
                <div className="field-error">{err('item') || err('variant') || err('extra_price')}</div>
            )}
        </div>
    );
}

function SlotRows({ slots, onChange, errors, sellables }) {
    function update(index, patch) {
        onChange(slots.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    }

    function updateOption(slotIndex, optionIndex, patch) {
        const slot = slots[slotIndex];
        const options = slot.options.map((o, i) => (i === optionIndex ? { ...o, ...patch } : o));
        const next = { options };
        // name an unnamed slot after its first item
        if (patch.item && !slot.name.trim() && optionIndex === 0) {
            next.name = sellables.find((s) => s.value === patch.item)?.label.replace(/ \(hidden\)$/, '') ?? '';
        }
        update(slotIndex, next);
    }

    function removeOption(slotIndex, optionIndex) {
        const rest = slots[slotIndex].options.filter((_, i) => i !== optionIndex);
        if (!rest.some((o) => o.is_default)) rest[0] = { ...rest[0], is_default: true };
        update(slotIndex, { options: rest });
    }

    return slots.map((slot, i) => {
        const err = (k) => errors[`slots.${i}.${k}`];
        const many = slot.options.length > 1;
        return (
            <div key={slot.id ?? `new-${i}`} className="address-row deal-slot cust-field-full">
                <div className="deal-slot-head">
                    <Field label={many ? 'Choice' : 'Item'} required error={err('name')}>
                        <Input
                            value={slot.name}
                            invalid={err('name')}
                            placeholder={many ? 'Drink' : 'Burger'}
                            onChange={(e) => update(i, { name: e.target.value })}
                        />
                    </Field>
                    <Field label="Qty" required error={err('quantity')}>
                        <Input
                            type="number"
                            min="1"
                            max="20"
                            value={slot.quantity}
                            invalid={err('quantity')}
                            onChange={(e) => update(i, { quantity: e.target.value })}
                        />
                    </Field>
                    <div className="option-row-tools">
                        <button
                            type="button"
                            className="cust-close"
                            onClick={() => onChange(slots.filter((_, j) => j !== i))}
                            aria-label="Remove slot"
                        >
                            <X size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </div>
                <div className="deal-slot-note">
                    {many
                        ? `Customer picks ${slot.quantity > 1 ? slot.quantity : 'one'} — the default is pre-selected on the POS`
                        : 'Fixed item — add more items to let the customer choose'}
                </div>
                {slot.options.map((o, j) => (
                    <OptionLine
                        key={o.id ?? `new-${j}`}
                        option={o}
                        index={j}
                        slotIndex={i}
                        sellables={sellables}
                        errors={errors}
                        many={many}
                        onChange={(patch) => updateOption(i, j, patch)}
                        onDefault={() => update(i, { options: slot.options.map((x, k) => ({ ...x, is_default: k === j })) })}
                        onRemove={() => removeOption(i, j)}
                    />
                ))}
                {err('options') && <div className="field-error">{err('options')}</div>}
                <Button
                    variant="ghost"
                    icon={Plus}
                    className="btn-xs option-recipe-btn"
                    onClick={() => update(i, { options: [...slot.options, { ...NEW_OPTION }] })}
                >
                    {many ? 'Add another choice' : 'Let the customer choose…'}
                </Button>
            </div>
        );
    });
}

function DaysPicker({ value, onChange, days }) {
    function toggle(day, on) {
        onChange(on ? [...value, day].sort() : value.filter((d) => d !== day));
    }

    return (
        <div className="order-type-picker">
            {days.map((d) => (
                <CheckItem key={d.value} checked={value.includes(d.value)} onChange={(on) => toggle(d.value, on)}>
                    {d.label}
                </CheckItem>
            ))}
        </div>
    );
}

function DealDrawer({ deal, sellables, orderTypes, days, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(deal?.id);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: deal?.name ?? '',
        description: deal?.description ?? '',
        price: deal?.price ?? '',
        starts_on: deal?.starts_on ?? '',
        ends_on: deal?.ends_on ?? '',
        days_of_week: deal?.days_of_week ?? [],
        start_time: deal?.start_time ?? '',
        end_time: deal?.end_time ?? '',
        available_for: deal?.available_for ?? orderTypes.map((o) => o.value),
        sort_order: deal?.sort_order ?? 0,
        is_active: deal?.is_active ?? true,
        slots: deal?.slots?.map((s) => ({
            id: s.id,
            name: s.name,
            quantity: s.quantity,
            options: s.options.map((o) => ({
                id: o.id,
                item: o.item ? `${o.type}:${o.item.id}` : '',
                variant: o.variant?.id ?? '',
                extra_price: String(Number(o.extra_price)),
                is_default: o.is_default,
            })),
        })) ?? [newSlot()],
        image: null,
        remove_image: false,
    });

    const regular = regularTotal(data.slots, sellables);
    const saving = regular - Number(data.price || 0);

    function submit() {
        transform((d) => {
            const payload = {
                ...d,
                slots: d.slots.map((s) => ({
                    ...s,
                    options: s.options.map(({ item, ...o }) => {
                        const [type, uuid] = item ? item.split(':') : ['', ''];
                        return { ...o, type, item: uuid };
                    }),
                })),
            };
            return isEdit ? { ...payload, _method: 'put' } : payload;
        });
        post(isEdit ? route('deals.update', deal.id) : route('deals.store'), {
            preserveScroll: true,
            forceFormData: Boolean(data.image),
            onSuccess: onClose,
        });
    }

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Deal — ${deal.name}` : 'Add Deal'}
            footer={
                <>
                    {isEdit && can('deals.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Deal'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <FormSection icon={BadgePercent} title="Deal" />
                <Field label="Name" required full error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Zinger Meal"
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field
                    label="Deal price"
                    required
                    error={errors.price}
                    hint={
                        regular > 0 &&
                        (saving > 0
                            ? `Menu price ${money(regular)} — customer saves ${money(saving)} (${Math.round((saving / regular) * 100)}%)`
                            : `Menu price of the items is ${money(regular)}`)
                    }
                >
                    <Input
                        type="number"
                        min="0"
                        step="any"
                        inputMode="decimal"
                        value={data.price}
                        invalid={errors.price}
                        onChange={(e) => setData('price', e.target.value)}
                    />
                </Field>
                <Field label="Sort order" error={errors.sort_order}>
                    <Input
                        type="number"
                        min="0"
                        value={data.sort_order}
                        invalid={errors.sort_order}
                        onChange={(e) => setData('sort_order', e.target.value)}
                    />
                </Field>
                <Field label="Description" full error={errors.description}>
                    <Textarea
                        value={data.description}
                        invalid={errors.description}
                        placeholder="Zinger burger, fries and a drink"
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Field>
                <Field label="Image" full error={errors.image}>
                    <PhotoUpload
                        value={data.image}
                        current={deal?.image_url}
                        removed={data.remove_image}
                        label="Click to upload image (JPG, PNG, WebP · max 2 MB)"
                        onChange={(file) => setData((d) => ({ ...d, image: file, remove_image: false }))}
                        onRemove={() => setData((d) => ({ ...d, image: null, remove_image: true }))}
                    />
                </Field>

                <FormSection icon={Package} title="What's in the deal">
                    Menu items and ready items. Menu items use their recipes, ready items reduce their stock.
                </FormSection>
                {sellables.length === 0 && (
                    <div className="cust-field-full field-hint">
                        Add menu items or ready items first
                        {can('menu-items.index') && (
                            <>
                                {' '}
                                — <Link href={route('menu-items.index')}>Menu Items</Link>
                            </>
                        )}
                    </div>
                )}
                <SlotRows slots={data.slots} onChange={(slots) => setData('slots', slots)} errors={errors} sellables={sellables} />
                <Field full error={errors.slots}>
                    <Button variant="ghost" icon={Plus} className="address-add" onClick={() => setData('slots', [...data.slots, newSlot()])}>
                        Add item
                    </Button>
                </Field>

                <FormSection icon={CalendarClock} title="When it is sold">
                    Leave dates, days and times empty to sell it any time.
                </FormSection>
                <Field label="From" error={errors.starts_on}>
                    <Input type="date" value={data.starts_on} invalid={errors.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                </Field>
                <Field label="Until" error={errors.ends_on}>
                    <Input type="date" value={data.ends_on} invalid={errors.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                </Field>
                <Field label="Days" full error={errors.days_of_week} hint="None ticked = every day">
                    <DaysPicker value={data.days_of_week} onChange={(v) => setData('days_of_week', v)} days={days} />
                </Field>
                <Field label="From time" error={errors.start_time}>
                    <Input type="time" value={data.start_time} invalid={errors.start_time} onChange={(e) => setData('start_time', e.target.value)} />
                </Field>
                <Field label="Until time" error={errors.end_time} hint="May pass midnight, e.g. 22:00 → 02:00">
                    <Input type="time" value={data.end_time} invalid={errors.end_time} onChange={(e) => setData('end_time', e.target.value)} />
                </Field>
                <Field label="Sold for" required full error={errors.available_for}>
                    <OrderTypePicker value={data.available_for} onChange={(v) => setData('available_for', v)} options={orderTypes} />
                </Field>
                <Field label="Status" full error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'On sale' : 'Switched off'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Deal is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function DealsIndex({ deals, filters, counts, statusCounts, sellables, orderTypes, days }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'deal',
        destroy: 'deals.destroy',
        restore: 'deals.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Deal',
            className: 'cell-strong',
            render: (r) => (
                <span className="item-name">
                    <Thumb src={r.image_url} />
                    <span>
                        {r.name}
                        <span className="cell-sub">
                            {r.slots
                                .map((s) => (s.options.length > 1 ? `${s.name} (choice)` : s.options[0]?.label ?? s.name))
                                .map((text, i) => (r.slots[i].quantity > 1 ? `${r.slots[i].quantity} × ${text}` : text))
                                .join(' + ')}
                        </span>
                    </span>
                </span>
            ),
        },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('deals.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'price',
                  label: 'Price',
                  align: 'right',
                  className: 'mono',
                  render: (r) => (
                      <>
                          <span className="promo-value">{money(r.price)}</span>
                          {r.regular_price > Number(r.price) && <span className="cell-sub deal-was">{money(r.regular_price)}</span>}
                      </>
                  ),
              },
              {
                  key: 'cost',
                  label: 'Food cost',
                  align: 'right',
                  className: 'mono',
                  render: (r) =>
                      r.cost ? (
                          <>
                              {money(r.cost)}
                              {Number(r.price) > 0 && <span className="cell-sub">{Math.round((r.cost / Number(r.price)) * 100)}%</span>}
                          </>
                      ) : (
                          <span className="cell-muted">—</span>
                      ),
              },
              {
                  key: 'schedule',
                  label: 'When',
                  render: (r) => (
                      <>
                          {r.schedule}
                          <span className="cell-sub">{dateRange(r.starts_on, r.ends_on, date) ?? 'No end date'}</span>
                      </>
                  ),
              },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => <StatusDot status={r.status.dot}>{r.status.label}</StatusDot>,
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Deals"
                primary={
                    can('deals.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Deal
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} deals · {statusCounts.active} on sale today
                </span>
            </PageStatus>

            {!inTrash && <OfferStats counts={statusCounts} value={query.status} onChange={(v) => setQuery('status', v)} />}

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('deals.restore')} />

            <FilterBar count={`${deals.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search deals…" />
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
                rows={deals.data}
                meta={deals.meta}
                noun="deals"
                empty={inTrash ? 'Trash is empty' : 'No deals yet'}
                onRowClick={!inTrash && can('deals.update') ? setEditing : undefined}
                rowClassName={(r) => (r.status.value === 'expired' || r.status.value === 'inactive' ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <DealDrawer
                    key={editing.id ?? 'new'}
                    deal={editing.id ? editing : null}
                    sellables={sellables}
                    orderTypes={orderTypes}
                    days={days}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
