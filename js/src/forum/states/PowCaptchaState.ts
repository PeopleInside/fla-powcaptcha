import app from 'flarum/forum/app';
import { PowSolveError, solvePow } from '../solver';

declare const m: any;

export type PowStatus = 'idle' | 'loading' | 'solving' | 'solved' | 'error';
export type PowAction = 'login' | 'signup' | 'forgot';

/** The server accepts a challenge for 300 s after issuing it; refresh a bit earlier. */
const MAX_AGE_MS = 270_000;

class ChallengeError extends Error {
    constructor(public readonly kind: 'rate_limited' | 'unavailable', public readonly retryAfter = 0) {
        super(kind);
    }
}

export default class PowCaptchaState {
    readonly id = 'pow-' + Math.random().toString(36).slice(2, 10);

    private status: PowStatus = 'idle';
    private token: string | null = null;
    private issuedAt = 0;
    private runId = 0;
    private errorKey = 'error';
    private errorParams: Record<string, unknown> = {};
    private honeypot = '';
    private waiters: Array<(status: PowStatus) => void> = [];

    constructor(public readonly action: PowAction) {}

    getStatus(): PowStatus {
        return this.status;
    }

    /** Translated message for the error state. */
    getErrorMessage(): string {
        return app.translator.trans('peopleinside-powcaptcha.forum.' + this.errorKey, this.errorParams) as string;
    }

    getHoneypotValue(): string {
        return this.honeypot;
    }

    setHoneypotValue(value: string): void {
        this.honeypot = value;
    }

    /** True when a solved token is about to expire on the server. */
    isStale(): boolean {
        return this.status === 'solved' && Date.now() - this.issuedAt > MAX_AGE_MS;
    }

    /** The solved, still valid token (`challenge:nonce`), or null. */
    getResponse(): string | null {
        return this.status === 'solved' && !this.isStale() ? this.token : null;
    }

    reset(): void {
        this.runId++;
        this.token = null;
        this.setStatus('idle');
    }

    retry(): void {
        this.reset();
        void this.start();
    }

    waitUntilSettled(timeoutMs: number): Promise<PowStatus> {
        if (this.status !== 'loading' && this.status !== 'solving') {
            return Promise.resolve(this.status);
        }

        return new Promise((resolve) => {
            const timer = setTimeout(() => {
                this.waiters = this.waiters.filter((w) => w !== done);
                resolve(this.status);
            }, timeoutMs);

            const done = (status: PowStatus) => {
                clearTimeout(timer);
                resolve(status);
            };

            this.waiters.push(done);
        });
    }

    async start(): Promise<void> {
        if (this.status === 'loading' || this.status === 'solving') return;

        const run = ++this.runId;
        const cancelled = () => run !== this.runId;

        this.token = null;
        this.setStatus('loading');

        try {
            const { challenge, difficulty } = await this.fetchChallenge();
            if (cancelled()) return;

            this.issuedAt = Date.now();
            this.setStatus('solving');

            const token = await solvePow(challenge, difficulty, cancelled);
            if (cancelled()) return;

            this.token = token;
            this.setStatus('solved');
        } catch (e) {
            if (cancelled() || (e instanceof PowSolveError && e.kind === 'aborted')) return;

            if (e instanceof ChallengeError && e.kind === 'rate_limited') {
                this.errorKey = 'rate_limited';
                this.errorParams = { seconds: e.retryAfter || 30 };
            } else {
                this.errorKey = 'error';
                this.errorParams = {};
            }

            this.token = null;
            this.setStatus('error');
        }
    }

    private setStatus(status: PowStatus): void {
        this.status = status;

        if (status !== 'loading' && status !== 'solving') {
            const waiters = this.waiters;
            this.waiters = [];
            waiters.forEach((resolve) => resolve(status));
        }

        m.redraw();
    }

    private async fetchChallenge(): Promise<{ challenge: string; difficulty: number }> {
        const base = app.forum.attribute<string>('apiUrl').replace(/\/$/, '');
        let response: Response;

        try {
            response = await fetch(`${base}/powcaptcha/challenge?action=${encodeURIComponent(this.action)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
        } catch (e) {
            throw new ChallengeError('unavailable');
        }

        if (response.status === 429) {
            const retry = parseInt(response.headers.get('Retry-After') ?? '30', 10);
            throw new ChallengeError('rate_limited', Number.isFinite(retry) ? retry : 30);
        }

        if (!response.ok) throw new ChallengeError('unavailable');

        const data = await response.json().catch(() => null);

        if (!data || typeof data.challenge !== 'string') throw new ChallengeError('unavailable');

        // The difficulty inside the signed challenge is authoritative.
        const embedded = parseInt(data.challenge.split('.')[3] ?? '', 10);
        const difficulty = Number.isFinite(embedded) ? embedded : Number(data.difficulty);

        if (!Number.isFinite(difficulty) || difficulty < 1 || difficulty > 8) throw new ChallengeError('unavailable');

        return { challenge: data.challenge, difficulty };
    }
}
