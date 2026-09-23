import { useEffect, useRef, useState } from 'react';
import { MoreHorizontal } from 'lucide-react';

/** "⋯" menu that holds the secondary toolbar actions on small screens (CSS shows it < 768px). */
export default function ToolbarMore({ children }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const close = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, [open]);

    return (
        <div className="toolbar-more" ref={ref}>
            <button
                type="button"
                className="btn btn-secondary toolbar-more-btn"
                aria-label="More actions"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
            >
                <MoreHorizontal strokeWidth={1.5} />
            </button>
            {open && (
                <div className="toolbar-more-menu" onClick={() => setOpen(false)}>
                    {children}
                </div>
            )}
        </div>
    );
}
