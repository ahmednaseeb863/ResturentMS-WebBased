import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@/components/ui/ConfirmDialog';

/**
 * Trash + restore for a list screen (CLAUDE.md §1).
 *
 *   const trash = useTrash({ noun: 'branch', destroy: 'branches.destroy', restore: 'branches.restore', reason: 'required' });
 *   trash.ask(row)      → opens the confirm dialog (asks a reason)
 *   trash.restore(row)  → restores right away
 *   {trash.dialog}      → render once in the page
 */
export default function useTrash({ noun, destroy, restore, reason = 'optional', onDone }) {
    const [target, setTarget] = useState(null);
    const [processing, setProcessing] = useState(false);

    function confirm(text) {
        router.delete(route(destroy, target.id), {
            data: { reason: text },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setTarget(null);
            },
            onSuccess: () => onDone?.(),
        });
    }

    const dialog = (
        <ConfirmDialog
            open={target !== null}
            onClose={() => setTarget(null)}
            onConfirm={confirm}
            title={`Move ${noun} to trash?`}
            message={
                target
                    ? `“${target.name}” will be hidden everywhere. You can restore it later from the Trash tab or the Recycle Bin.`
                    : ''
            }
            confirmLabel="Move to trash"
            danger
            reason={reason}
            processing={processing}
        />
    );

    return {
        ask: setTarget,
        restore: (row) => router.post(route(restore, row.id), {}, { preserveScroll: true }),
        dialog,
    };
}
