<?php

namespace PeopleInside\PowCaptcha\Service;

use Illuminate\Database\ConnectionInterface;
use PeopleInside\PowCaptcha\Support\CacheGuard;
use Psr\Log\LoggerInterface;

/**
 * Anonymous event log shown in the admin panel.
 *
 * Privacy by design: only the time, the form, the outcome, a technical reason and
 * the difficulty are stored. No IP address (not even truncated or hashed), no user
 * agent, no username or e-mail. It keeps the last KEEP events per outcome and
 * deletes older rows automatically; admins can also clear it manually.
 */
class EventLogger
{
    public const TABLE = 'powcaptcha_logs';
    public const KEEP = 30;
    public const MAX_WRITES_PER_MINUTE = 40;

    private const ACTIONS = ['login', 'signup', 'forgot', 'token'];
    private const REASONS = [
        'ok', 'missing', 'malformed', 'wrong_action', 'bad_signature', 'wrong_network',
        'expired', 'too_fast', 'bad_solution', 'replayed', 'honeypot', 'cache_error',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CacheGuard $cache,
        private readonly RiskTracker $risk,
        private readonly PowSettings $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(string $action, bool $ok, string $reason, int $difficulty): void
    {
        if (! $this->settings->logEnabled()) {
            return;
        }

        try {
            $result = $ok ? 'ok' : 'fail';

            // During a flood we count, but do not write: bots must not be able to turn
            // every request into database writes.
            $writes = $this->cache->hit(
                $this->risk->instancePrefix() . 'logw:' . $result . ':' . intdiv(time(), 60),
                120
            );

            if ($writes > self::MAX_WRITES_PER_MINUTE) {
                return;
            }

            $this->db->table(self::TABLE)->insert([
                'created_at' => gmdate('Y-m-d H:i:s'),
                'action' => in_array($action, self::ACTIONS, true) ? $action : 'login',
                'result' => $result,
                'reason' => in_array($reason, self::REASONS, true) ? $reason : 'malformed',
                'difficulty' => max(0, min(9, $difficulty)),
            ]);

            $this->prune($result);
        } catch (\Throwable $e) {
            // Logging must never break login or registration.
            $this->logger->warning('[fla-powcaptcha] Could not write the event log: ' . $e->getMessage());
        }
    }

    /** @return list<array{id:int,ts:int,action:string,result:string,reason:string,difficulty:int}> */
    public function latest(): array
    {
        $rows = $this->db->table(self::TABLE)->orderByDesc('id')->limit(self::KEEP * 2)->get();
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'ts' => (int) strtotime($row->created_at . ' UTC'),
                'action' => (string) $row->action,
                'result' => (string) $row->result,
                'reason' => (string) $row->reason,
                'difficulty' => (int) $row->difficulty,
            ];
        }

        return $out;
    }

    public function clear(): int
    {
        return (int) $this->db->table(self::TABLE)->delete();
    }

    private function prune(string $result): void
    {
        $cutoff = $this->db->table(self::TABLE)
            ->where('result', $result)
            ->orderByDesc('id')
            ->offset(self::KEEP)
            ->limit(1)
            ->value('id');

        if ($cutoff !== null) {
            $this->db->table(self::TABLE)->where('result', $result)->where('id', '<=', $cutoff)->delete();
        }
    }
}
