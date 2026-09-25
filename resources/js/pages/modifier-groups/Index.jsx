import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ListPlus, Plus, Trash2, Wheat, X } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FormGrid,
    FormSection,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    StatusDot,
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import RecipeEditor, { toRecipeLines } from '@/components/menu/RecipeEditor';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

const NEW_OPTION = { id: null, name: '', price: '0', is_active: true, recipe: [] };

function OptionRows({ options, onChange, errors, rawMaterials, units }) {
    const [open, setOpen] = useState(() => new Set(options.map((o, i) => (o.recipe.length ? i : null)).filter((i) => i !== null)));

    function update(index, patch) {
        onChange(options.map((o, i) => (i === index ? { ...o, ...patch } : o)));
    }

    function toggle(index) {
        setOpen((s) => {
            const next = new Set(s);
            if (next.has(index)) next.delete(index);
            else next.add(index);
            return next;
        });
    }

    return options.map((o, i) => {
        const err = (k) => errors[`modifiers.${i}.${k}`];
        const showRecipe = open.has(i) || o.recipe.length > 0;
        return (
            <div key={o.id ?? `new-${i}`} className="address-row option-row cust-field-full">
                <div className="option-row-main">
                    <Field label="Option" required error={err('name')}>
                        <Input value={o.name} invalid={err('name')} placeholder="Extra cheese" onChange={(e) => update(i, { name: e.target.value })} />
                    </Field>
                    <Field label="Price" required error={err('price')}>
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={o.price}
                            invalid={err('price')}
                            onChange={(e) => update(i, { price: e.target.value })}
                        />
                    </Field>
                    <div className="option-row-tools">
                        <Toggle checked={o.is_active} onChange={(v) => update(i, { is_active: v })} label="Option is available" />
                        <button type="button" className="cust-close" onClick={() => onChange(options.filter((_, j) => j !== i))} aria-label="Remove option">
                            <X size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </div>
                {showRecipe ? (
                    <RecipeEditor
                        lines={o.recipe}
                        onChange={(recipe) => update(i, { recipe })}
                        materials={rawMaterials}
                        units={units}
                        errors={errors}
                        errorKey={`modifiers.${i}.recipe`}
                        empty="No raw materials — nothing is deducted for this option."
                    />
                ) : (
                    <Button variant="ghost" icon={Wheat} className="btn-xs option-recipe-btn" onClick={() => toggle(i)}>
                        Uses raw materials…
                    </Button>
                )}
            </div>
        );
    });
}

function GroupDrawer({ group, rawMaterials, units, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(group?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: group?.name ?? '',
        min_select: group?.min_select ?? 0,
        max_select: group?.max_select ?? '',
        is_active: group?.is_active ?? true,
        modifiers: group?.modifiers?.map((m) => ({
            id: m.id,
            name: m.name,
            price: String(Number(m.price)),
            is_active: m.is_active,
            recipe: toRecipeLines(m.recipe),
        })) ?? [{ ...NEW_OPTION }],
    });

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('modifier-groups.update', group.id), opts);
        else post(route('modifier-groups.store'), opts);
    }

    return (
        <Drawer
            open
            wide
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Add-on Group — ${group.name}` : 'Add Add-on Group'}
            footer={
                <>
                    {isEdit && can('modifier-groups.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Group'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Group name" required full error={errors.name} hint="e.g. Extra toppings, Choose your sauce">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Customer picks at least" error={errors.min_select} hint="0 = optional">
                    <Input
                        type="number"
                        min="0"
                        value={data.min_select}
                        invalid={errors.min_select}
                        onChange={(e) => setData('min_select', e.target.value)}
                    />
                </Field>
                <Field label="At most" error={errors.max_select} hint="Empty = no limit">
                    <Input
                        type="number"
                        min="1"
                        value={data.max_select}
                        invalid={errors.max_select}
                        onChange={(e) => setData('max_select', e.target.value)}
                    />
                </Field>
                <Field label="Status" full error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Offered on the POS' : 'Hidden'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Group is active" />
                    </div>
                </Field>

                <FormSection icon={ListPlus} title="Options" />
                <OptionRows
                    options={data.modifiers}
                    onChange={(list) => setData('modifiers', list)}
                    errors={errors}
                    rawMaterials={rawMaterials}
                    units={units}
                />
                <Field full error={errors.modifiers}>
                    <Button variant="ghost" icon={Plus} className="address-add" onClick={() => setData('modifiers', [...data.modifiers, { ...NEW_OPTION }])}>
                        Add option
                    </Button>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function ModifierGroupsIndex({ groups, filters, counts, rawMaterials, units }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'add-on group',
        destroy: 'modifier-groups.destroy',
        restore: 'modifier-groups.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Group', className: 'cell-strong' }];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('modifier-groups.restore') })]
        : [
              ...baseColumns,
              { key: 'rule', label: 'Rule', render: (r) => <Tag tone={r.min_select > 0 ? 'accent' : 'neutral'}>{r.rule}</Tag> },
              {
                  key: 'modifiers',
                  label: 'Options',
                  render: (r) => (
                      <span className="option-list">
                          {r.modifiers.map((m) => (
                              <span key={m.id} className={m.is_active ? undefined : 'cell-muted'}>
                                  {m.name} <span className="mono">{Number(m.price) ? `+${money(m.price)}` : 'free'}</span>
                              </span>
                          ))}
                      </span>
                  ),
              },
              { key: 'menu_items_count', label: 'Used by', align: 'right', render: (r) => `${r.menu_items_count} items` },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Hidden'}</StatusDot>
                  ),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Add-on Groups"
                primary={
                    can('modifier-groups.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Group
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} groups · link them to menu items</span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('modifier-groups.restore')}
            />

            <FilterBar count={`${groups.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search groups…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={groups.data}
                meta={groups.meta}
                noun="groups"
                empty={inTrash ? 'Trash is empty' : 'No add-on groups yet'}
                onRowClick={!inTrash && can('modifier-groups.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <GroupDrawer
                    key={editing.id ?? 'new'}
                    group={editing.id ? editing : null}
                    rawMaterials={rawMaterials}
                    units={units}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
