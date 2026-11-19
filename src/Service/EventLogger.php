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
 * agent, no username or e-mail. It keeps the last N events per outcome (N is the
 * `log_keep` setting, default 100) and deletes older rows automatically. Rows older than
 * `log_max_age_days` (default 30, on by default via `log_prune_age`) are deleted too, so
 * a quiet site does not keep old entries (and IPs, if enabled) indefinitely. Admins can
 * also clear it manually.
 */
class EventLogger
{
    public const TABLE = 'powcaptcha_logs';
    /** Default only; the effective value comes from PowSettings::logKeep(). */
    public const KEEP = PowSettings::LOG_KEEP_DEFAULT;
    public const MAX_WRITES_PER_MINUTE = 40;
    public const PRUNE_INTERVAL = 30;

    private const ACTIONS = ['login', 'signup', 'forgot', 'token'];
    private const REASONS = [
        'ok', 'missing', 'malformed', 'wrong_action', 'bad_signature', 'wrong_network',
        'expired', 'too_fast', 'bad_solution', 'replayed', 'honeypot', 'cache_error',
        'rate_limited', 'signup_limit', 'suspicious',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CacheGuard $cache,
        private readonly RiskTracker $risk,
        private readonly PowSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly StatsTracker $stats
    ) {
    }

    public function record(string $action, bool $ok, string $reason, int $difficulty, ?string $ip = null): void
    {
        // Anonymous daily counters for the dashboard widget. Independent of the event log:
        // they keep working even if the log is disabled or capped.
        $this->stats->hit($ok);

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

            $ipToRecord = null;
            if ($this->settings->logIp() && is_string($ip) && trim($ip) !== '') {
                $ipToRecord = substr(trim($ip), 0, 45);
            }

            $row = [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'action' => in_array($action, self::ACTIONS, true) ? $action : 'login',
                'result' => $result,
                'reason' => in_array($reason, self::REASONS, true) ? $reason : 'malformed',
                'difficulty' => max(0, min(255, $difficulty)),
                'ip' => $ipToRecord,
            ];

            // If `php flarum migrate` was not run after updating to 3.8.1, the `ip`
            // column does not exist and every insert would fail silently (empty log).
            if (! $this->hasIpColumn()) {
                unset($row['ip']);
            }

            $this->db->table(self::TABLE)->insert($row);

            // Pruning costs several queries, so it runs at most once every PRUNE_INTERVAL
            // seconds instead of on every write (the table can overshoot the limit by a
            // few rows in between; latest() trims it again whenever the admin looks).
            if ($this->cache->hit($this->risk->instancePrefix() . 'logprune:' . intdiv(time(), self::PRUNE_INTERVAL), self::PRUNE_INTERVAL * 2) === 1) {
                $this->pruneAll();
            }
        } catch (\Throwable $e) {
            // Logging must never break login or registration.
            $this->logger->warning('[fla-powcaptcha] Could not write the event log: ' . $e->getMessage());
        }
    }

    /** @return list<array{id:int,ts:int,action:string,result:string,reason:string,difficulty:int,ip:?string}> */
    public function latest(): array
    {
        // On a quiet site nothing is written, so the age limit is also enforced when
        // the admin opens the log.
        try {
            $this->pruneAll();
        } catch (\Throwable $e) {
            $this->logger->warning('[fla-powcaptcha] Could not prune old log entries: ' . $e->getMessage());
        }

        $rows = $this->db->table(self::TABLE)->orderByDesc('id')->limit($this->keep() * 2)->get();
        $out = [];
        $showIp = $this->settings->logIp();

        foreach ($rows as $row) {
            $ipValue = null;
            if ($showIp && isset($row->ip) && $row->ip !== null && (string)$row->ip !== '') {
                $ipValue = (string) $row->ip;
            }

            $out[] = [
                'id' => (int) $row->id,
                'ts' => (int) strtotime($row->created_at . ' UTC'),
                'action' => (string) $row->action,
                'result' => (string) $row->result,
                'reason' => (string) $row->reason,
                'difficulty' => (int) $row->difficulty,
                'ip' => $ipValue,
            ];
        }

        return $out;
    }

    private function hasIpColumn(): bool
    {
        static $has = null;

        if ($has === null) {
            try {
                $has = $this->db->getSchemaBuilder()->hasColumn(self::TABLE, 'ip');
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }

    /** Entries kept per outcome (configurable in the admin settings). */
    public function keep(): int
    {
        return $this->settings->logKeep();
    }

    /** Maximum age of an entry in days, or null when age-based pruning is off. */
    public function maxAgeDays(): ?int
    {
        return $this->settings->logPruneByAge() ? $this->settings->logMaxAgeDays() : null;
    }

    /** Trims both outcomes to the size limit and removes entries past the age limit. */
    private function pruneAll(): void
    {
        $keep = $this->settings->logKeep();
        $this->prune('ok', $keep);
        $this->prune('fail', $keep);
        $this->pruneOld();
    }

    /** Deletes entries older than the configured number of days. Returns the rows deleted. */
    public function pruneOld(): int
    {
        $days = $this->maxAgeDays();

        if ($days === null) {
            return 0;
        }

        // created_at is stored in UTC (see record()).
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        return (int) $this->db->table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
    }

    public function clear(): int
    {
        return (int) $this->db->table(self::TABLE)->delete();
    }

    private function prune(string $result, int $keep): void
    {
        $cutoff = $this->db->table(self::TABLE)
            ->where('result', $result)
            ->orderByDesc('id')
            ->offset($keep)
            ->limit(1)
            ->value('id');

        if ($cutoff !== null) {
            $this->db->table(self::TABLE)->where('result', $result)->where('id', '<=', $cutoff)->delete();
        }
    }
}
