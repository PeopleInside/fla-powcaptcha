/**
 * Anonymous interaction summary sent with each submit: trusted pointer / key / focus
 * event counts and time on page. Read by the server's behaviour checks.
 */
const counters = { p: 0, k: 0, f: 0 };
const start = Date.now();
let lastMove = 0;

if (typeof document !== 'undefined') {
  const opts = { passive: true, capture: true } as const;
  document.addEventListener('pointerdown', (e) => { if (e.isTrusted) counters.p++; }, opts);
  document.addEventListener('touchstart', (e) => { if (e.isTrusted) counters.p++; }, opts);
  document.addEventListener('pointermove', (e) => {
    const now = Date.now();
    if (e.isTrusted && now - lastMove > 100) {
      lastMove = now;
      counters.p++;
    }
  }, opts);
  document.addEventListener('keydown', (e) => { if (e.isTrusted) counters.k++; }, opts);
  document.addEventListener('input', (e) => { if (e.isTrusted) counters.k++; }, opts);
  document.addEventListener('focusin', (e) => { if (e.isTrusted) counters.f++; }, opts);
}

export default function behaviorSummary(): string {
  const cap = Math.min;
  return `${cap(counters.p, 999999)}.${cap(counters.k, 999999)}.${cap(counters.f, 999999)}.${cap(Date.now() - start, 999999999)}`;
}
