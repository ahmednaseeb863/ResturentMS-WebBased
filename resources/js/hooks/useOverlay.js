import { useEffect } from 'react';

/** Shared behaviour for drawers/dialogs: Esc closes, page behind stops scrolling. */
export default function useOverlay(open, onClose) {
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);
        document.body.classList.add('overlay-open');
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.classList.remove('overlay-open');
        };
    }, [open, onClose]);
}
