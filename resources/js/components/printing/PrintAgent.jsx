import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { CircleAlert, X } from 'lucide-react';
import useCan from '@/hooks/useCan';
import useLive from '@/hooks/useLive';
import { getDevicePrinters, onDevicePrintersChange, pendingJobs, printJob } from '@/lib/printing';

const SAFETY_POLL = 60000;

/** The printers this device prints for (changes with the Printing dialog). */
export function useDevicePrinters() {
    return useSyncExternalStore(onDevicePrintersChange, getDevicePrinters, () => []);
}

/**
 * Runs in the app layout on every page: when this device prints for some printers, it
 * picks up their jobs (when the polled "printers" stamp moves, and every minute) and prints
 * them one at a time.
 * A failed print shows a toast; the job can be retried from the print queue.
 */
export default function PrintAgent() {
    const { context } = usePage().props;
    const can = useCan();
    const printers = useDevicePrinters();
    const branch = context?.branch?.id;
    const method = context?.settings?.print_method ?? 'browser';
    const enabled = Boolean(branch) && printers.length > 0 && can('print-jobs.pending');

    const busy = useRef(false);
    const again = useRef(false);
    const [failure, setFailure] = useState(null);

    const run = useCallback(async () => {
        if (!enabled) return;
        if (busy.current) {
            again.current = true;
            return;
        }
        busy.current = true;
        try {
            do {
                again.current = false;
                for (const job of await pendingJobs(printers)) {
                    try {
                        await printJob(job.id, method);
                    } catch (error) {
                        setFailure({ title: error.job ?? job.title, message: error.message });
                    }
                }
            } while (again.current);
        } catch {
            // offline / signed out: try again on the next tick
        } finally {
            busy.current = false;
        }
    }, [enabled, printers, method]);

    // a job was queued somewhere in the branch (polled change stamp) → look for ours
    useLive('printers', () => run(), enabled);

    useEffect(() => {
        if (!enabled) return undefined;
        const first = setTimeout(run, 0);
        const timer = setInterval(run, SAFETY_POLL);
        return () => {
            clearTimeout(first);
            clearInterval(timer);
        };
    }, [enabled, run]);

    if (!failure) return null;

    return (
        <div className="toast toast-error print-toast" role="alert">
            <CircleAlert size={15} strokeWidth={1.5} />
            <span>
                <strong>{failure.title}</strong> didn’t print — {failure.message}
                {can('print-jobs.index') && (
                    <>
                        {' '}
                        <Link href={route('print-jobs.index', { status: 'failed' })}>Print queue</Link>
                    </>
                )}
            </span>
            <button type="button" className="cust-close" onClick={() => setFailure(null)} aria-label="Dismiss">
                <X size={12} strokeWidth={1.5} />
            </button>
        </div>
    );
}
