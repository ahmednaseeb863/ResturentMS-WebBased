import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { watch } from '@/lib/live';

/**
 * Run `handler(events)` when a topic of the current branch changed (polling, no sockets):
 *   useLive('kitchen', () => router.reload({ only: ['tickets'] }));
 *   useLive('orders', (events) => showReady(events));
 * The interval is the Kitchen setting "Check for changes every … sec".
 */
export default function useLive(topic, handler, enabled = true) {
    const { context } = usePage().props;
    const branchId = context?.branch?.id;
    const seconds = context?.settings?.refresh_seconds ?? 5;

    const saved = useRef(handler);
    useEffect(() => {
        saved.current = handler;
    });

    useEffect(() => {
        if (!enabled || !branchId || !topic) return undefined;
        return watch({ topic, branchId, seconds, handler: (events) => saved.current(events) });
    }, [topic, branchId, seconds, enabled]);

    return seconds;
}
