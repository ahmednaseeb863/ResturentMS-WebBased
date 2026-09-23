import { Moon, Sun } from 'lucide-react';
import useTheme from '@/hooks/useTheme';

export default function ThemeToggle() {
    const [theme, toggle] = useTheme();
    const Icon = theme === 'dark' ? Sun : Moon;

    return (
        <button
            type="button"
            className="theme-toggle"
            onClick={toggle}
            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            title={theme === 'dark' ? 'Light theme' : 'Dark theme'}
        >
            <Icon size={14} strokeWidth={1.5} />
        </button>
    );
}
