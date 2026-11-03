import { sha256 } from 'js-sha256';

/**
 * Pure Proof-of-Work solver (no Flarum dependency, so it can be tested in Node).
 *
 * Finds a nonce such that SHA-256("<challenge>:<nonce>") starts with `difficulty`
 * hexadecimal zeros. Work is split into ~10 ms slices so the page stays responsive,
 * and the loop yields through a MessageChannel (not setTimeout) because browsers
 * throttle timers in background tabs to ~1 per second, which would stall the solve.
 */
export class PowSolveError extends Error {
    constructor(public readonly kind: 'aborted' | 'timeout' | 'limit') {
        super(kind);
    }
}

const SLICE_MS = 10;
const BATCH = 256;

let channel: MessageChannel | null = null;

function yieldToBrowser(): Promise<void> {
    if (typeof MessageChannel === 'undefined') {
        return new Promise((resolve) => setTimeout(resolve, 0));
    }

    channel = channel ?? new MessageChannel();
    const port = channel;

    return new Promise((resolve) => {
        port.port1.onmessage = () => resolve();
        port.port2.postMessage(0);
    });
}

/** Expected number of hashes is 16^difficulty. Allow 16x that (failure chance ~1e-7). */
export function maxAttemptsFor(difficulty: number): number {
    return Math.pow(16, difficulty) * 16;
}

export function maxDurationFor(difficulty: number): number {
    return difficulty >= 6 ? 240_000 : 120_000;
}

export async function solvePow(challenge: string, difficulty: number, isCancelled: () => boolean): Promise<string> {
    const d = Math.max(1, Math.min(8, Math.floor(difficulty)));
    const zeros = '0'.repeat(d);
    const prefix = challenge + ':';
    const maxAttempts = maxAttemptsFor(d);
    const deadline = Date.now() + maxDurationFor(d);

    let nonce = 0;

    while (nonce < maxAttempts) {
        const sliceEnd = Date.now() + SLICE_MS;

        do {
            for (let i = 0; i < BATCH; i++, nonce++) {
                if (sha256(prefix + nonce).startsWith(zeros)) {
                    return prefix + nonce;
                }
            }
        } while (Date.now() < sliceEnd);

        if (isCancelled()) throw new PowSolveError('aborted');
        if (Date.now() > deadline) throw new PowSolveError('timeout');

        await yieldToBrowser();
    }

    throw new PowSolveError('limit');
}
