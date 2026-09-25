import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Moon, Plus, Trash2 } from 'lucide-react';
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
    StatusDot,
    Tag,
    Toggle,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useTrash from '@/hooks/useTrash';
import { clock } from '@/lib/format';

function duration(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}

function ShiftTypeDrawer({ shiftType, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(shiftType?.id);
    const { data, setData, post, put, processing, errors } = useForm({
        name: shiftType?.name ?? '',
        start_time: shiftType?.start_time ?? '11:00',
        end_time: shiftType?.end_time ?? '19:00',
        is_active: shiftType?.is_active ?? true,
    });
    const overnight = data.start_time && data.end_time && data.end_time <= data.start_time;

    function submit() {
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('shift-types.update', shiftType.id), options);
        else post(route('shift-types.store'), options);
    }

    return (
        <Drawer
            open
            onClose={onClose}
            onSubmit={submit}
            title={isEdit ? `Edit Shift Type — ${shiftType.name}` : 'Add Shift Type'}
            footer={
                <>
                    {isEdit && can('shift-types.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Shift Type'}
                    </Button>
                </>
            }
        >
            <FormGrid>
                <Field label="Name" required full error={errors.name} hint="e.g. Morning, Night">
                    <Input
                        value={data.name}
                        invalid={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus={!isEdit}
                    />
                </Field>
                <Field label="Starts" required error={errors.start_time}>
                    <Input
                        mono
                        type="time"
                        value={data.start_time}
                        invalid={errors.start_time}
                        onChange={(e) => setData('start_time', e.target.value)}
                    />
                </Field>
                <Field
                    label="Ends"
                    required
                    error={errors.end_time}
                    hint={overnight && data.end_time !== data.start_time ? 'Overnight — ends the next day' : null}
                >
                    <Input
                        mono
                        type="time"
                        value={data.end_time}
                        invalid={errors.end_time}
                        onChange={(e) => setData('end_time', e.target.value)}
                    />
                </Field>
                <Field label="Status" error={errors.is_active}>
                    <div className="field-inline">
                        <span className="field-inline-label">{data.is_active ? 'Active' : 'Inactive'}</span>
                        <Toggle checked={data.is_active} onChange={(v) => setData('is_active', v)} label="Shift type is active" />
                    </div>
                </Field>
            </FormGrid>
        </Drawer>
    );
}

export default function ShiftTypesIndex({ shiftTypes, filters, counts, cutoff }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'shift type',
        destroy: 'shift-types.destroy',
        restore: 'shift-types.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';

    const baseColumns = [{ key: 'name', label: 'Shift', className: 'cell-strong' }];

    const columns = inTrash
        ? [...baseColumns, ...trashColumns({ onRestore: trash.restore, canRestore: can('shift-types.restore') })]
        : [
              ...baseColumns,
              {
                  key: 'hours',
                  label: 'Hours',
                  className: 'mono',
                  render: (r) => `${clock(r.start_time)} – ${clock(r.end_time)}`,
              },
              { key: 'duration', label: 'Length', className: 'mono', render: (r) => duration(r.duration_minutes) },
              {
                  key: 'overnight',
                  label: '',
                  render: (r) =>
                      r.overnight ? (
                          <Tag tone="info">
                              <Moon size={10} strokeWidth={1.5} /> OVERNIGHT
                          </Tag>
                      ) : null,
              },
              {
                  key: 'status',
                  label: 'Status',
                  render: (r) => (
                      <StatusDot status={r.is_active ? 'active' : 'inactive'}>
                          {r.is_active ? 'Active' : 'Inactive'}
                      </StatusDot>
                  ),
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Shift Types"
                primary={
                    can('shift-types.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Add Shift Type
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} shift types · business day starts at {clock(cutoff)}
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('shift-types.restore')}
            />

            <FilterBar count={`${shiftTypes.meta.total} shown`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search shift types…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={shiftTypes.data}
                meta={shiftTypes.meta}
                noun="shift types"
                empty={inTrash ? 'Trash is empty' : 'No shift types yet'}
                onRowClick={!inTrash && can('shift-types.update') ? setEditing : undefined}
                rowClassName={(r) => (!r.is_active ? 'row-dim' : undefined)}
                stack
            />

            {editing && (
                <ShiftTypeDrawer
                    key={editing.id ?? 'new'}
                    shiftType={editing}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
