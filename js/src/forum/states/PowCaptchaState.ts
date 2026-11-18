import app from 'flarum/forum/app';
import { SolverError, solve } from '../solver';

export type PowStatus = 'idle' | 'loading' | 'solving' | 'solved' | 'error';

class ChallengeError extends Error {
  constructor(public kind: 'unavailable' | 'rate_limited', public retryAfter = 0) {
    super(kind);
  }
}

/** One instance per modal: fetches a challenge, solves it, exposes the token. */
export default class PowCaptchaState {
  id = 'pow-' + Math.random().toString(36).slice(2, 10);
  status: PowStatus = 'idle';
  token: string | null = null;
  issuedAt = 0;
  runId = 0;
  errorKey = 'error';
  errorParams: Record<string, unknown> = {};
  honeypot = '';
  waiters: Array<(s: PowStatus) => void> = [];

  constructor(public action: string) {}

  getStatus() { return this.status; }

  getErrorMessage() {
    return app.translator.trans('peopleinside-powcaptcha.forum.' + this.errorKey, this.errorParams);
  }

  getHoneypotValue() { return this.honeypot; }
  setHoneypotValue(v: string) { this.honeypot = v; }

  /** Tokens expire server-side after 5 minutes; treat them as stale after 4.5. */
  isStale() { return this.status === 'solved' && Date.now() - this.issuedAt > 270000; }

  getResponse(): string | null {
    return this.status !== 'solved' || this.isStale() ? null : this.token;
  }

  reset() {
    this.runId++;
    this.token = null;
    this.setStatus('idle');
  }

  retry() {
    this.reset();
    this.start();
  }

  waitUntilSettled(timeoutMs: number): Promise<PowStatus> {
    if (this.status !== 'loading' && this.status !== 'solving') return Promise.resolve(this.status);

    return new Promise((resolve) => {
      const timer = setTimeout(() => {
        this.waiters = this.waiters.filter((w) => w !== waiter);
        resolve(this.status);
      }, timeoutMs);
      const waiter = (s: PowStatus) => {
        clearTimeout(timer);
        resolve(s);
      };
      this.waiters.push(waiter);
    });
  }

  async start() {
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

      const solution = await solve(challenge, difficulty, cancelled);
      if (cancelled()) return;

      this.token = solution;
      this.setStatus('solved');
    } catch (e) {
      if (cancelled() || (e instanceof SolverError && e.kind === 'aborted')) return;

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

  setStatus(status: PowStatus) {
    this.status = status;
    if (status !== 'loading' && status !== 'solving') {
      const waiters = this.waiters;
      this.waiters = [];
      waiters.forEach((w) => w(status));
    }
    m.redraw();
  }

  async fetchChallenge(): Promise<{ challenge: string; difficulty: number }> {
    const base = (app.forum.attribute('apiUrl') as string).replace(/\/$/, '');
    let res: Response;

    try {
      res = await fetch(`${base}/powcaptcha/challenge?action=${encodeURIComponent(this.action)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
    } catch (e) {
      throw new ChallengeError('unavailable');
    }

    if (res.status === 429) {
      const retry = parseInt(res.headers.get('Retry-After') ?? '30', 10);
      throw new ChallengeError('rate_limited', Number.isFinite(retry) ? retry : 30);
    }
    if (!res.ok) throw new ChallengeError('unavailable');

    const body = await res.json().catch(() => null);
    if (!body || typeof body.challenge !== 'string') throw new ChallengeError('unavailable');

    // The difficulty (in bits) is read from the signed challenge itself.
    const fromChallenge = parseInt(body.challenge.split('.')[3] ?? '', 10);
    const difficulty = Number.isFinite(fromChallenge) ? fromChallenge : Number(body.difficulty);
    if (!Number.isFinite(difficulty) || difficulty < 12 || difficulty > 32) throw new ChallengeError('unavailable');

    return { challenge: body.challenge, difficulty };
  }
}
