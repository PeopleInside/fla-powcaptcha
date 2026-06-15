import app from 'flarum/forum/app';

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
    public status: PowStatus = 'idle';
    public errorMessage: string | null = null;
    public isSubmitQueued = false;
    public onSolvedCallback?: () => void;

    private token: string | null = null;
    private aborted = false;

    // ─── Public API ───────────────────────────────────────────────────

    /** Start fetching a challenge and solving it. Safe to call multiple times. */
    async start(): Promise<void> {
        if (this.status !== 'idle') return;

        this.aborted = false;
        this.status = 'loading';
        m.redraw();

        try {
            const { challenge, difficulty } = await this.fetchChallenge();

            if (this.aborted) return;

            this.status = 'solving';
            m.redraw();

            const solution = await this.solve(challenge, difficulty);

            if (this.aborted) return;

            this.token = solution;
            this.status = 'solved';
            m.redraw();

            if (this.onSolvedCallback) {
                this.onSolvedCallback();
            }
        } catch (err: any) {
            if (this.aborted) return;
            this.status = 'error';
            this.errorMessage = err?.message ?? String(err);
            this.isSubmitQueued = false;
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
        this.status = 'idle';
        this.token = null;
        this.errorMessage = null;
        this.isSubmitQueued = false;
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
     * We use the Web Crypto API (available in all modern browsers) and yield
     * back to the event loop optionally to keep the UI responsive.
     */
    private async solve(challenge: string, difficulty: number): Promise<string> {
        const encoder = new TextEncoder();
        const prefix = `${challenge}:`;
        const startedAt = Date.now();
        const normalizedDiff = Math.max(1, Math.min(8, difficulty));
        const { maxAttempts, maxDuration } = this.getSolveLimits(normalizedDiff);

        let lastYieldAt = Date.now();

        for (let nonce = 0; nonce < maxAttempts; nonce++) {
            if (this.aborted) throw new Error('aborted');

            if (nonce % 4096 === 0) {
                const now = Date.now();
                if (now - startedAt > maxDuration) {
                    throw new Error('challenge solve timeout');
                }
                if (now - lastYieldAt > 32) {
                    await new Promise<void>((r) => setTimeout(r, 0));
                    lastYieldAt = Date.now();
                }
            }

            const data = encoder.encode(prefix + nonce);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);

            if (this.meetsHashDifficulty(new Uint8Array(hashBuffer), normalizedDiff)) {
                return `${challenge}:${nonce}`;
            }
        }

        throw new Error('challenge solve iteration limit reached');
    }

    private meetsHashDifficulty(hashBytes: Uint8Array, difficulty: number): boolean {
        // Difficulty is measured in leading zero hex chars (nibbles).
        const requiredFullZeroBytes = Math.floor(difficulty / 2);

        for (let byteIndex = 0; byteIndex < requiredFullZeroBytes; byteIndex++) {
            if (hashBytes[byteIndex] !== 0) {
                return false;
            }
        }

        if (difficulty % 2 === 1) {
            // Odd difficulty needs one extra zero nibble (high 4 bits).
            return (hashBytes[requiredFullZeroBytes] & 0xf0) === 0;
        }

        return true;
    }
}
