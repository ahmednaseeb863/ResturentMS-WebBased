import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FolderTree, Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FilterSelect,
    FormGrid,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import AddStockDialog from '@/components/menu/AddStockDialog';
import StockActions from '@/components/menu/StockActions';
import StockFields, { stockDefaults } from '@/components/menu/StockFields';
import StockLevel from '@/components/menu/StockLevel';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

function MaterialDrawer({ material, categories, units, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(material?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: material?.name ?? '',
        code: material?.code ?? '',
        category: material?.category?.id ?? '',
        is_active: material?.is_active ?? true,
        ...stockDefaults(material),
    });

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('raw-materials.update', material.id), opts);
        else post(route('raw-materials.store'), opts);
    }

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Raw Material — ${material.name}` : 'Add Raw Material'}
            footer={
                <>
                    {isEdit && can('raw-materials.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Raw Material'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Chicken fillet"
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Code" error={errors.code}>
                    <Input mono value={data.code} invalid={errors.code} placeholder="CHK-01" onChange={(e) => setData('code', e.target.value)} />
                </Field>
                <Field label="Category" error={errors.category}>
                    <Select
                        value={data.category}
                        invalid={errors.category}
                        placeholder="No category"
                        options={categories}
                        onChange={(e) => setData('category', e.target.value)}
                    />
                </Field>

                <StockFields item={material} data={data} setData={setData} errors={errors} units={units} />

                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Raw material is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function RawMaterialsIndex({ materials, filters, counts, categories, units }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(null);
    const trash = useTrash({
        noun: 'raw material',
        destroy: 'raw-materials.destroy',
        restore: 'raw-materials.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Raw material',
            className: 'cell-strong',
            render: (r) => (
                <>
                    {r.name}
                    {r.code && <span className="cell-sub mono">{r.code}</span>}
                </>
            ),
        },
        { key: 'category', label: 'Category', render: (r) => r.category?.name ?? <span className="cell-muted">—</span> },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('raw-materials.restore') })]
        : [
              ...baseColumns,
              { key: 'stock', label: 'In stock', align: 'right', render: (r) => <StockLevel item={r} /> },
              {
                  key: 'avg_cost',
                  label: 'Avg cost',
                  align: 'right',
                  className: 'mono',
                  render: (r) => `${money(r.avg_cost)} / ${r.stock_unit?.short_name ?? ''}`,
              },
              { key: 'stock_value', label: 'Value', align: 'right', className: 'mono', render: (r) => money(r.stock_value) },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Inactive'}</StatusDot>
                  ),
              },
              { key: 'actions', label: '', align: 'right', render: (r) => <StockActions item={r} onAdd={setAdding} /> },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Raw Materials"
                primary={
                    can('raw-materials.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Raw Material
                        </Button>
                    )
                }
            >
                {can('raw-material-categories.index') && (
                    <Button variant="secondary" icon={FolderTree} href={route('raw-material-categories.index')}>
                        Categories
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>{counts.active} raw materials</span>
                {counts.low > 0 && <span className="text-warn">{counts.low} low on stock</span>}
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('raw-materials.restore')} />

            <FilterBar count={`${materials.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search by name or code…" />
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
                rows={materials.data}
                meta={materials.meta}
                noun="raw materials"
                empty={inTrash ? 'Trash is empty' : 'No raw materials yet'}
                onRowClick={!inTrash && can('raw-materials.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <MaterialDrawer
                    key={editing.id ?? 'new'}
                    material={editing}
                    categories={categories}
                    units={units}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {adding && <AddStockDialog key={adding.id} item={adding} units={units} onClose={() => setAdding(null)} />}
            {trash.dialog}
        </PageBody>
    );
}
