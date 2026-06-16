import app from 'flarum/forum/app';
import { sha256 } from 'js-sha256';

declare const m: any;

export type PowStatus = 'idle' | 'loading' | 'solving' | 'solved' | 'error';

/**
 * Manages the lifecycle of a single Proof-of-Work challenge.
 *
 * Flow:
 *   idle  ──► loading  ──► solving  ──► solved
 *   idle  ──► loading  ──► error
 *   solved/error ──► idle  (after reset())
 */
export default class PowCaptchaState {
    public id: string = Math.random().toString(36).slice(2, 9);
    public status: PowStatus = 'idle';
    public errorMessage: string | null = null;

    private token: string | null = null;
    private aborted = false;
    private currentRunId = 0;

    // ─── Public API ───────────────────────────────────────────────────

    /** Start fetching a challenge and solving it. Safe to call multiple times. */
    async start(): Promise<void> {
        if (this.status !== 'idle') return;

        this.aborted = false;
        const runId = ++this.currentRunId;
        this.status = 'loading';
        m.redraw();

        try {
            const { challenge, difficulty } = await this.fetchChallenge();

            if (this.aborted || runId !== this.currentRunId) return;

            this.status = 'solving';
            m.redraw();

            const solution = await this.solve(challenge, difficulty, runId);

            if (this.aborted || runId !== this.currentRunId) return;

            this.token = solution;
            this.status = 'solved';
            m.redraw();
        } catch (err: any) {
            if (this.aborted || runId !== this.currentRunId) return;
            this.status = 'error';
            this.errorMessage = err?.message ?? String(err);
            m.redraw();
        }
    }

    /** Return the solved token, or null if not yet solved. */
    getResponse(): string | null {
        return this.status === 'solved' ? this.token : null;
    }

    getStatus(): PowStatus {
        return this.status;
    }

    /** Reset to idle so start() can be called again. */
    reset(): void {
        this.aborted = true;
        this.currentRunId++;
        this.status = 'idle';
        this.token = null;
        this.errorMessage = null;
        m.redraw();
    }

    /** Convenience: reset then start a new challenge. */
    async retry(): Promise<void> {
        this.reset();
        await this.start();
    }

    // ─── Internal helpers ─────────────────────────────────────────────

    private async fetchChallenge(): Promise<{ challenge: string; difficulty: number }> {
        const apiUrl = (app.forum.attribute<string>('apiUrl') as string) || '/api';
        const url = apiUrl.replace(/\/$/, '') + '/powcaptcha/challenge';

        return app.request<{ challenge: string; difficulty: number }>({
            method: 'GET',
            url: url,
        });
    }

    private getSolveLimits(difficulty: number): { maxAttempts: number; maxDuration: number } {
        if (difficulty <= 2) {
            return { maxAttempts: 500_000, maxDuration: 15_000 };
        }
        if (difficulty === 3) {
            return { maxAttempts: 3_000_000, maxDuration: 45_000 };
        }
        if (difficulty === 4) {
            return { maxAttempts: 15_000_000, maxDuration: 120_000 };
        }
        if (difficulty === 5) {
            return { maxAttempts: 45_000_000, maxDuration: 300_000 };
        }
        return { maxAttempts: 100_000_000, maxDuration: 600_000 };
    }

    /**
     * Brute-force a nonce N such that SHA-256(`${challenge}:${N}`) starts with
     * `difficulty` hex zeros.
     *
     * We use a synchronous pure-JS sha256 library to maximize performance
     * and avoid excessive asynchronous microtask overhead in the loop,
     * while yielding occasionally to keep the browser UI responsive.
     */
    private async solve(challenge: string, difficulty: number, runId: number): Promise<string> {
        const prefix = `${challenge}:`;
        const startedAt = Date.now();
        const normalizedDiff = Math.max(1, Math.min(8, difficulty));
        const { maxAttempts, maxDuration } = this.getSolveLimits(normalizedDiff);
        const requiredPrefix = '0'.repeat(normalizedDiff);

        let lastYieldAt = Date.now();

        for (let nonce = 0; nonce < maxAttempts; nonce++) {
            if (this.aborted || runId !== this.currentRunId) throw new Error('aborted');

            // Yield periodically to prevent blocking the UI
            if (nonce % 2048 === 0) {
                const now = Date.now();
                if (now - startedAt > maxDuration) {
                    throw new Error('challenge solve timeout');
                }
                if (now - lastYieldAt > 16) {
                    await new Promise<void>((r) => setTimeout(r, 0));
                    lastYieldAt = Date.now();
                }
            }

            const hashHex = sha256(prefix + nonce);
            if (hashHex.startsWith(requiredPrefix)) {
                return `${challenge}:${nonce}`;
            }
        }

        throw new Error('challenge solve iteration limit reached');
    }
}
