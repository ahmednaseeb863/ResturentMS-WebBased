import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Copy, ListPlus, Plus, Ruler, Trash2, UtensilsCrossed, Wheat, X } from 'lucide-react';
import {
    Button,
    CheckItem,
    DataTable,
    Dialog,
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
    Tag,
    Textarea,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import OrderTypePicker from '@/components/menu/OrderTypePicker';
import RecipeEditor, { recipeCost, toRecipeLines } from '@/components/menu/RecipeEditor';
import { Thumb } from '@/pages/categories/Index';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { money } from '@/lib/format';

const NEW_SIZE = { id: null, name: '', price: '', is_default: false, recipe: [], own_recipe: false };

/** "32%" food cost of a price (display only). */
function costShare(cost, price) {
    const p = Number(price);
    return cost && p ? `${Math.round((cost / p) * 100)}%` : null;
}

function SizeRows({ sizes, onChange, errors, rawMaterials, units }) {
    function update(index, patch) {
        onChange(sizes.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    }

    function makeDefault(index) {
        onChange(sizes.map((s, i) => ({ ...s, is_default: i === index })));
    }

    function remove(index) {
        const rest = sizes.filter((_, i) => i !== index);
        if (rest.length && !rest.some((s) => s.is_default)) rest[0] = { ...rest[0], is_default: true };
        onChange(rest);
    }

    return sizes.map((s, i) => {
        const err = (k) => errors[`variants.${i}.${k}`];
        return (
            <div key={s.id ?? `new-${i}`} className="address-row option-row cust-field-full">
                <div className="option-row-main">
                    <Field label="Size" required error={err('name')}>
                        <Input value={s.name} invalid={err('name')} placeholder="Large" onChange={(e) => update(i, { name: e.target.value })} />
                    </Field>
                    <Field label="Price" required error={err('price')}>
                        <Input
                            type="number"
                            min="0"
                            step="any"
                            inputMode="decimal"
                            value={s.price}
                            invalid={err('price')}
                            onChange={(e) => update(i, { price: e.target.value })}
                        />
                    </Field>
                    <div className="option-row-tools">
                        <button
                            type="button"
                            className={`address-default ${s.is_default ? 'on' : ''}`}
                            aria-pressed={s.is_default}
                            onClick={() => makeDefault(i)}
                        >
                            {s.is_default ? 'Default' : 'Make default'}
                        </button>
                        <button type="button" className="cust-close" onClick={() => remove(i)} aria-label="Remove size">
                            <X size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </div>
                <div className="field-inline size-recipe-switch">
                    <div>
                        <div className="field-inline-label">{s.own_recipe ? 'Own recipe for this size' : 'Uses the item recipe'}</div>
                        <div className="field-inline-sub">Turn on when this size uses different amounts</div>
                    </div>
                    <Toggle
                        checked={s.own_recipe}
                        onChange={(v) => update(i, { own_recipe: v, recipe: v ? s.recipe : [] })}
                        label="Own recipe for this size"
                    />
                </div>
                {s.own_recipe && (
                    <RecipeEditor
                        lines={s.recipe}
                        onChange={(recipe) => update(i, { recipe })}
                        materials={rawMaterials}
                        units={units}
                        errors={errors}
                        errorKey={`variants.${i}.recipe`}
                    />
                )}
            </div>
        );
    });
}

function MenuItemDrawer({ item, categories, stations, rawMaterials, units, modifierGroups, orderTypes, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(item?.id);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: item?.name ?? '',
        category: item?.category?.id ?? '',
        kitchen_station: item?.kitchen_station?.id ?? '',
        description: item?.description ?? '',
        price: item?.price ?? '',
        prep_time_minutes: item?.prep_time_minutes ?? '',
        available_for: item?.available_for ?? orderTypes.map((o) => o.value),
        sort_order: item?.sort_order ?? 0,
        is_active: item?.is_active ?? true,
        is_sold_out: item?.is_sold_out ?? false,
        recipe: toRecipeLines(item?.recipe),
        variants:
            item?.variants?.map((v) => ({
                id: v.id,
                name: v.name,
                price: String(Number(v.price)),
                is_default: v.is_default,
                recipe: toRecipeLines(v.recipe),
                own_recipe: v.recipe.length > 0,
            })) ?? [],
        modifier_groups: item?.modifier_groups?.map((g) => g.id) ?? [],
        image: null,
        remove_image: false,
    });

    const hasSizes = data.variants.length > 0;
    const category = categories.find((c) => c.value === data.category);
    const cost = recipeCost(data.recipe, rawMaterials, units);
    const defaultSize = data.variants.find((v) => v.is_default) ?? data.variants[0];
    const shownPrice = hasSizes ? defaultSize?.price : data.price;

    function addSize() {
        setData('variants', [...data.variants, { ...NEW_SIZE, is_default: data.variants.length === 0 }]);
    }

    function toggleGroup(uuid, on) {
        setData('modifier_groups', on ? [...data.modifier_groups, uuid] : data.modifier_groups.filter((g) => g !== uuid));
    }

    function submit() {
        transform((d) => {
            const payload = {
                ...d,
                variants: d.variants.map(({ own_recipe, ...v }) => ({ ...v, recipe: own_recipe ? v.recipe : [] })),
            };
            return isEdit ? { ...payload, _method: 'put' } : payload;
        });
        post(isEdit ? route('menu-items.update', item.id) : route('menu-items.store'), {
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
            title={isEdit ? `Edit Menu Item — ${item.name}` : 'Add Menu Item'}
            footer={
                <>
                    {isEdit && can('menu-items.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Menu Item'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <FormSection icon={UtensilsCrossed} title="Details" />
                <Field label="Name" required full error={errors.name}>
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        placeholder="Zinger Burger"
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
                <Field label="Price" required={!hasSizes} error={errors.price} hint={hasSizes ? 'Set per size below' : undefined}>
                    <Input
                        type="number"
                        min="0"
                        step="any"
                        inputMode="decimal"
                        value={hasSizes ? (defaultSize?.price ?? '') : data.price}
                        invalid={errors.price}
                        disabled={hasSizes}
                        onChange={(e) => setData('price', e.target.value)}
                    />
                </Field>
                <Field label="Kitchen station" error={errors.kitchen_station}>
                    <Select
                        value={data.kitchen_station}
                        invalid={errors.kitchen_station}
                        placeholder={category?.station ? `Category's (${category.station})` : 'Category’s station'}
                        options={stations}
                        onChange={(e) => setData('kitchen_station', e.target.value)}
                    />
                </Field>
                <Field label="Prep time" error={errors.prep_time_minutes}>
                    <div className="stg-number">
                        <Input
                            type="number"
                            min="1"
                            value={data.prep_time_minutes}
                            invalid={errors.prep_time_minutes}
                            onChange={(e) => setData('prep_time_minutes', e.target.value)}
                        />
                        <span className="stg-unit">min</span>
                    </div>
                </Field>
                <Field label="Description" full error={errors.description}>
                    <Textarea
                        value={data.description}
                        invalid={errors.description}
                        placeholder="Crispy fillet, lettuce, mayo…"
                        onChange={(e) => setData('description', e.target.value)}
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
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'On the menu' : 'Hidden'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Item is active" />
                    </div>
                </Field>
                <Field label="Sold out" error={errors.is_sold_out}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_sold_out ? 'Sold out today' : 'Available'}</span>
                        <Toggle checked={data.is_sold_out} onChange={(v) => setData('is_sold_out', v)} label="Sold out" />
                    </div>
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

                <FormSection icon={Wheat} title="Recipe — raw materials for one serving">
                    Deducted when the kitchen confirms the item is ready.
                    {cost > 0 && shownPrice ? ` Food cost ${costShare(cost, shownPrice)} of the price.` : ''}
                </FormSection>
                <div className="cust-field-full">
                    <RecipeEditor
                        lines={data.recipe}
                        onChange={(recipe) => setData('recipe', recipe)}
                        materials={rawMaterials}
                        units={units}
                        errors={errors}
                        empty={
                            rawMaterials.length
                                ? 'No recipe yet — nothing will be deducted from stock.'
                                : 'Add raw materials first (Inventory → Raw Materials).'
                        }
                    />
                </div>

                <FormSection icon={Ruler} title="Sizes">
                    Optional — each size has its own price.
                </FormSection>
                <SizeRows
                    sizes={data.variants}
                    onChange={(list) => setData('variants', list)}
                    errors={errors}
                    rawMaterials={rawMaterials}
                    units={units}
                />
                <Field full error={errors.variants}>
                    <Button variant="ghost" icon={Plus} className="address-add" onClick={addSize}>
                        Add size
                    </Button>
                </Field>

                <FormSection icon={ListPlus} title="Add-ons" />
                <Field full error={errors.modifier_groups}>
                    {modifierGroups.length ? (
                        <div className="check-list">
                            {modifierGroups.map((g) => (
                                <CheckItem
                                    key={g.value}
                                    checked={data.modifier_groups.includes(g.value)}
                                    onChange={(on) => toggleGroup(g.value, on)}
                                >
                                    {g.label}
                                    <span className="cell-sub">{g.rule}</span>
                                </CheckItem>
                            ))}
                        </div>
                    ) : (
                        <div className="field-hint">
                            No add-on groups yet
                            {can('modifier-groups.index') && (
                                <>
                                    {' '}
                                    — <Link href={route('modifier-groups.index')}>add one</Link>
                                </>
                            )}
                        </div>
                    )}
                </Field>
            </FormGrid>
        </Drawer>
    );
}

function CopyMenuDialog({ branches, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ from: branches[0]?.value ?? '' });

    function submit(e) {
        e.preventDefault();
        post(route('menu-items.copy'), { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title="Copy Menu From Another Branch"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" form="copy-menu-form" disabled={processing || !data.from}>
                        {processing ? 'Copying…' : 'Copy Menu'}
                    </Button>
                </>
            }
        >
            <form id="copy-menu-form" onSubmit={submit} noValidate>
                <p className="ui-dialog-text">
                    Copies kitchen stations, categories, raw materials, ready items, add-on groups, menu items with their
                    recipes, deals and discounts into this branch — <b>without stock</b> and without printers. Things this branch already has (same
                    name) are kept as they are.
                </p>
                <Field label="Copy from" required error={errors.from}>
                    <Select value={data.from} invalid={errors.from} options={branches} onChange={(e) => setData('from', e.target.value)} />
                </Field>
            </form>
        </Dialog>
    );
}

export default function MenuItemsIndex({
    items,
    filters,
    counts,
    categories,
    stations,
    rawMaterials,
    units,
    modifierGroups,
    orderTypes,
    copyBranches,
}) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const [copying, setCopying] = useState(false);
    const trash = useTrash({
        noun: 'menu item',
        destroy: 'menu-items.destroy',
        restore: 'menu-items.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Menu item',
            className: 'cell-strong',
            render: (r) => (
                <span className="item-name">
                    <Thumb src={r.image_url} />
                    <span>
                        {r.name}
                        {r.variants.length > 0 && <span className="cell-sub">{r.variants.map((v) => v.name).join(' · ')}</span>}
                    </span>
                </span>
            ),
        },
        { key: 'category', label: 'Category', render: (r) => r.category?.name },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('menu-items.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'price',
                  label: 'Price',
                  align: 'right',
                  className: 'mono',
                  render: (r) =>
                      r.variants.length > 1
                          ? `${money(Math.min(...r.variants.map((v) => Number(v.price))))} +`
                          : money(r.price),
              },
              {
                  key: 'cost',
                  label: 'Food cost',
                  align: 'right',
                  className: 'mono',
                  render: (r) => {
                      const size = r.variants.find((v) => v.is_default);
                      const cost = size ? size.recipe_cost : r.recipe_cost;
                      const price = size ? size.price : r.price;
                      return cost ? (
                          <>
                              {money(cost)}
                              <span className="cell-sub">{costShare(cost, price)}</span>
                          </>
                      ) : (
                          <span className="cell-muted">No recipe</span>
                      );
                  },
              },
              { key: 'station', label: 'Station', render: (r) => r.station_name ?? <span className="cell-muted">—</span> },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) =>
                      r.is_sold_out && r.is_active ? (
                          <Tag tone="warn">Sold out</Tag>
                      ) : (
                          <StatusDot status={r.is_active ? 'active' : 'inactive'}>{r.is_active ? 'Active' : 'Hidden'}</StatusDot>
                      ),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Menu Items"
                primary={
                    can('menu-items.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Menu Item
                        </Button>
                    )
                }
            >
                {copyBranches.length > 0 && (
                    <Button variant="secondary" icon={Copy} onClick={() => setCopying(true)}>
                        Copy from Branch
                    </Button>
                )}
            </PageToolbar>
            <PageStatus>
                <span>{counts.active} menu items · prepared in the kitchen, no stock</span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('menu-items.restore')} />

            <FilterBar count={`${items.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search menu items…" />
                {!inTrash && (
                    <>
                        <FilterSelect
                            label="Category"
                            value={query.category}
                            onChange={(v) => setQuery('category', v)}
                            options={[{ value: '', label: 'All' }, ...categories]}
                        />
                        <FilterSelect
                            label="Status"
                            value={query.status}
                            onChange={(v) => setQuery('status', v)}
                            options={[
                                { value: '', label: 'All' },
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Hidden' },
                                { value: 'sold_out', label: 'Sold out' },
                            ]}
                        />
                    </>
                )}
            </FilterBar>

            <DataTable
                columns={columns}
                rows={items.data}
                meta={items.meta}
                noun="menu items"
                empty={inTrash ? 'Trash is empty' : 'No menu items yet'}
                onRowClick={!inTrash && can('menu-items.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <MenuItemDrawer
                    key={editing.id ?? 'new'}
                    item={editing.id ? editing : null}
                    categories={categories}
                    stations={stations}
                    rawMaterials={rawMaterials}
                    units={units}
                    modifierGroups={modifierGroups}
                    orderTypes={orderTypes}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {copying && <CopyMenuDialog branches={copyBranches} onClose={() => setCopying(false)} />}
            {trash.dialog}
        </PageBody>
    );
}
