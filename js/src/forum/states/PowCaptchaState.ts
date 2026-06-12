import app from 'flarum/forum/app';
// `m` is a UMD global provided by Flarum core (mithril), no local import needed.

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
    private static readonly SOLVE_YIELD_INTERVAL = 1024;
    private static readonly MAX_SOLVE_ATTEMPTS = 2_000_000;
    private static readonly MAX_SOLVE_DURATION_MS = 15_000;

    public status: PowStatus = 'idle';
    public errorMessage: string | null = null;

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
        } catch (err: any) {
            if (this.aborted) return;
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

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json() as Promise<{ challenge: string; difficulty: number }>;
    }

    /**
     * Brute-force a nonce N such that SHA-256(`${challenge}:${N}`) starts with
     * `difficulty` hex zeros.
     *
     * We use the Web Crypto API (available in all modern browsers) and yield
     * back to the event loop every 200 iterations to keep the UI responsive.
     */
    private async solve(challenge: string, difficulty: number): Promise<string> {
        const encoder = new TextEncoder();
        const challengePrefix = `${challenge}:`;
        const startedAt = Date.now();
        const normalizedDifficulty = Math.max(1, Math.min(8, difficulty));

        // Dynamically adjust solve attempt limits and maximum duration based on difficulty.
        // This ensures users on slow, old or power-constrained mobile devices don't see
        // their captcha solve process timeout unexpectedly on higher levels.
        let maxAttempts = PowCaptchaState.MAX_SOLVE_ATTEMPTS;
        let maxDuration = PowCaptchaState.MAX_SOLVE_DURATION_MS;

        if (normalizedDifficulty <= 2) {
            maxAttempts = 500_000;
            maxDuration = 10_000; // 10s is plenty for level 1-2
        } else if (normalizedDifficulty === 3) {
            maxAttempts = 2_000_000;
            maxDuration = 20_000; // 20s for standard level
        } else if (normalizedDifficulty === 4) {
            maxAttempts = 10_000_000;
            maxDuration = 60_000; // 1m for hard level
        } else if (normalizedDifficulty === 5) {
            maxAttempts = 35_000_000;
            maxDuration = 180_000; // 3m for very hard level
        } else {
            // Difficulty levels 6, 7 or 8 (developer customized overrides)
            maxAttempts = 100_000_000;
            maxDuration = 300_000; // 5m max override
        }

        let lastYieldAt = Date.now();

        for (let nonce = 0; nonce < maxAttempts; nonce++) {
            if (this.aborted) throw new Error('aborted');

            // Yield periodically to keep UI responsive without adding nested setTimeout slop/throttling.
            if (nonce % 4096 === 0) {
                const now = Date.now();
                if (now - startedAt > maxDuration) {
                    throw new Error('challenge solve timeout');
                }
                if (now - lastYieldAt > 32) { // Yield only if CPU has been locked for > 32ms
                    await new Promise<void>((r) => setTimeout(r, 0));
                    lastYieldAt = Date.now();
                }
            }

            const data = encoder.encode(challengePrefix + nonce);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashBytes = new Uint8Array(hashBuffer);

            if (this.meetsHashDifficulty(hashBytes, normalizedDifficulty)) {
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
