import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, CheckItem, Dialog, EmptyState } from '@/components/ui';
import { setDevicePrinters } from '@/lib/printing';
import { useDevicePrinters } from './PrintAgent';

/**
 * "This screen prints for…" — which of the branch's printers this browser prints on.
 * Saved on this device only (a kitchen PC ticks its station printer, a counter its own).
 * `printers`: [{ id, name, type }]
 */
export default function PrintDeviceDialog({ printers, onClose }) {
    const { context } = usePage().props;
    const saved = useDevicePrinters();
    const [picked, setPicked] = useState(() => saved.filter((id) => printers.some((p) => p.id === id)));
    const qz = context?.settings?.print_method === 'qz';

    const toggle = (id, on) => setPicked((list) => (on ? [...list, id] : list.filter((x) => x !== id)));

    function save() {
        // keep printers of other branches this device prints for
        const others = saved.filter((id) => !printers.some((p) => p.id === id));
        setDevicePrinters([...others, ...picked]);
        onClose();
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title="Printing on This Screen"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={save}>
                        Save
                    </Button>
                </>
            }
        >
            <p className="ui-dialog-text">
                Tick the printers this screen prints for. Keep this page open — kitchen tickets and void slips print here as they
                arrive.{' '}
                {qz
                    ? 'QZ Tray must be running on this computer.'
                    : 'Each ticket opens the browser print dialog (set QZ Tray under Settings → Printing to print silently).'}
            </p>
            {printers.length === 0 ? (
                <EmptyState title="No printers">Add the branch’s printers under Printers first.</EmptyState>
            ) : (
                <div className="print-device-list">
                    {printers.map((p) => (
                        <CheckItem key={p.id} checked={picked.includes(p.id)} onChange={(on) => toggle(p.id, on)}>
                            {p.name} <span className="cell-muted">· {p.type}</span>
                        </CheckItem>
                    ))}
                </div>
            )}
        </Dialog>
    );
}
