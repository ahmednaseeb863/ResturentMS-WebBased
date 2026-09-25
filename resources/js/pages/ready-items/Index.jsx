import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
    PhotoUpload,
    SearchInput,
    Select,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import AddStockDialog from '@/components/menu/AddStockDialog';
import OrderTypePicker from '@/components/menu/OrderTypePicker';
import StockActions from '@/components/menu/StockActions';
import StockFields, { stockDefaults } from '@/components/menu/StockFields';
import StockLevel from '@/components/menu/StockLevel';
import { Thumb } from '@/pages/categories/Index';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

function ReadyItemDrawer({ item, categories, stations, units, orderTypes, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(item?.id);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: item?.name ?? '',
        code: item?.code ?? '',
        barcode: item?.barcode ?? '',
        category: item?.category?.id ?? '',
        kitchen_station: item?.kitchen_station?.id ?? '',
        price: item?.price ?? '',
        available_for: item?.available_for ?? orderTypes.map((o) => o.value),
        sort_order: item?.sort_order ?? 0,
        is_active: item?.is_active ?? true,
        image: null,
        remove_image: false,
        ...stockDefaults(item, units.find((u) => u.label === 'pcs')?.value),
    });

    function submit() {
        transform((d) => (isEdit ? { ...d, _method: 'put' } : d));
        post(isEdit ? route('ready-items.update', item.id) : route('ready-items.store'), {
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
            title={isEdit ? `Edit Ready Item — ${item.name}` : 'Add Ready Item'}
            footer={
                <>
                    {isEdit && can('ready-items.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Ready Item'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Coke 1.5L"
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field
                    label="Category"
                    required
                    error={errors.category}
                    hint={
                        !categories.length &&
                        (can('categories.index') ? (
                            <>
                                No categories yet — <Link href={route('categories.index')}>add one</Link>
                            </>
                        ) : (
                            'No categories yet'
                        ))
                    }
                >
                    <Select
                        value={data.category}
                        invalid={errors.category}
                        placeholder="Pick a category…"
                        options={categories}
                        onChange={(e) => setData('category', e.target.value)}
                    />
                </Field>
                <Field label="Sale price" required error={errors.price}>
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
                <Field label="Code" error={errors.code}>
                    <Input mono value={data.code} invalid={errors.code} onChange={(e) => setData('code', e.target.value)} />
                </Field>
                <Field label="Barcode" error={errors.barcode}>
                    <Input mono value={data.barcode} invalid={errors.barcode} onChange={(e) => setData('barcode', e.target.value)} />
                </Field>
                <Field label="Kitchen station" error={errors.kitchen_station} hint="Only if it must go through the kitchen / bar">
                    <Select
                        value={data.kitchen_station}
                        invalid={errors.kitchen_station}
                        placeholder="None — served as-is"
                        options={stations}
                        onChange={(e) => setData('kitchen_station', e.target.value)}
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
                <Field label="Sold for" required full error={errors.available_for}>
                    <OrderTypePicker value={data.available_for} onChange={(v) => setData('available_for', v)} options={orderTypes} />
                </Field>
                <Field label="Image" full error={errors.image}>
                    <PhotoUpload
                        value={data.image}
                        current={item?.image_url}
                        removed={data.remove_image}
                        label="Click to upload image (JPG, PNG, WebP · max 2 MB)"
                        onChange={(file) => setData((d) => ({ ...d, image: file, remove_image: false }))}
                        onRemove={() => setData((d) => ({ ...d, image: null, remove_image: true }))}
                    />
                </Field>

                <StockFields item={item} data={data} setData={setData} errors={errors} units={units} />

                <FormSection title="Status" />
                <Field error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Hidden on the POS'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Ready item is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function ReadyItemsIndex({ items, filters, counts, categories, stations, units, orderTypes }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(null);
    const trash = useTrash({
        noun: 'ready item',
        destroy: 'ready-items.destroy',
        restore: 'ready-items.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Ready item',
            className: 'cell-strong',
            render: (r) => (
                <span className="item-name">
                    <Thumb src={r.image_url} />
                    <span>
                        {r.name}
                        {(r.code || r.barcode) && <span className="cell-sub mono">{r.code ?? r.barcode}</span>}
                    </span>
                </span>
            ),
        },
        { key: 'category', label: 'Category', render: (r) => r.category?.name },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('ready-items.restore') })]
        : [
              ...baseColumns,
              { key: 'price', label: 'Price', align: 'right', className: 'mono', render: (r) => money(r.price) },
              { key: 'stock', label: 'In stock', align: 'right', render: (r) => <StockLevel item={r} /> },
              { key: 'avg_cost', label: 'Avg cost', align: 'right', className: 'mono', render: (r) => money(r.avg_cost) },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Hidden'}</StatusDot>
                  ),
              },
              { key: 'actions', label: '', align: 'right', render: (r) => <StockActions item={r} onAdd={setAdding} /> },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Ready Items"
                primary={
                    can('ready-items.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Ready Item
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} ready items · sold as-is, stock kept</span>
                {counts.low > 0 && <span className="text-warn">{counts.low} low on stock</span>}
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('ready-items.restore')} />

            <FilterBar count={`${items.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search name, code, barcode…" />
                {!inTrash && (
                    <>
                        <FilterSelect
                            label="Category"
                            value={query.category}
                            onChange={(v) => setQuery('category', v)}
                            options={[{ value: '', label: 'All' }, ...categories]}
                        />
                        <FilterSelect
                            label="Stock"
                            value={query.stock}
                            onChange={(v) => setQuery('stock', v)}
                            options={[
                                { value: '', label: 'All' },
                                { value: 'low', label: 'Low stock' },
                            ]}
                        />
                    </>
                )}
            </FilterBar>

            <DataTable
                columns={columns}
                rows={items.data}
                meta={items.meta}
                noun="ready items"
                empty={inTrash ? 'Trash is empty' : 'No ready items yet'}
                onRowClick={!inTrash && can('ready-items.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <ReadyItemDrawer
                    key={editing.id ?? 'new'}
                    item={editing}
                    categories={categories}
                    stations={stations}
                    units={units}
                    orderTypes={orderTypes}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {adding && <AddStockDialog key={adding.id} item={adding} units={units} onClose={() => setAdding(null)} />}
            {trash.dialog}
        </PageBody>
    );
}
