import { request } from '@/lib/http';

/**
 * Screens refreshing themselves without WebSockets (the app runs on local servers and
 * shared hosting). One shared poller asks `live.poll` for the change stamps of the topics
 * the mounted screens care about — kitchen, orders, floor, printers — every few seconds, and
 * calls a screen only when its stamp moved (or, for orders, when ready / bill events arrive).
 * The screen then reloads its own data. Slower while the tab is hidden.
 */
const subscribers = new Set();
let versions = {};
let stamp = null;
let branch = null;
let timer = null;
let running = false;
const seenEvents = new Set();

function nextDelay() {
    const seconds = Math.min(...[...subscribers].map((s) => s.seconds));
    return (document.hidden ? seconds * 3 : seconds) * 1000;
}

function schedule() {
    clearTimeout(timer);
    timer = subscribers.size ? setTimeout(poll, nextDelay()) : null;
}

async function poll() {
    if (running || !subscribers.size) return;
    running = true;

    const topics = [...new Set([...subscribers].map((s) => s.topic))];
    const params = new URLSearchParams();
    topics.forEach((t) => params.append('topics[]', t));
    if (stamp !== null) params.set('since', stamp);

    try {
        const data = await request(`${route('live.poll')}?${params}`);
        const first = stamp === null;
        stamp = data.stamp;

        for (const topic of topics) {
            const now = data.versions[topic];
            const before = versions[topic];
            versions[topic] = now;

            const events = (data.events[topic] ?? []).filter((e) => {
                const key = `${e.id}:${e.kind}:${e.stamp}`;
                if (seenEvents.has(key)) return false;
                seenEvents.add(key);
                return true;
            });

            // the first answer only sets the baseline
            if (first || before === undefined || (now === before && !events.length)) continue;
            subscribers.forEach((s) => s.topic === topic && s.handler(events));
        }
    } catch {
        // offline / signed out — try again next time
    } finally {
        running = false;
        schedule();
    }
}

/** Watch a topic; `handler(events)` runs when it changed. Returns the unsubscribe function. */
export function watch({ topic, branchId, seconds, handler }) {
    if (branchId !== branch) {
        // another branch: start from a fresh baseline
        branch = branchId;
        versions = {};
        stamp = null;
    }

    const subscriber = { topic, seconds: Math.max(2, seconds || 5), handler };
    subscribers.add(subscriber);
    if (!timer && !running) timer = setTimeout(poll, 0);
    else schedule();

    return () => {
        subscribers.delete(subscriber);
        schedule();
    };
}

/** Check right away (e.g. after this screen changed something itself). */
export function pollNow() {
    clearTimeout(timer);
    poll();
}

if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && subscribers.size) pollNow();
    });
}
