import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FormGrid,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Select,
    Tag,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function UnitDrawer({ unit, bases, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(unit?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: unit?.name ?? '',
        short_name: unit?.short_name ?? '',
        base_unit: unit?.base_unit?.id ?? '',
        factor: unit?.factor ?? '',
    });

    const base = bases.find((b) => b.value === data.base_unit);

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('units.update', unit.id), opts);
        else post(route('units.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Unit — ${unit.short_name}` : 'Add Unit'}
            footer={
                <>
                    {isEdit && can('units.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Unit'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Gram"
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Short name" required error={errors.short_name} hint="Shown next to quantities">
                    <Input
                        mono
                        value={data.short_name}
                        invalid={errors.short_name}
                        placeholder="g"
                        onChange={(e) => setData('short_name', e.target.value)}
                    />
                </Field>
                <Field
                    label="Part of"
                    full
                    error={errors.base_unit}
                    hint="Leave empty for a base unit (kg, L, pcs, carton…). Units of one family convert automatically."
                >
                    <Select
                        value={data.base_unit}
                        invalid={errors.base_unit}
                        placeholder="— Base unit —"
                        options={bases.filter((b) => b.value !== unit?.id)}
                        onChange={(e) => setData('base_unit', e.target.value)}
                    />
                </Field>
                {base && (
                    <Field
                        label={`1 ${data.short_name || 'unit'} equals`}
                        required
                        full
                        error={errors.factor}
                        hint={`e.g. 1 g = 0.001 kg, 1 dozen = 12 pcs`}
                    >
                        <div className="stg-number">
                            <Input
                                type="number"
                                min="0"
                                step="any"
                                inputMode="decimal"
                                value={data.factor}
                                invalid={errors.factor}
                                onChange={(e) => setData('factor', e.target.value)}
                            />
                            <span className="stg-unit">{base.short_name}</span>
                        </div>
                    </Field>
                )}
            </FormGrid>
        </Drawer>
    );
}

export default function UnitsIndex({ units, filters, counts, bases }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({ noun: 'unit', destroy: 'units.destroy', restore: 'units.restore', onDone: () => setEditing(null) });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        { key: 'short_name', label: 'Unit', className: 'cell-strong mono' },
        { key: 'name', label: 'Name' },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('units.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'conversion',
                  label: 'Conversion',
                  render: (r) => (r.conversion ? <span className="mono">{r.conversion}</span> : <Tag tone="info">Base unit</Tag>),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Units"
                primary={
                    can('units.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Unit
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} units · shared by all branches</span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('units.restore')} />

            <FilterBar count={`${units.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search units…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={units.data}
                meta={units.meta}
                noun="units"
                empty={inTrash ? 'Trash is empty' : 'No units yet'}
                onRowClick={!inTrash && can('units.update') ? setEditing : undefined}
                stack
            />

            {editing && (
                <UnitDrawer
                    key={editing.id ?? 'new'}
                    unit={editing}
                    bases={bases}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask({ ...editing, name: editing.short_name })}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
