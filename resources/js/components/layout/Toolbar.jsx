import { Menu } from 'lucide-react';
import BranchSwitcher from './BranchSwitcher';

/**
 * Shared toolbar (pos-react `.toolbar`). The page content (title + actions) is
 * portalled into `.toolbar-slot` by <PageToolbar>; the slot uses display:contents
 * so the original flex layout (title left, actions right) is unchanged.
 */
export default function Toolbar({ slotRef, showMenuButton, navOpen, onMenu }) {
    return (
        <div className="toolbar">
            {showMenuButton && (
                <button
                    type="button"
                    className="toolbar-menu-btn"
                    onClick={onMenu}
                    aria-label="Toggle navigation"
                    aria-controls="app-sidebar"
                    aria-expanded={navOpen}
                >
                    <Menu size={18} strokeWidth={1.5} />
                </button>
            )}
            <div className="toolbar-slot" ref={slotRef} />
            <BranchSwitcher />
        </div>
    );
}
