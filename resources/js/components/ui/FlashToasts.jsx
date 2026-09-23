import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';

/** Shows the session flash (`success` / `error`) after a redirect; hides after 4s. */
export default function FlashToasts() {
    const { flash } = usePage().props;
    // Every visit brings a new `flash` object, so remembering the dismissed one is enough.
    const [dismissed, setDismissed] = useState(null);

    let toast = null;
    if (flash?.error) toast = { type: 'error', text: flash.error };
    else if (flash?.success) toast = { type: 'success', text: flash.success };
    const visible = toast !== null && dismissed !== flash;

    useEffect(() => {
        if (!visible) return undefined;
        const t = setTimeout(() => setDismissed(flash), 4000);
        return () => clearTimeout(t);
    }, [visible, flash]);

    if (!visible) return null;
    const Icon = toast.type === 'error' ? CircleAlert : CircleCheck;

    return (
        <div className={`toast toast-${toast.type}`} role="status">
            <Icon size={15} strokeWidth={1.5} />
            <span>{toast.text}</span>
            <button type="button" className="cust-close" onClick={() => setDismissed(flash)} aria-label="Dismiss">
                <X size={12} strokeWidth={1.5} />
            </button>
        </div>
    );
}
