import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import {
    Button,
    DataTable,
    Drawer,
    Field,
    FilterBar,
    FormGrid,
    Input,
    PageBody,
    PageToolbar,
    SearchInput,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';

function CategoryDrawer({ category, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(category?.id);
    const { data, setData, post, put, processing, errors } = useForm({ name: category?.name ?? '' });

    function submit() {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('raw-material-categories.update', category.id), opts);
        else post(route('raw-material-categories.store'), opts);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Category — ${category.name}` : 'Add Raw Material Category'}
            footer={
                <>
                    {isEdit && can('raw-material-categories.destroy') && (
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
                <Field label="Name" required full error={errors.name} hint="e.g. Meat, Dairy, Vegetables, Packaging">
                    <Input value={data.name} invalid={errors.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function RawMaterialCategoriesIndex({ categories, filters, counts }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'category',
        destroy: 'raw-material-categories.destroy',
        restore: 'raw-material-categories.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Category', className: 'cell-strong' }];
    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('raw-material-categories.restore') })]
        : [...baseColumns, { key: 'raw_materials_count', label: 'Raw materials', align: 'right' }];

    return (
        <PageBody>
            <PageToolbar
                title="Raw Material Categories"
                primary={
                    can('raw-material-categories.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Category
                        </Button>
                    )
                }
            >
                {can('raw-materials.index') && (
                    <Button variant="secondary" icon={ArrowLeft} href={route('raw-materials.index')}>
                        Raw Materials
                    </Button>
                )}
            </PageToolbar>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('raw-material-categories.restore')}
            />

            <FilterBar count={`${categories.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search categories…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={categories.data}
                meta={categories.meta}
                noun="categories"
                empty={inTrash ? 'Trash is empty' : 'No categories yet'}
                onRowClick={!inTrash && can('raw-material-categories.update') ? setEditing : undefined}
                stack
            />

            {editing && (
                <CategoryDrawer
                    key={editing.id ?? 'new'}
                    category={editing}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
