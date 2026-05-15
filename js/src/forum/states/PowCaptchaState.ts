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

        for (let nonce = 0; ; nonce++) {
            if (this.aborted) throw new Error('aborted');

            const data = encoder.encode(challengePrefix + nonce);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashBytes = new Uint8Array(hashBuffer);

            if (this.hasRequiredLeadingZeros(hashBytes, difficulty)) {
                return `${challenge}:${nonce}`;
            }

            // Yield periodically to keep the UI responsive.
            if (nonce % 1024 === 0) {
                await new Promise<void>((r) => setTimeout(r, 0));
            }
        }
    }

    private hasRequiredLeadingZeros(hashBytes: Uint8Array, difficulty: number): boolean {
        const requiredFullZeroBytes = Math.floor(difficulty / 2);

        for (let index = 0; index < requiredFullZeroBytes; index++) {
            if (hashBytes[index] !== 0) {
                return false;
            }
        }

        if (difficulty % 2 === 1) {
            return (hashBytes[requiredFullZeroBytes] & 0xf0) === 0;
        }

        return true;
    }
}
