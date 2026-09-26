import { usePage } from '@inertiajs/react';
import { date } from '@/lib/format';

/**
 * Bottom status bar: branch · user | page items | counter + shift + business date · version.
 * Items marked `sb-keep` stay visible on phones (branch + shift), the rest hide < 768px.
 */
export default function StatusBar({ slotRef }) {
    const { app, auth, context } = usePage().props;
    const shift = context?.shift;

    return (
        <div className="statusbar">
            <span className="sb-keep">{context?.branch?.name ?? 'No branch selected'}</span>
            {auth?.user && <span>{auth.user.name}</span>}
            <span className="statusbar-slot" ref={slotRef} />

            <div className="statusbar-right">
                <span className="sb-keep">{shift ? `${shift.counter} · ${shift.code}` : 'No open shift'}</span>
                {context?.business_date && <span>Business day {date(context.business_date)}</span>}
                <span>v{app?.version}</span>
            </div>
        </div>
    );
}
