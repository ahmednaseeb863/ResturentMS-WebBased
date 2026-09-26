import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { printPage } from '@/lib/printing';

/**
 * The server asked this screen to print a bill / receipt page itself (no receipt printer
 * on the counter): `flash.print = { url }` → browser print dialog in a hidden frame.
 */
export default function FlashPrint() {
    const { flash } = usePage().props;
    const done = useRef(null);

    useEffect(() => {
        if (!flash?.print?.url || done.current === flash) return;
        done.current = flash;
        printPage(flash.print.url);
    }, [flash]);

    return null;
}
