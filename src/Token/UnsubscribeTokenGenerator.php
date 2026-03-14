<?php

namespace Resofire\DigestMail\Token;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Generates and persists one-per-user unsubscribe tokens.
 *
 * Strategy: UPSERT by user_id (INSERT … ON DUPLICATE KEY UPDATE on MySQL,
 * emulated via updateOrInsert on the query builder for cross-driver compat).
 *
 * Called by DigestMailer once per user, just before the digest job is
 * dispatched. This ensures the token embedded in the email is always fresh
 * and the expiry clock is reset on every send.
 *
 * Revocation: deleting the row in digest_unsubscribe_tokens invalidates the
 * link immediately. UnsubscribeController deletes the token on a confirmed
 * preference change (single-use after submission), so a second click on the
 * same emailed link will show the "invalid token" error page.
 */
class UnsubscribeTokenGenerator
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * Upsert a token for $user and return the raw 64-char hex string.
     *
     * If a row already exists for this user_id it is overwritten with a new
     * token and a refreshed created_at. This means every digest send gives
     * the user a fresh link, and old links from previous emails stop working
     * once the next digest is sent — an intentional security property.
     */
    public function getOrCreate(User $user): string
    {
        $token     = bin2hex(random_bytes(32)); // 64 hex chars
        $now       = Carbon::now()->toDateTimeString();

        // updateOrInsert matches on the first array (the WHERE clause) and
        // applies the second array as the SET / INSERT values. The unique
        // index on user_id means at most one row is ever matched.
        $this->db->table('digest_unsubscribe_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            ['token' => $token, 'created_at' => $now],
        );

        return $token;
    }
}
