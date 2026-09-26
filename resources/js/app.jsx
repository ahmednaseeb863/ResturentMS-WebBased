import '@fontsource/barlow/400.css';
import '@fontsource/barlow/500.css';
import '@fontsource/barlow/700.css';
import '@fontsource/barlow-condensed/400.css';
import '@fontsource/barlow-condensed/600.css';

import { createInertiaApp } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';

const appName = document.querySelector('title')?.textContent || 'Restaurant MS';

// Pages that render without the app shell, or with a variant of it.
function layoutFor(name) {
    if (name.startsWith('auth/')) return null;
    if (name.startsWith('pos/') || name.startsWith('kitchen/')) return [AppLayout, { hideSidebar: true }];
    return AppLayout;
}

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.jsx');
        const page = pages[`./pages/${name}.jsx`];
        if (!page) throw new Error(`Inertia page not found: ${name}`);
        return page();
    },
    layout: layoutFor,
    progress: { color: '#5980a6', delay: 150 },
});
