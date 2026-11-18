import { sha256 } from 'js-sha256';

export class SolverError extends Error {
  constructor(public kind: 'aborted' | 'timeout' | 'limit') {
    super(kind);
  }
}

let channel: MessageChannel | null = null;

/** Yields to the event loop without the 4 ms clamp of nested setTimeout. */
export function yieldToUi(): Promise<void> {
  if (typeof MessageChannel === 'undefined') return new Promise((r) => setTimeout(r, 0));
  channel = channel ?? new MessageChannel();
  const ch = channel;
  return new Promise((resolve) => {
    ch.port1.onmessage = () => resolve();
    ch.port2.postMessage(0);
  });
}

/**
 * Finds a nonce such that sha256(`${challenge}:${nonce}`) starts with `bits` zero
 * BITS. Difficulty is in bits (12-24), matching the server.
 */
export async function solve(challenge: string, bits: number, cancelled: () => boolean): Promise<string> {
  const b = Math.max(1, Math.min(32, Math.floor(bits)));
  const fullHex = b >> 2; // whole hex zeros
  const rest = b & 3; // remaining bits in the next hex digit
  const zeros = '0'.repeat(fullHex);
  const prefix = challenge + ':';
  const maxTries = 16 * Math.pow(2, b);
  const deadline = Date.now() + (b >= 24 ? 240000 : 120000);

  let n = 0;
  while (n < maxTries) {
    const sliceEnd = Date.now() + 10;
    do {
      for (let i = 0; i < 256; i++, n++) {
        const hash = sha256(prefix + n);
        if (hash.startsWith(zeros) && (rest === 0 || parseInt(hash[fullHex], 16) < 16 >> rest)) return prefix + n;
      }
    } while (Date.now() < sliceEnd);

    if (cancelled()) throw new SolverError('aborted');
    if (Date.now() > deadline) throw new SolverError('timeout');
    await yieldToUi();
  }

  throw new SolverError('limit');
}
