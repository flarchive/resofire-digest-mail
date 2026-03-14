<?php

namespace Resofire\DigestMail\Job;

use Resofire\DigestMail\DigestContent;
use Resofire\DigestMail\DigestMailer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * Queueable job that sends a single digest email to a single user.
 *
 * Scaling properties:
 *   $tries   — how many times to attempt before moving to failed_jobs
 *   $backoff — seconds to wait between attempts (exponential: 30, 60, 120)
 *   $queue   — the named queue this job runs on
 *
 * These are set by SendDigestCommand/EnqueueDigestCommand via ->tries(),
 * ->backoff(), and ->onQueue() before pushing. The property defaults here
 * act as a safety net if the job is ever dispatched without those calls.
 */
class SendDigestJob extends AbstractJob
{
    /** Maximum attempts before the job is considered permanently failed. */
    public int $tries = 3;

    /** Exponential backoff in seconds between retries. */
    public array $backoff = [30, 60, 120];

    /**
     * Set the number of times this job may be attempted.
     * Returns $this for fluent chaining.
     */
    public function tries(int $tries): static
    {
        $this->tries = $tries;
        return $this;
    }

    /**
     * Set the backoff strategy (array of seconds per attempt).
     * Returns $this for fluent chaining.
     */
    public function backoff(array $backoff): static
    {
        $this->backoff = $backoff;
        return $this;
    }

    public function __construct(
        private User          $user,
        private DigestContent $content,
        private string        $unsubscribeToken,
    ) {
        parent::__construct();
    }

    public function handle(DigestMailer $mailer): void
    {
        $mailer->sendToUser($this->user, $this->content, $this->unsubscribeToken);
    }
}
