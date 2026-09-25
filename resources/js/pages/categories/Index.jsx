import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Image, Plus, Trash2 } from 'lucide-react';
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
    PhotoUpload,
    SearchInput,
    Select,
    StatusDot,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

export function Thumb({ src }) {
    return <span className="item-thumb">{src ? <img src={src} alt="" /> : <Image size={14} strokeWidth={1.5} />}</span>;
}

function CategoryDrawer({ category, stations, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(category?.id);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: category?.name ?? '',
        kitchen_station: category?.kitchen_station?.id ?? '',
        sort_order: category?.sort_order ?? 0,
        is_active: category?.is_active ?? true,
        image: null,
        remove_image: false,
    });

    function submit() {
        transform((d) => (isEdit ? { ...d, _method: 'put' } : d));
        post(isEdit ? route('categories.update', category.id) : route('categories.store'), {
            preserveScroll: true,
            forceFormData: Boolean(data.image),
            onSuccess: onClose,
        });
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Category — ${category.name}` : 'Add Category'}
            footer={
                <>
                    {isEdit && can('categories.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Category'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="Shown as a tab on the POS">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus={!isEdit} />
                </Field>
                <Field label="Kitchen station" error={errors.kitchen_station} hint="Items go here unless they pick their own">
                    <Select
                        value={data.kitchen_station}
                        invalid={errors.kitchen_station}
                        placeholder="None (not sent to the kitchen)"
                        options={stations}
                        onChange={(e) => setData('kitchen_station', e.target.value)}
                    />
                </Field>
                <Field label="Sort order" error={errors.sort_order} hint="Lower shows first">
                    <Input
                        type="number"
                        min="0"
                        value={data.sort_order}
                        invalid={errors.sort_order}
                        onChange={(e) => setData('sort_order', e.target.value)}
                    />
                </Field>
                <Field label="Image" full error={errors.image}>
                    <PhotoUpload
                        value={data.image}
                        current={category?.image_url}
                        removed={data.remove_image}
                        label="Click to upload image (JPG, PNG, WebP · max 2 MB)"
                        onChange={(file) => {
                            setData((d) => ({ ...d, image: file, remove_image: false }));
                        }}
                        onRemove={() => setData((d) => ({ ...d, image: null, remove_image: true }))}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Hidden on the POS'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Category is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function CategoriesIndex({ categories, filters, counts, stations }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'category',
        destroy: 'categories.destroy',
        restore: 'categories.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [
        {
            key: 'name',
            label: 'Category',
            className: 'cell-strong',
            render: (r) => (
                <span className="item-name">
                    <Thumb src={r.image_url} />
                    {r.name}
                </span>
            ),
        },
    ];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('categories.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'kitchen_station',
                  label: 'Kitchen station',
                  render: (r) => r.kitchen_station?.name ?? <span className="cell-muted">—</span>,
              },
              { key: 'menu_items_count', label: 'Menu items', align: 'right' },
              { key: 'ready_items_count', label: 'Ready items', align: 'right' },
              { key: 'sort_order', label: 'Order', align: 'right', className: 'mono' },
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
                title="Categories"
                primary={
                    can('categories.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Category
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>{counts.active} categories · shared by menu items and ready items</span>
            </PageStatus>

            <TrashTabs value={filters.tab} onChange={(tab) => setQuery('tab', tab)} counts={counts} canRestore={can('categories.restore')} />

            <FilterBar count={`${categories.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search categories…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={categories.data}
                meta={categories.meta}
                noun="categories"
                empty={inTrash ? 'Trash is empty' : 'No categories yet'}
                onRowClick={!inTrash && can('categories.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <CategoryDrawer
                    key={editing.id ?? 'new'}
                    category={editing}
                    stations={stations}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
