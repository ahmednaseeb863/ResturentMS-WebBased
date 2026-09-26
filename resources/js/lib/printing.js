import { request } from '@/lib/http';

/**
 * Printing from this browser (PLAN §8 "Thermal printing"). Each screen chooses which of
 * the branch's printers it prints for (saved on this device only); the print agent then
 * claims their jobs and prints them — silently through QZ Tray (raw ESC/POS) or with the
 * browser print dialog (the slip page in a hidden frame).
 */
const STORAGE_KEY = 'rms-print-device';
const listeners = new Set();
let devicePrinters = read();

function read() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY));
        return Array.isArray(saved) ? saved.filter((id) => typeof id === 'string') : [];
    } catch {
        return [];
    }
}

/** Printer uuids this device prints for. */
export function getDevicePrinters() {
    return devicePrinters;
}

export function setDevicePrinters(ids) {
    devicePrinters = [...ids];
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(devicePrinters));
    } catch {
        // storage blocked: keep the choice for this visit only
    }
    listeners.forEach((listener) => listener());
}

export function onDevicePrintersChange(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

// ── QZ Tray ────────────────────────────────────────────────────────────

let qzSetUp = false;

async function qzTray() {
    const qz = (await import('qz-tray')).default;

    if (!qzSetUp) {
        // signed requests print without asking; without a certificate QZ Tray asks "Allow?"
        qz.security.setCertificatePromise((resolve) => {
            request(route('qz.certificate'), { as: 'text' }).then(resolve, () => resolve());
        });
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise((toSign) => (resolve) => {
            request(route('qz.sign'), { method: 'POST', body: { request: toSign }, as: 'text' }).then(resolve, () => resolve());
        });
        qzSetUp = true;
    }

    if (!qz.websocket.isActive()) {
        try {
            await qz.websocket.connect({ retries: 2, delay: 1 });
        } catch {
            throw new Error('QZ Tray is not running on this computer.');
        }
    }

    return qz;
}

async function printWithQz(job) {
    const qz = await qzTray();
    const target =
        job.printer.connection === 'network'
            ? { host: job.printer.host, port: job.printer.port }
            : await qz.printers.find(job.printer.device).catch(() => {
                  throw new Error(`Printer “${job.printer.device}” was not found on this computer.`);
              });

    await qz.print(qz.configs.create(target), [{ type: 'raw', format: 'base64', data: job.escpos }]);
}

// ── Browser print dialog ───────────────────────────────────────────────

function printWithBrowser(job) {
    return new Promise((resolve) => {
        const frame = document.createElement('iframe');
        frame.className = 'print-frame';
        frame.setAttribute('aria-hidden', 'true');

        const finish = () => {
            window.removeEventListener('message', onMessage);
            clearTimeout(timer);
            setTimeout(() => frame.remove(), 1000);
            resolve();
        };
        const onMessage = (e) => {
            if (e.origin === window.location.origin && e.data?.type === 'print-job-done' && e.data.id === job.id) finish();
        };
        // the dialog may stay open a while; don't block the queue for ever
        const timer = setTimeout(finish, 120000);

        window.addEventListener('message', onMessage);
        frame.src = `${job.page}?auto=1`;
        document.body.appendChild(frame);
    });
}

/** Claim a pending job and print it; reports printed / failed. Resolves false when another screen took it. */
export async function printJob(id, method) {
    let job;
    try {
        job = await request(route('print-jobs.claim', id), { method: 'POST' });
    } catch (error) {
        if (error.status === 409) return false;
        throw error;
    }

    try {
        if (method === 'qz') await printWithQz(job);
        else await printWithBrowser(job);
        await request(route('print-jobs.done', id), { method: 'POST' });
        return true;
    } catch (error) {
        await request(route('print-jobs.failed', id), { method: 'POST', body: { error: error.message } }).catch(() => {});
        error.job = job.title;
        throw error;
    }
}

export async function pendingJobs(printers) {
    if (!printers.length) return [];
    const params = new URLSearchParams();
    printers.forEach((id) => params.append('printers[]', id));
    const data = await request(`${route('print-jobs.pending')}?${params}`);
    return data.jobs;
}
