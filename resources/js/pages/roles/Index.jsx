import { useState } from 'react';
import { createPortal } from 'react-dom';
import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import {
    Button,
    CheckBox,
    CheckItem,
    DataTable,
    Field,
    FilterBar,
    Input,
    PageBody,
    PageStatus,
    PageToolbar,
    SearchInput,
    Tag,
    Textarea,
    TrashTabs,
    trashColumns,
} from '@/components/ui';
import useCan from '@/hooks/useCan';
import useListQuery from '@/hooks/useListQuery';
import useOverlay from '@/hooks/useOverlay';
import useTrash from '@/hooks/useTrash';
import { cx } from '@/lib/format';

const tone = (i) => `role-tone-${i % 5}`;

/** pos-react RoleModal: role info on the left, permission groups on the right. */
function RoleEditor({ role, groups, onClose, onTrash }) {
    const can = useCan();
    const isEdit = Boolean(role?.id);
    const allIds = groups.flatMap((g) => g.permissions.map((p) => p.id));
    const { data, setData, post, put, processing, errors } = useForm({
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions ?? [],
    });
    useOverlay(true, onClose);

    const selected = new Set(data.permissions);
    const setSelected = (next) => setData('permissions', [...next]);

    function togglePerm(id) {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        setSelected(next);
    }

    function toggleGroup(group) {
        const ids = group.permissions.map((p) => p.id);
        const allChecked = ids.every((id) => selected.has(id));
        const next = new Set(selected);
        ids.forEach((id) => (allChecked ? next.delete(id) : next.add(id)));
        setSelected(next);
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (isEdit) put(route('roles.update', role.id), options);
        else post(route('roles.store'), options);
    }

    return createPortal(
        <div className="cust-overlay" onClick={onClose}>
            <form
                className="role-modal"
                role="dialog"
                aria-modal="true"
                aria-label={isEdit ? 'Edit Role' : 'Create Role'}
                onClick={(e) => e.stopPropagation()}
                onSubmit={submit}
                noValidate
            >
                <div className="cust-modal-header">
                    <span>{isEdit ? 'Edit Role' : 'Create Role'}</span>
                    <button type="button" className="cust-close" onClick={onClose} aria-label="Close">
                        <X size={14} strokeWidth={1.5} />
                    </button>
                </div>

                <div className="role-modal-body">
                    <div className="role-info-col">
                        <Field label="Role Name" required error={errors.name}>
                            <Input
                                placeholder="e.g. Cashier"
                                value={data.name}
                                invalid={errors.name}
                                onChange={(e) => setData('name', e.target.value)}
                                autoFocus={!isEdit}
                            />
                        </Field>
                        <Field label="Description" error={errors.description}>
                            <Textarea
                                placeholder="What can this role do?"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </Field>
                        {errors.permissions && <div className="field-error">{errors.permissions}</div>}
                        <div className="role-perm-summary">
                            <span>
                                {selected.size} / {allIds.length} permissions
                            </span>
                            <div className="role-card-actions">
                                <Button variant="ghost" className="btn-xs" onClick={() => setSelected(allIds)}>
                                    All
                                </Button>
                                <Button variant="ghost" className="btn-xs" onClick={() => setSelected([])}>
                                    None
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div className="role-perms-col">
                        {groups.map((group) => {
                            const ids = group.permissions.map((p) => p.id);
                            const checkedCount = ids.filter((id) => selected.has(id)).length;
                            const allChecked = checkedCount === ids.length;

                            return (
                                <div key={group.id} className="role-perm-group">
                                    <button
                                        type="button"
                                        className="role-perm-group-header"
                                        onClick={() => toggleGroup(group)}
                                        aria-pressed={allChecked}
                                    >
                                        <CheckBox checked={allChecked} partial={checkedCount > 0} />
                                        <span className="role-perm-group-name">{group.title}</span>
                                        <span className="role-perm-count">
                                            {checkedCount}/{ids.length}
                                        </span>
                                    </button>
                                    <div className="role-perm-items">
                                        {group.permissions.map((p) => (
                                            <CheckItem
                                                key={p.id}
                                                checked={selected.has(p.id)}
                                                onChange={() => togglePerm(p.id)}
                                            >
                                                {p.title}
                                            </CheckItem>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="cust-modal-footer">
                    {isEdit && can('roles.destroy') && (
                        <Button variant="ghost" icon={Trash2} className="text-danger footer-start" onClick={onTrash}>
                            Move to trash
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" type="submit" disabled={processing}>
                        {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Role'}
                    </Button>
                </div>
            </form>
        </div>,
        document.body,
    );
}

export default function RolesIndex({ roles, filters, counts, groups }) {
    const can = useCan();
    const [query, setQuery] = useListQuery(filters);
    const [editing, setEditing] = useState(null);
    const trash = useTrash({
        noun: 'role',
        destroy: 'roles.destroy',
        restore: 'roles.restore',
        onDone: () => setEditing(null),
    });
    const inTrash = filters.tab === 'trash';
    const total = groups.reduce((n, g) => n + g.permissions.length, 0);
    const canEdit = !inTrash && can('roles.update');

    // module names a role touches, for the "Modules" column
    const modulesOf = (role) =>
        groups.filter((g) => g.permissions.some((p) => role.permissions?.includes(p.id))).map((g) => g.title);

    const nameColumn = {
        key: 'name',
        label: 'Role',
        render: (r) => (
            <div className="role-name-cell">
                <span className={cx('role-dot', tone(roles.data.indexOf(r)))} />
                {r.name}
            </div>
        ),
    };

    const columns = inTrash
        ? [nameColumn, ...trashColumns({ onRestore: trash.restore, canRestore: can('roles.restore') })]
        : [
              nameColumn,
              {
                  key: 'description',
                  label: 'Description',
                  className: 'cell-muted',
                  render: (r) => r.description || '—',
              },
              { key: 'admins_count', label: 'Users', align: 'center', className: 'mono' },
              {
                  key: 'perms',
                  label: 'Permissions',
                  align: 'center',
                  className: 'mono',
                  render: (r) => `${r.permissions.length} / ${total}`,
              },
              {
                  key: 'modules',
                  label: 'Modules',
                  render: (r) => {
                      const mods = modulesOf(r);
                      return (
                          <div className="tag-list">
                              {mods.slice(0, 5).map((m) => (
                                  <Tag key={m}>{m}</Tag>
                              ))}
                              {mods.length > 5 && <Tag>+{mods.length - 5}</Tag>}
                          </div>
                      );
                  },
              },
          ];

    return (
        <PageBody>
            <PageToolbar
                title="Roles & Permissions"
                primary={
                    can('roles.store') && (
                        <Button variant="primary" icon={Plus} onClick={() => setEditing({})}>
                            Create Role
                        </Button>
                    )
                }
            />
            <PageStatus>
                <span>
                    {counts.active} roles · {total} permissions
                </span>
            </PageStatus>

            <TrashTabs
                value={filters.tab}
                onChange={(tab) => setQuery('tab', tab)}
                counts={counts}
                canRestore={can('roles.restore')}
            />

            {!inTrash && roles.data.length > 0 && !query.search && (
                <div className="role-cards-grid">
                    {roles.data.map((r, i) => (
                        <div key={r.id} className="role-card">
                            <div className={cx('role-card-accent', tone(i))} />
                            <div className="role-card-body">
                                <div className="role-card-header">
                                    <div className="role-card-name">{r.name}</div>
                                    {canEdit && (
                                        <Button
                                            variant="ghost"
                                            icon={Pencil}
                                            className="btn-xs"
                                            onClick={() => setEditing(r)}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </div>
                                <div className="role-card-desc">{r.description}</div>
                                <div className="role-card-footer">
                                    <span>{r.permissions.length} permissions</span>
                                    <span>{r.admins_count} users</span>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <FilterBar count={`${roles.meta.total} roles`}>
                <SearchInput value={query.search} onChange={(v) => setQuery('search', v)} placeholder="Search roles…" />
            </FilterBar>

            <DataTable
                columns={columns}
                rows={roles.data}
                meta={roles.meta}
                noun="roles"
                empty={inTrash ? 'Trash is empty' : 'No roles found'}
                onRowClick={canEdit ? setEditing : undefined}
            />

            {editing && (
                <RoleEditor
                    key={editing.id ?? 'new'}
                    role={editing}
                    groups={groups}
                    onClose={() => setEditing(null)}
                    onTrash={() => trash.ask(editing)}
                />
            )}
            {trash.dialog}
        </PageBody>
    );
}
