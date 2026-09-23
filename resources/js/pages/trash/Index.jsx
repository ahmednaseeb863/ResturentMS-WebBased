import { router } from '@inertiajs/react';
import { ArchiveRestore } from 'lucide-react';
import {
    Button,
    DataTable,
    FilterBar,
    FilterSelect,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Tag,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import { dateTime } from '@/lib/format';

/** Recycle Bin: everything trashed, across modules, with Restore. */
export default function TrashIndex({ items, modules, filters }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);

    const restore = (row) =>
        router.post(route('trash.restore', { module: row.module, uuid: row.id }), {}, { preserveScroll: true });

    const columns = [
        { key: 'name', label: 'Record', className: 'cell-strong' },
        { key: 'module', label: 'Module', render: (r) => <Tag>{r.module_label}</Tag> },
        { key: 'deleted_at', label: 'Trashed', className: 'mono', render: (r) => dateTime(r.deleted_at) },
        { key: 'deleted_by', label: 'By', render: (r) => r.deleted_by ?? '—' },
        { key: 'delete_reason', label: 'Reason', className: 'cell-muted', render: (r) => r.delete_reason || '—' },
        {
            key: 'restore',
            label: '',
            align: 'right',
            render: (r) =>
                can('trash.restore') && (
                    <Button variant="ghost" icon={ArchiveRestore} className="btn-xs" onClick={() => restore(r)}>
                        Restore
                    </Button>
                ),
        },
    ];

    return (
        <PageBody>
            <PageToolbar title="Recycle Bin" />
            <PageStatus>
                <span>{items.meta.total} items in trash</span>
            </PageStatus>

            <FilterBar count={`${items.meta.total} items`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search trash…" />
                <FilterSelect
                    label="Module"
                    value={query.module}
                    onChange={(v) => setQuery('module', v)}
                    options={[{ value: '', label: 'All modules' }, ...modules]}
                />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={items.data}
                meta={items.meta}
                noun="items"
                empty="The recycle bin is empty"
                stack
            />
        </PageBody>
    );
}
