<?php

use Resofire\DigestMail\Api\Controller\DigestStatsController;
use Resofire\DigestMail\Api\Controller\SendTestDigestController;
use Resofire\DigestMail\Console\SendDigestCommand;
use Resofire\DigestMail\Console\EnqueueDigestCommand;
use Resofire\DigestMail\Controller\UnsubscribeController;
use Resofire\DigestMail\Listener\SaveDigestFrequency;
use Flarum\Api\Serializer\CurrentUserSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Extension\ExtensionManager;
use Flarum\User\Event\Saving;
use Flarum\User\User;

return [
    // -------------------------------------------------------------------------
    // NOTE: Migrations are auto-discovered from the /migrations directory by
    // Flarum's ExtensionManager when the extension is enabled. No registration
    // is needed or possible — there is no Extend\Migration extender in Flarum.
    // -------------------------------------------------------------------------
    // Blade view namespace
    //
    // Registers the /views directory under the 'resofire-digest-mail' namespace so that
    // views can be referenced as 'resofire-digest-mail::emails.digest' and
    // 'resofire-digest-mail::unsubscribe' anywhere in the extension.
    // -------------------------------------------------------------------------
    (new Extend\View)
        ->namespace('resofire-digest-mail', __DIR__ . '/views'),

    // -------------------------------------------------------------------------
    // Translations
    // -------------------------------------------------------------------------
    new Extend\Locales(__DIR__ . '/locale'),

    // -------------------------------------------------------------------------
    // Frontend assets
    //
    // Forum: adds the digest-frequency selector to the user settings page.
    // Admin: adds the digest settings panel under the extension page.
    // -------------------------------------------------------------------------
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    // -------------------------------------------------------------------------
    // Console
    //
    // .command() — registers `php flarum digest:send` for manual/test runs.
    //   Flags:
    //     --frequency=daily|weekly|monthly   override the time gate
    //     --dry-run                          print recipients, don't dispatch
    //     --user=ID                          restrict to one user (testing)
    //
    // .schedule() — registers the hourly scheduler entry via Flarum's own
    // scheduling system (flarum.console.scheduled), which is the correct API.
    // -------------------------------------------------------------------------
    (new Extend\Console)
        ->command(SendDigestCommand::class)
        ->command(EnqueueDigestCommand::class)
        ->schedule(SendDigestCommand::class, fn ($event) => $event->hourly()),

    // -------------------------------------------------------------------------
    // Forum routes
    //
    // GET /digest/unsubscribe?token=...
    //   Shows the preference picker. With &frequency=... also present, saves
    //   immediately (signed token authenticates — no POST/CSRF needed) and
    //   redirects to ?saved=1.
    // -------------------------------------------------------------------------
    (new Extend\Routes('forum'))
        ->get(
            '/digest/unsubscribe',
            'resofire.digest-mail.unsubscribe',
            UnsubscribeController::class
        ),

    // -------------------------------------------------------------------------
    // API route — test send
    //
    // POST /api/resofire/digest-mail/test-send
    //   Admin-only. Sends a live digest email to an arbitrary address.
    //   Does not update digest_last_sent_at or consume unsubscribe tokens.
    // -------------------------------------------------------------------------
    (new Extend\Routes('api'))
        ->post(
            '/resofire/digest-mail/test-send',
            'resofire.digest-mail.test-send',
            SendTestDigestController::class
        )
        ->get(
            '/resofire/digest-mail/stats',
            'resofire.digest-mail.stats',
            DigestStatsController::class
        ),

    // -------------------------------------------------------------------------
    // Expose digestFrequency on the current-user API payload
    //
    // The forum JS reads this attribute when the settings page mounts so it
    // can pre-select the correct option in the frequency dropdown.
    //
    // CurrentUserSerializer extends UserSerializer, so this attribute is only
    // present on the /api/users/me (or equivalent) response — not on every
    // user object returned in discussion lists etc.
    // -------------------------------------------------------------------------
    (new Extend\ApiSerializer(CurrentUserSerializer::class))
        ->attribute(
            'digestFrequency',
            fn ($serializer, User $user) => $user->digest_frequency
        ),

    // Expose optional-integration availability to the admin JS so toggles
    // can be grayed out when an integration extension is not installed.
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attribute(
            'digestExtensions',
            function ($serializer) {
                /** @var ExtensionManager $manager */
                $manager = resolve(ExtensionManager::class);
                return [
                    'leaderboard' => [
                        'enabled'         => $manager->isEnabled('huseyinfiliz-leaderboard'),
                        'title'           => 'Leaderboard',
                        'iconName'        => 'fas fa-trophy',
                        'iconColor'       => '#ffffff',
                        'iconBg'          => '#3498db',
                    ],
                    'badges' => [
                        'enabled'         => $manager->isEnabled('fof-badges'),
                        'title'           => 'Badges',
                        'iconName'        => 'fas fa-award',
                        'iconColor'       => '#ffffff',
                        'iconBg'          => '#8b5cf6',
                    ],
                    'pickem' => [
                        'enabled'         => $manager->isEnabled('huseyinfiliz-pickem'),
                        'title'           => "Pick'em",
                        'iconName'        => 'fas fa-football-ball',
                        'iconColor'       => '#ffffff',
                        'iconBg'          => '#16a34a',
                    ],
                    'nightmode' => [
                        'enabled' => $manager->isEnabled('fof-nightmode'),
                    ],
                    'gamepedia' => [
                        'enabled'  => $manager->isEnabled('huseyinfiliz-gamepedia'),
                        'title'    => 'Gamepedia',
                        'iconName' => 'fas fa-gamepad',
                        'iconColor'=> '#ffffff',
                        'iconBg'   => '#e85d04',
                    ],
                    'likes' => [
                        'enabled' => $manager->isEnabled('flarum-likes'),
                    ],
                    'reactions' => [
                        'enabled'  => $manager->isEnabled('fof-reactions'),
                        'title'    => 'Reactions',
                        'iconName' => 'fas fa-smile',
                        'iconColor'=> '#ffffff',
                        'iconBg'   => '#f59e0b',
                    ],
                    'awards' => [
                        'enabled'  => $manager->isEnabled('huseyinfiliz-awards'),
                        'title'    => 'Awards',
                        'iconName' => 'fas fa-star',
                        'iconColor'=> '#ffffff',
                        'iconBg'   => '#f59e0b',
                    ],
                ];
            }
        )
        ->attribute(
            'digestAllowedFrequencies',
            function ($serializer) {
                /** @var \Flarum\Settings\SettingsRepositoryInterface $settings */
                $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
                $raw = fn(string $key, string $fallback) => ($v = $settings->get($key)) === null || $v === '' ? $fallback : $v;
                return [
                    'daily'   => $raw('resofire-digest-mail.allow_daily',   '0') === '1',
                    'weekly'  => $raw('resofire-digest-mail.allow_weekly',  '1') === '1',
                    'monthly' => $raw('resofire-digest-mail.allow_monthly', '1') === '1',
                ];
            }
        ),

    // Settings defaults — returned by Flarum before the admin saves for the
    // first time, so the extension behaves sensibly out of the box.
    (new Extend\Settings())
        ->default('resofire-digest-mail.enable_badges',      '1')
        ->default('resofire-digest-mail.enable_leaderboard', '1')
        ->default('resofire-digest-mail.enable_gamepedia',   '1')
        ->default('resofire-digest-mail.enable_reactions',   '1')
        ->default('resofire-digest-mail.enable_awards',      '1')
        ->default('resofire-digest-mail.limit_favorites',    '6')
        ->default('resofire-digest-mail.queue_name',         'digest')
        ->default('resofire-digest-mail.queue_chunk_size',   '200')
        ->default('resofire-digest-mail.queue_delay',        '0')
        ->default('resofire-digest-mail.queue_tries',        '3')
        ->default('resofire-digest-mail.section_order',      '')
        ->default('resofire-digest-mail.allow_daily',        '0')
        ->default('resofire-digest-mail.allow_weekly',       '1')
        ->default('resofire-digest-mail.allow_monthly',      '1')
        ->default('resofire-digest-mail.timezone',           'UTC')
        ->default('resofire-digest-mail.send_hour',          '8')
        ->default('resofire-digest-mail.weekly_day',         '1')
        ->default('resofire-digest-mail.monthly_day',        '1'),

    // -------------------------------------------------------------------------
    // Persist digestFrequency via the existing PATCH /api/users/{id} endpoint
    //
    // The User\Event\Saving event is fired by EditUserHandler after it has
    // applied all the standard attribute changes but before it calls
    // $user->save(). Our listener reads 'digestFrequency' from $event->data
    // (the raw JSON:API attributes payload), validates it, and writes it to
    // $user->digest_frequency. Because $user is passed by reference through
    // the event, the value is automatically persisted by the handler's
    // subsequent $user->save() call — no extra save() needed here.
    // -------------------------------------------------------------------------
    (new Extend\Event)
        ->listen(Saving::class, SaveDigestFrequency::class),
];
