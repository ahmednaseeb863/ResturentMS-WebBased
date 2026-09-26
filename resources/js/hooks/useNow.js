import { useEffect, useState } from 'react';

/** Current time in ms, updated every `interval` ms (ticket timers, clocks). */
export default function useNow(interval = 1000) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), interval);
        return () => clearInterval(timer);
    }, [interval]);

    return now;
}
