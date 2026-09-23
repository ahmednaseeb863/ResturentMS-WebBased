import { useCallback, useState } from 'react';

const KEY = 'rms-theme';

function current() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

/** Light / dark theme on <html data-theme>, same mechanism as pos-react. Saved per device for now (per user from Phase 1). */
export default function useTheme() {
    const [theme, setTheme] = useState(current);

    const toggle = useCallback(() => {
        const next = current() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try {
            localStorage.setItem(KEY, next);
        } catch {
            // storage blocked — theme still applies for this visit
        }
        setTheme(next);
    }, []);

    return [theme, toggle];
}
