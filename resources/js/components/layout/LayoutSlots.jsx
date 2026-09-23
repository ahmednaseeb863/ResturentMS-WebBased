import { createContext, useContext } from 'react';
import { createPortal } from 'react-dom';
import { Head } from '@inertiajs/react';
import ToolbarMore from './ToolbarMore';

/**
 * The persistent layout exposes two DOM slots (toolbar + status bar). Pages fill
 * them with <PageToolbar> / <PageStatus> — rendered via portals, so the layout
 * never re-mounts between visits (no flicker) and pages need no useEffect.
 */
export const LayoutSlotsContext = createContext({ toolbarEl: null, statusEl: null });

/**
 * Page title + actions for the shared toolbar.
 *  - `primary`: the main action, always visible (also on mobile)
 *  - `children`: secondary actions; collapse into a "⋯" menu under 768px
 */
export function PageToolbar({ title, primary = null, children = null, headTitle }) {
    const { toolbarEl } = useContext(LayoutSlotsContext);

    return (
        <>
            <Head title={headTitle ?? title} />
            {toolbarEl &&
                createPortal(
                    <>
                        <span className="toolbar-title">{title}</span>
                        {children && <div className="toolbar-actions">{children}</div>}
                        {primary}
                        {children && <ToolbarMore>{children}</ToolbarMore>}
                    </>,
                    toolbarEl,
                )}
        </>
    );
}

/** Extra page-specific items for the status bar (e.g. "25 customers"). */
export function PageStatus({ children }) {
    const { statusEl } = useContext(LayoutSlotsContext);
    return statusEl ? createPortal(children, statusEl) : null;
}
