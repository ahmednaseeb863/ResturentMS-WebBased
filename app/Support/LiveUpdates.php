<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Something changed" stamps for screens that refresh themselves (no WebSockets — the app
 * runs on local servers and shared hosting). Each branch has one stamp per topic in the
 * cache; screens poll `live.poll` every few seconds and reload their data only when a
 * stamp moved. Topics:
 *   kitchen  — tickets sent / started / ready / served / voided (kitchen display)
 *   orders   — events kept for a few minutes: an order became ready / a station's items are
 *              ready (`kind` ready / items_ready — POS and waiter alerts), the waiter asked
 *              for the bill (`kind` bill — POS alert)
 *   floor    — an order or table changed (waiter app table grid / table screen)
 *   deliveries — a delivery changed (board, riders, rider panel); event `kind` assigned
 *              tells the rider a new delivery is theirs
 *   printers — a print job is waiting (print agent)
 */
class LiveUpdates
{
    public const TOPICS = ['kitchen', 'orders', 'floor', 'deliveries', 'printers'];

    private const EVENTS_KEPT = 30;

    private const EVENTS_FOR = 600; // seconds

    /** Mark a topic changed once the transaction commits; never breaks the request. */
    public static function bump(string $topic, int $branchId, ?array $event = null): void
    {
        DB::afterCommit(function () use ($topic, $branchId, $event) {
            rescue(function () use ($topic, $branchId, $event) {
                $stamp = static::now();
                Cache::forever(static::key($branchId, $topic), $stamp);

                if ($event !== null) {
                    Cache::lock(static::key($branchId, "{$topic}-events-lock"), 5)->block(3, function () use ($topic, $branchId, $event, $stamp) {
                        $key = static::key($branchId, "{$topic}-events");
                        $events = collect(Cache::get($key, []))
                            ->filter(fn ($e) => $e['stamp'] > $stamp - static::EVENTS_FOR * 1000)
                            ->push([...$event, 'stamp' => $stamp])
                            ->take(-static::EVENTS_KEPT)
                            ->values()
                            ->all();
                        Cache::forever($key, $events);
                    });
                }
            }, report: true);
        });
    }

    /**
     * Current stamps of the topics, plus the events since `$since` (ms) of the topics that have them.
     *
     * @param  list<string>  $topics
     * @return array{stamp: int, versions: array<string, int>, events: array<string, list<array>>}
     */
    public static function poll(int $branchId, array $topics, ?int $since): array
    {
        $versions = [];
        $events = [];

        foreach ($topics as $topic) {
            $versions[$topic] = (int) Cache::get(static::key($branchId, $topic), 0);

            if ($since !== null) {
                $new = array_values(array_filter(
                    Cache::get(static::key($branchId, "{$topic}-events"), []),
                    fn ($e) => $e['stamp'] >= $since,
                ));
                if ($new) {
                    $events[$topic] = $new;
                }
            }
        }

        return ['stamp' => static::now(), 'versions' => $versions, 'events' => $events];
    }

    private static function key(int $branchId, string $topic): string
    {
        return "live:{$branchId}:{$topic}";
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
