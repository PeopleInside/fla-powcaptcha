<?php

namespace PeopleInside\PowCaptcha\Service;

use Illuminate\Database\ConnectionInterface;
use PeopleInside\PowCaptcha\Support\CacheGuard;
use Psr\Log\LoggerInterface;

/**
 * Anonymous daily counters (passed / blocked) for the admin dashboard widget.
 *
 * Unlike the event log, these counters are not capped at N entries, so the widget stays
 * accurate even on a busy site. Only a date and two numbers are stored: no IP, no user
 * agent, no personal data. Days are UTC. Rows older than KEEP_DAYS are deleted.
 * Counting is skipped entirely when the dashboard widget option is turned off.
 *
 * Performance: a check only increments an atomic counter in the cache. The counters are
 * moved to the database in one write at most every FLUSH_INTERVAL seconds (and whenever
 * the admin opens the widget), so a flood of requests costs no database writes here.
 */
class StatsTracker
{
    public const TABLE = 'powcaptcha_stats';
    public const KEEP_DAYS = 90;
    public const FLUSH_INTERVAL = 30;
    /** Buffered counts survive this long if no request flushes them (2 days). */
    private const PENDING_TTL = 172800;

    private const COLUMNS = ['passed', 'blocked'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CacheGuard $cache,
        private readonly RiskTracker $risk,
        private readonly PowSettings $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public function hit(bool $ok): void
    {
        if (! $this->settings->dashboardWidget()) {
            return;
        }

        $column = $ok ? 'passed' : 'blocked';
        $day = gmdate('Y-m-d');

        try {
            $this->cache->hit($this->pendingKey($day, $column), self::PENDING_TTL);
        } catch (\Throwable $e) {
            // Cache unavailable: count this one directly so it is not lost.
            try {
                $this->add($day, $column, 1);
            } catch (\Throwable $e2) {
                $this->logger->warning('[fla-powcaptcha] Could not update the statistics: ' . $e2->getMessage());
            }

            return;
        }

        try {
            // Only the first request of each window pays for the database write.
            $this->flushIfDue(self::FLUSH_INTERVAL);
        } catch (\Throwable $e) {
            // Statistics must never break login or registration.
            $this->logger->warning('[fla-powcaptcha] Could not save the statistics: ' . $e->getMessage());
        }
    }

    /**
     * @return array{ready:bool,today:array{passed:int,blocked:int},week:array{passed:int,blocked:int}}
     */
    public function summary(): array
    {
        $out = [
            'ready' => true,
            'today' => ['passed' => 0, 'blocked' => 0],
            'week' => ['passed' => 0, 'blocked' => 0],
        ];

        try {
            // Make the numbers fresh when the admin looks (short window: no flood risk here).
            $this->flushIfDue(3);

            $today = gmdate('Y-m-d');
            // Rolling 7 days, today included.
            $from = gmdate('Y-m-d', time() - 6 * 86400);

            $rows = $this->db->table(self::TABLE)->where('day', '>=', $from)->get();

            foreach ($rows as $row) {
                $out['week']['passed'] += (int) $row->passed;
                $out['week']['blocked'] += (int) $row->blocked;

                if (substr((string) $row->day, 0, 10) === $today) {
                    $out['today']['passed'] += (int) $row->passed;
                    $out['today']['blocked'] += (int) $row->blocked;
                }
            }
        } catch (\Throwable $e) {
            // Most likely `php flarum migrate` has not been run yet.
            $out['ready'] = false;
            $this->logger->warning('[fla-powcaptcha] Could not read the statistics: ' . $e->getMessage());
        }

        return $out;
    }

    /** Runs flush() for the first caller of each $interval-second window only. */
    private function flushIfDue(int $interval): void
    {
        $gate = $this->risk->instancePrefix() . 'stats:gate:' . $interval . ':' . intdiv(time(), $interval);

        if ($this->cache->hit($gate, $interval * 2) === 1) {
            $this->flush();
        }
    }

    /** Moves the buffered counts of today and yesterday (UTC) into the table. */
    private function flush(): void
    {
        foreach ([gmdate('Y-m-d'), gmdate('Y-m-d', time() - 86400)] as $day) {
            foreach (self::COLUMNS as $column) {
                $key = $this->pendingKey($day, $column);
                $pending = $this->cache->count($key);

                if ($pending <= 0) {
                    continue;
                }

                // Subtract exactly what was read: hits that arrive meanwhile are kept.
                $this->cache->decrement($key, $pending);

                try {
                    $this->add($day, $column, $pending);
                } catch (\Throwable $e) {
                    // Database failed (e.g. table missing): give the counts back.
                    $this->cache->put($key, $this->cache->count($key) + $pending, self::PENDING_TTL);

                    throw $e;
                }
            }
        }
    }

    private function add(string $day, string $column, int $amount): void
    {
        $updated = $this->db->table(self::TABLE)->where('day', $day)->increment($column, $amount);

        if ((int) $updated > 0) {
            return;
        }

        try {
            $this->db->table(self::TABLE)->insert([
                'day' => $day,
                'passed' => $column === 'passed' ? $amount : 0,
                'blocked' => $column === 'blocked' ? $amount : 0,
            ]);

            // A new day has started: drop very old rows (runs once a day).
            $this->prune();
        } catch (\Throwable $e) {
            // Another request created the row first: just add to it.
            $this->db->table(self::TABLE)->where('day', $day)->increment($column, $amount);
        }
    }

    private function pendingKey(string $day, string $column): string
    {
        return $this->risk->instancePrefix() . 'stats:pending:' . $day . ':' . $column;
    }

    private function prune(): void
    {
        $cutoff = gmdate('Y-m-d', time() - self::KEEP_DAYS * 86400);

        $this->db->table(self::TABLE)->where('day', '<', $cutoff)->delete();
    }
}
