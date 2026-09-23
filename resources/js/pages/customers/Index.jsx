import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Cake, Mail, MapPin, Phone, Plus, ReceiptText, StickyNote, Trash2, X } from 'lucide-react';
import {
    Button,
    ContactBlock,
    DataTable,
    DetailPanel,
    Drawer,
    EmptyState,
    Field,
    FilterBar,
    FormGrid,
    FormSection,
    InfoCards,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Tabs,
    Tag,
    Textarea,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { date, dateTime, money } from '@/lib/format';

const NEW_ADDRESS = { id: null, label: '', address: '', area: '', landmark: '', is_default: false };

function AddressRows({ addresses, errors, onChange }) {
    function update(index, key, value) {
        onChange(addresses.map((a, i) => (i === index ? { ...a, [key]: value } : a)));
    }

    function makeDefault(index) {
        onChange(addresses.map((a, i) => ({ ...a, is_default: i === index })));
    }

    function remove(index) {
        const rest = addresses.filter((_, i) => i !== index);
        if (rest.length && !rest.some((a) => a.is_default)) rest[0] = { ...rest[0], is_default: true };
        onChange(rest);
    }

    return (
        <>
            {addresses.map((a, i) => {
                const err = (k) => errors[`addresses.${i}.${k}`];
                return (
                    <div key={a.id ?? `new-${i}`} className="address-row cust-field-full">
                        <div className="address-row-head">
                            <button
                                type="button"
                                className={`address-default ${a.is_default ? 'on' : ''}`}
                                aria-pressed={a.is_default}
                                onClick={() => makeDefault(i)}
                            >
                                {a.is_default ? 'Default address' : 'Make default'}
                            </button>
                            <button
                                type="button"
                                className="cust-close"
                                onClick={() => remove(i)}
                                aria-label="Remove address"
                            >
                                <X size={14} strokeWidth={1.5} />
                            </button>
                        </div>
                        <FormGrid>
                            <Field label="Label" error={err('label')}>
                                <Input
                                    value={a.label ?? ''}
                                    placeholder="Home, Office…"
                                    invalid={err('label')}
                                    onChange={(e) => update(i, 'label', e.target.value)}
                                />
                            </Field>
                            <Field label="Area" error={err('area')}>
                                <Input
                                    value={a.area ?? ''}
                                    placeholder="Gulberg III"
                                    invalid={err('area')}
                                    onChange={(e) => update(i, 'area', e.target.value)}
                                />
                            </Field>
                            <Field label="Address" required full error={err('address')}>
                                <Input
                                    value={a.address}
                                    placeholder="House / street"
                                    invalid={err('address')}
                                    onChange={(e) => update(i, 'address', e.target.value)}
                                />
                            </Field>
                            <Field label="Landmark" full error={err('landmark')}>
                                <Input
                                    value={a.landmark ?? ''}
                                    placeholder="Near…"
                                    invalid={err('landmark')}
                                    onChange={(e) => update(i, 'landmark', e.target.value)}
                                />
                            </Field>
                        </FormGrid>
                    </div>
                );
            })}
        </>
    );
}

function CustomerDrawer({ customer, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(customer?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: customer?.name ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        birthday: customer?.birthday ?? '',
        notes: customer?.notes ?? '',
        addresses: customer?.addresses ?? [],
    });

    function addAddress() {
        setData('addresses', [...data.addresses, { ...NEW_ADDRESS, is_default: data.addresses.length === 0 }]);
    }

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('customers.update', customer.id), options);
        else post(route('customers.store'), options);
    }

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Customer — ${customer.name}` : 'Add Customer'}
            footer={
                <>
                    {isEdit && can('customers.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Customer'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Customer name" required full error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Full name"
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Phone" required error={errors.phone} hint="Used to find the customer at the POS">
                    <Input
                        mono
                        inputMode="tel"
                        value={data.phone}
                        invalid={errors.phone}
                        placeholder="03001234567"
                        onChange={(e) => setData('phone', e.target.value)}
                    />
                </Field>
                <Field label="Email" error={errors.email}>
                    <Input
                        type="email"
                        value={data.email}
                        invalid={errors.email}
                        placeholder="email@example.com"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                </Field>
                <Field label="Birthday" error={errors.birthday}>
                    <Input
                        type="date"
                        value={data.birthday}
                        invalid={errors.birthday}
                        onChange={(e) => setData('birthday', e.target.value)}
                    />
                </Field>
                <Field label="Notes" full error={errors.notes}>
                    <Textarea
                        value={data.notes}
                        invalid={errors.notes}
                        placeholder="Allergies, preferences…"
                        onChange={(e) => setData('notes', e.target.value)}
                    />
                </Field>

                <FormSection icon={MapPin} title="Delivery addresses" />
                <AddressRows
                    addresses={data.addresses}
                    errors={errors}
                    onChange={(list) => setData('addresses', list)}
                />
                <Field full error={errors.addresses}>
                    <Button variant="ghost" icon={Plus} className="address-add" onClick={addAddress}>
                        Add address
                    </Button>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

function CustomerDetail({ customer, onClose, onEdit }) {
    const can = useCan();
    const [tab, setTab] = useState('addresses');

    return (
        <DetailPanel
            open
            onClose={onClose}
            avatar={customer.initials}
            title={customer.name}
            sub={`Customer since ${date(customer.created_at)}`}
            actions={
                can('customers.update') && (
                    <Button variant="ghost" onClick={() => onEdit(customer)}>
                        Edit
                    </Button>
                )
            }
        >
            <InfoCards
                items={[
                    { label: 'Total spent', value: money(customer.total_spent) },
                    { label: 'Visits', value: customer.visits_count },
                    { label: 'Last visit', value: date(customer.last_visit_at) },
                ]}
            />
            <ContactBlock
                rows={[
                    { icon: Phone, text: customer.phone, mono: true },
                    { icon: Mail, text: customer.email },
                    { icon: Cake, text: customer.birthday && date(customer.birthday) },
                    { icon: StickyNote, text: customer.notes },
                ]}
            />
            <Tabs
                value={tab}
                onChange={setTab}
                tabs={[
                    { key: 'addresses', label: 'Addresses', count: customer.addresses.length },
                    { key: 'orders', label: 'Order History' },
                ]}
            />
            <div className="cust-tab-content">
                {tab === 'addresses' &&
                    (customer.addresses.length ? (
                        <div className="address-list">
                            {customer.addresses.map((a) => (
                                <div key={a.id} className="address-card">
                                    <div className="address-card-head">
                                        <span className="cell-strong">{a.label || 'Address'}</span>
                                        {a.is_default && <Tag tone="accent">DEFAULT</Tag>}
                                    </div>
                                    <div>{a.address}</div>
                                    <div className="cell-muted">{[a.area, a.landmark].filter(Boolean).join(' · ')}</div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <EmptyState icon={MapPin} title="No addresses">
                            Add a delivery address from Edit.
                        </EmptyState>
                    ))}
                {tab === 'orders' && (
                    <EmptyState icon={ReceiptText} title="No orders yet">
                        Orders from every branch will show here once the POS is live.
                    </EmptyState>
                )}
            </div>
        </DetailPanel>
    );
}

export default function CustomersIndex({ customers, filters, counts }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [viewing, setViewing] = useState(null);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'customer',
        destroy: 'customers.destroy',
        restore: 'customers.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Customer',
            render: (r) => (
                <div className="cust-name-cell">
                    <div className="cust-avatar-sm">{r.initials}</div>
                    <div>
                        <div className="products-name">{r.name}</div>
                        <div className="cust-sub-id">{r.email || '—'}</div>
                    </div>
                </div>
            ),
        },
        { key: 'phone', label: 'Phone', className: 'mono' },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('customers.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'area',
                  label: 'Area',
                  className: 'cell-muted',
                  render: (r) => r.addresses.find((a) => a.is_default)?.area || '—',
              },
              { key: 'visits_count', label: 'Visits', align: 'right', className: 'mono' },
              {
                  key: 'total_spent',
                  label: 'Total spent',
                  align: 'right',
                  className: 'mono',
                  render: (r) => money(r.total_spent),
              },
              { key: 'last_visit_at', label: 'Last visit', render: (r) => dateTime(r.last_visit_at) },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Customers"
                primary={
                    can('customers.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Customer
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} customers · shared by all branches</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('customers.restore')}
            />

            <FilterBar count={`${customers.meta.total} shown`}>
                <SearchInput
                    value={query.search}
                    onChange={(v) => setQuery('search', v)}
                    placeholder="Search by name, phone or email…"
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={customers.data}
                meta={customers.meta}
                noun="customers"
                empty={inTrash ? 'Trash is empty' : 'No customers found'}
                onRowClick={inTrash ? undefined : setViewing}
                stack
            />

            {viewing && (
                <CustomerDetail
                    key={viewing.id}
                    customer={customers.data.find((c) => c.id === viewing.id) ?? viewing}
                    onClose={() => setViewing(null)}
                    onEdit={(c) => {
                        setViewing(null);
                        setEditing(c);
                    }}
                />
            )}
            {editing && (
                <CustomerDrawer
                    key={editing.id ?? 'new'}
                    customer={editing}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
