<?php

namespace Resofire\DigestMail\Console;

use Resofire\DigestMail\DigestContent;
use Resofire\DigestMail\DigestQuery;
use Resofire\DigestMail\DigestMailer;
use Resofire\DigestMail\Job\SendDigestJob;
use Resofire\DigestMail\Token\UnsubscribeTokenGenerator;
use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Console command that drives the entire digest send cycle.
 *
 * Scaling design:
 *   1. Shared data cache  — sections identical for every user (awards,
 *      leaderboard, etc.) are built once per frequency run and stored in
 *      Laravel's cache. Each user job reads from cache, not the DB.
 *
 *   2. Chunked user loading — users loaded in configurable chunks (default
 *      200) so memory stays flat regardless of forum size.
 *
 *   3. Delayed dispatch — jobs can be pushed with an available_at delay so
 *      the queue fills before workers start consuming. Combine with the
 *      digest:enqueue command for true two-phase operation.
 *
 *   4. Priority queue — digest jobs go onto a named queue ('digest' by
 *      default) so they don't block other forum notification jobs.
 *
 *   5. Retry + backoff — jobs carry tries and exponential backoff config
 *      so transient mail failures retry automatically.
 */
class SendDigestCommand extends Command
{
    protected $signature = 'digest:send
        {--frequency=  : Run only this frequency (daily|weekly|monthly)}
        {--dry-run     : Print eligible recipients without dispatching}
        {--user=       : Restrict to a single user ID (testing)}
        {--delay=      : Delay each job N seconds (overrides admin setting)}
        {--queue=      : Queue name to push jobs onto (overrides admin setting)}';

    protected $description = 'Send digest emails to subscribed forum members.';

    private const FREQUENCIES      = ['daily', 'weekly', 'monthly'];
    private const SHARED_CACHE_TTL = 7200; // 2 hours

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private DigestQuery                 $query,
        private DigestMailer                $mailer,
        private UnsubscribeTokenGenerator   $tokenGenerator,
        private Queue                       $queue,
        private ConnectionInterface         $db,
        private Cache                       $cache,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun        = (bool) $this->option('dry-run');
        $forcedFrequency = $this->option('frequency');
        $singleUserId    = $this->option('user');

        if ($forcedFrequency !== null && !in_array($forcedFrequency, self::FREQUENCIES, true)) {
            $this->error('Invalid --frequency. Must be one of: ' . implode(', ', self::FREQUENCIES));
            return self::FAILURE;
        }

        $dueFrequencies = $forcedFrequency !== null
            ? [$forcedFrequency]
            : $this->dueFrequencies();

        if (empty($dueFrequencies)) {
            $this->info('No digest frequencies are due at this time. Exiting.');
            return self::SUCCESS;
        }

        $this->info('Due frequencies: ' . implode(', ', $dueFrequencies));

        $totalDispatched = 0;
        $totalSkipped    = 0;

        foreach ($dueFrequencies as $frequency) {
            [$dispatched, $skipped] = $this->processFrequency(
                $frequency,
                $isDryRun,
                $singleUserId !== null ? (int) $singleUserId : null
            );
            $totalDispatched += $dispatched;
            $totalSkipped    += $skipped;
        }

        $this->newLine();
        $this->info("Done. Dispatched: {$totalDispatched}. Skipped: {$totalSkipped}.");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Time-gate
    // -------------------------------------------------------------------------

    private function dueFrequencies(): array
    {
        $timezone   = $this->settings->get('resofire-digest-mail.timezone', 'UTC');
        $now        = Carbon::now($timezone);
        $sendHour   = (int) $this->settings->get('resofire-digest-mail.send_hour',   8);
        $weeklyDay  = (int) $this->settings->get('resofire-digest-mail.weekly_day',  1);
        $monthlyDay = (int) $this->settings->get('resofire-digest-mail.monthly_day', 1);

        if ($now->hour !== $sendHour) return [];

        $due = ['daily'];
        if ($now->dayOfWeek === $weeklyDay) $due[] = 'weekly';
        if ($now->day === $monthlyDay)      $due[] = 'monthly';

        return $due;
    }

    // -------------------------------------------------------------------------
    // Per-frequency processing
    // -------------------------------------------------------------------------

    private function processFrequency(string $frequency, bool $isDryRun, ?int $singleUserId): array
    {
        $since     = $this->periodStart($frequency);
        $cutoff    = $this->lastSentCutoff($frequency);
        $dispatched = 0;
        $skipped    = 0;

        // Resolve queue settings — CLI flags take priority over admin settings.
        $queueName = $this->option('queue')
            ?? $this->settings->get('resofire-digest-mail.queue_name', 'digest');

        $delaySecs = $this->option('delay') !== null
            ? (int) $this->option('delay')
            : (int) $this->settings->get('resofire-digest-mail.queue_delay', 0);

        $chunkSize = max(50, min(500,
            (int) $this->settings->get('resofire-digest-mail.queue_chunk_size', 200)
        ));

        $this->info(
            "Processing '{$frequency}' " .
            "(since: {$since->toDateTimeString()}, " .
            "queue: {$queueName}, delay: {$delaySecs}s, chunk: {$chunkSize})"
        );

        // Build shared data once and cache it for this frequency run.
        // All per-user jobs will read from this cache instead of re-querying.
        $sharedData = null;
        if (!$isDryRun) {
            $cacheKey   = "resofire_digest_shared_{$frequency}_{$since->timestamp}";
            $sharedData = $this->cache->remember(
                $cacheKey,
                self::SHARED_CACHE_TTL,
                fn () => $this->query->buildSharedData($since)
            );
            $this->line("  [cache]    Shared data ready (key: {$cacheKey})");

            // Informational pre-flight: warn if shared sections are empty.
            // We still process users because their unread section may justify
            // sending individually — that cannot be checked without per-user queries.
            if ($this->sharedDataIsEmpty($sharedData)) {
                $this->line("  [preflight] Shared sections are empty. Users with unread discussions will still be processed.");
            }
        }

        // Chunk through eligible users — memory stays flat at O(chunkSize).
        $userQuery = User::query()
            ->where('digest_frequency', $frequency)
            ->where('is_email_confirmed', true)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('digest_last_sent_at')
                  ->orWhere('digest_last_sent_at', '<', $cutoff);
            });

        if ($singleUserId !== null) {
            $userQuery->where('id', $singleUserId);
        }

        $userQuery->chunk($chunkSize, function (Collection $users) use (
            $frequency, $since, $isDryRun, $sharedData,
            $queueName, $delaySecs, &$dispatched, &$skipped
        ) {
            foreach ($users as $user) {
                $theme   = $this->mailer->resolveTheme($user);
                $content = $this->query->buildForUser(
                    $user, $since, $frequency, $theme, $sharedData
                );

                if ($content->isEmpty()) {
                    $this->line("  [skip]     {$user->username} (#{$user->id}) — no content");
                    $skipped++;
                    continue;
                }

                if ($isDryRun) {
                    $this->line("  [dry-run]  {$user->username} (#{$user->id}) — " . $this->contentSummary($content));
                    $dispatched++;
                    continue;
                }

                $token = $this->tokenGenerator->getOrCreate($user);

                // Build the job with queue name, retry count and exponential backoff.
                $job = (new SendDigestJob($user, $content, $token))
                    ->onQueue($queueName)
                    ->tries($this->jobTries())
                    ->backoff([30, 60, 120]);

                // Delay makes jobs invisible to workers until available_at arrives.
                // This lets the queue fill up before workers start consuming.
                if ($delaySecs > 0) {
                    $job = $job->delay($delaySecs);
                }

                $this->queue->push($job);

                // Stamp last sent now so the user isn't double-dispatched if
                // the command re-runs within the same window.
                User::where('id', $user->id)->update([
                    'digest_last_sent_at' => Carbon::now()->toDateTimeString(),
                ]);

                $this->line("  [queued]   {$user->username} (#{$user->id})");
                $dispatched++;
            }
        });

        // Log the batch.
        if (!$isDryRun && $dispatched > 0) {
            $this->db->table('digest_send_log')->insert([
                'frequency'     => $frequency,
                'sent_count'    => $dispatched,
                'skipped_count' => $skipped,
                'sent_at'       => Carbon::now('UTC')->toDateTimeString(),
            ]);
        }

        return [$dispatched, $skipped];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function periodStart(string $frequency): Carbon
    {
        return match ($frequency) {
            'daily'   => Carbon::now('UTC')->subDay(),
            'weekly'  => Carbon::now('UTC')->subWeek(),
            'monthly' => Carbon::now('UTC')->subMonth(),
        };
    }

    private function lastSentCutoff(string $frequency): Carbon
    {
        return match ($frequency) {
            'daily'   => Carbon::now('UTC')->subHours(23),
            'weekly'  => Carbon::now('UTC')->subDays(6),
            'monthly' => Carbon::now('UTC')->subDays(28),
        };
    }

    private function jobTries(): int
    {
        return max(1, (int) $this->settings->get('resofire-digest-mail.queue_tries', 3));
    }

    /**
     * Check whether the shared data would cause every user to be skipped.
     * Mirrors the logic in DigestContent::isEmpty() for shared-only sections.
     * If this returns true, there is no point chunking through the user table.
     */
    private function sharedDataIsEmpty(array $shared): bool
    {
        // Core shared sections
        if (!empty($shared['newDiscussions']) && $shared['newDiscussions']->isNotEmpty()) return false;
        if (!empty($shared['hotDiscussions']) && $shared['hotDiscussions']->isNotEmpty()) return false;
        if (!empty($shared['newMembers'])     && $shared['newMembers']->isNotEmpty())     return false;

        // Force-send triggers
        if (!empty($shared['awards']['awards']))            return false;
        if (!empty($shared['leaderboard']['entries']))      return false;
        if (!empty($shared['pickem']['upcomingEvents']))    return false;
        if (!empty($shared['pickem']['recentResults']))     return false;

        // Note: unreadDiscussions is per-user, so we cannot check it here.
        // A user with unread content will still get sent even if we reach here,
        // but we cannot know that without checking each user individually.
        // The pre-flight is a best-effort early exit, not a guarantee.
        return true;
    }

    private function contentSummary(DigestContent $content): string
    {
        $parts = [];
        if ($content->newDiscussions->isNotEmpty())   $parts[] = $content->newDiscussions->count()   . ' new';
        if ($content->hotDiscussions->isNotEmpty())    $parts[] = $content->hotDiscussions->count()   . ' hot';
        if ($content->unreadDiscussions->isNotEmpty()) $parts[] = $content->unreadDiscussions->count(). ' unread';
        if ($content->newMembers->isNotEmpty())        $parts[] = $content->newMembers->count()       . ' members';
        if (!empty($content->awards['awards']))        $parts[] = count($content->awards['awards'])   . ' award(s)';
        return implode(', ', $parts) ?: 'extension sections only';
    }
}
