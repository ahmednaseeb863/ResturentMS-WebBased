import { ArchiveRestore } from 'lucide-react';
import Button from './Button';
import { dateTime } from '@/lib/format';

/**
 * DataTable columns appended on every Trash tab: when, who, why + Restore.
 *   columns = tab === 'trash' ? [...base, ...trashColumns({ onRestore: trash.restore, canRestore })] : […]
 */
export default function trashColumns({ onRestore, canRestore = true }) {
    return [
        { key: 'deleted_at', label: 'Trashed', className: 'mono', render: (r) => dateTime(r.deleted_at) },
        { key: 'deleted_by', label: 'By', render: (r) => r.deleted_by ?? '—' },
        { key: 'delete_reason', label: 'Reason', className: 'cell-muted', render: (r) => r.delete_reason || '—' },
        {
            key: 'restore',
            label: '',
            align: 'right',
            render: (r) =>
                canRestore && (
                    <Button
                        variant="ghost"
                        icon={ArchiveRestore}
                        className="btn-xs"
                        onClick={(e) => {
                            e.stopPropagation();
                            onRestore(r);
                        }}
                    >
                        Restore
                    </Button>
                ),
        },
    ];
}
