# Resofire Digest Mail

A [Flarum](https://flarum.org) extension that sends beautifully formatted periodic digest emails to forum members, summarising forum activity, leaderboard standings, badge achievements, and more — with full light and dark mode support.

---

## Features

### Email Content
- **New Discussions** — discussions started during the digest period
- **Active Discussions** — hot discussions ranked by a configurable reply/recency score
- **Unread Discussions** — personalised per recipient based on their read state
- **New Members** — members who joined during the digest period
- **Featured Discussion** — admin-pinned discussion displayed prominently at the top of every digest with a gold highlight card, author avatar, and a call-to-action button
- **Leaderboard** *(optional, requires `huseyinfiliz/leaderboard`)* — top point earners for the period, podium display for the top three, biggest mover spotlight, and a ranked table
- **Badges** *(optional, requires `fof/badges`)* — most recent badge earners, most earned badge of the period, and rarest badge awarded

### Scheduling
- **Flexible user frequency** — each user chooses daily, weekly, monthly, or opts out entirely from their account settings page
- **Admin-controlled send time** — configurable send hour displayed in the admin's local browser timezone, automatically converted to UTC for scheduling
- **Weekly send day** — configurable day of the week for weekly digests
- **Monthly send day** — configurable day of the month for monthly digests (capped at 28 to avoid month-end issues)
- **Empty content suppression** — digests are not sent if there is no activity to report during the period
- **Duplicate send protection** — tracks `digest_last_sent_at` per user with a tolerance window to prevent double sends on scheduler drift

### Admin Tools
- **Test send panel** — send a live digest to any email address directly from the admin panel, with light/dark theme toggle
- **Dry run mode** — console command flag that lists eligible recipients and content without dispatching any jobs
- **Per-section limits** — configurable maximum item counts for every section
- **Allowed frequency controls** — admins can restrict which frequencies (daily/weekly/monthly) are available to users
- **Integration toggles** — leaderboard and badges sections can each be individually enabled or disabled

### Design
- **Light and dark mode** — full support for both themes, respecting each user's Night Mode preference (requires `fof/nightmode`) with graceful fallback
- **Responsive email layout** — table-based HTML email compatible with all major email clients
- **Forum branding** — uses the forum's configured primary and secondary colors throughout

### Unsubscribe
- **One-click unsubscribe** — every email includes a secure token-based unsubscribe link
- **Preference page** — unsubscribe redirects to a preference page where users can choose a different frequency rather than only opting out entirely

---

## Requirements

### Core
- PHP >= 8.1
- Flarum >= 1.8
- A working outbound mail configuration in your Flarum admin panel
- A server cron job running every minute (standard Laravel scheduler requirement)

### Optional Integrations
| Extension | Enables |
|---|---|
| `huseyinfiliz/leaderboard` | Leaderboard section in digest |
| `fof/badges` | Badges section in digest |
| `fof/nightmode` | Per-user dark mode email theme |
| `blomstra/database-queue` | Asynchronous job processing (strongly recommended) |

---

## Installation

```bash
composer require resofire/digest-mail
```

Then enable the extension in your Flarum admin panel and run migrations:

```bash
php flarum migrate
```

---

## Scheduler Setup

Add the Laravel scheduler to your server's crontab to run every minute:

```
* * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1
```

The extension's `digest:send` command is automatically registered with the scheduler. It runs hourly and applies its own time-gate — checking the current UTC hour against your configured send hour, and (for weekly/monthly) the current day — before doing any work.

---

## Queue Setup

For best results, install the [`blomstra/database-queue`](https://github.com/blomstra/flarum-ext-database-queue) extension. This is the recommended way to switch Flarum from its default `SyncQueue` to an asynchronous database-backed queue, and is maintained alongside Flarum core.

```bash
composer require blomstra/database-queue
```

Then enable it in your Flarum admin panel. The extension handles queue table creation and worker registration automatically.

**Add a queue worker cron entry** so jobs are processed in the background:

```
* * * * * cd /path/to/flarum && php flarum queue:work --stop-when-empty >> /dev/null 2>&1
```

The `--stop-when-empty` flag processes all waiting jobs then exits cleanly, letting cron restart it each minute. This is the safest approach for shared hosting without Supervisor.

> Without a database queue driver, digests will still send but will do so synchronously and blocking during the command run. For small forums this is acceptable; for larger ones it risks timeouts.

---

## Admin Settings

### Schedule
| Setting | Description |
|---|---|
| **Send hour** | Time of day digests are sent, displayed in your browser's local timezone |
| **Weekly digest — send day** | Day of the week for weekly digests |
| **Monthly digest — send day** | Day of the month for monthly digests (capped at 28) |

### Content
| Setting | Description |
|---|---|
| **Featured discussion ID** | Discussion ID to pin as a featured discussion at the top of every digest. Leave blank to disable. |
| **New Discussions — max items** | Maximum new discussions shown per digest |
| **Active Discussions — max items** | Maximum hot/active discussions shown |
| **Unread Discussions — max items** | Maximum personalised unread discussions per recipient |
| **New Members — max items** | Maximum new member profiles shown |
| **Hot score — reply weight** | How much each reply contributes to a discussion's hotness score |
| **Hot score — recency weight** | How much recency boosts hotness (0 = replies-only ranking) |

### Integrations
| Setting | Description |
|---|---|
| **Enable Leaderboard** | Show/hide the leaderboard section (requires `huseyinfiliz/leaderboard`) |
| **Leaderboard — max entries** | Maximum number of leaderboard entries shown |
| **Enable Badges** | Show/hide the badges section (requires `fof/badges`) |
| **Badges — max recent earners** | Maximum number of recent badge earners listed |

### Frequencies
| Setting | Description |
|---|---|
| **Allow daily digests** | Whether users can select the daily frequency |
| **Allow weekly digests** | Whether users can select the weekly frequency |
| **Allow monthly digests** | Whether users can select the monthly frequency |

---

## Console Command

Digests can be triggered manually via the console:

```bash
# Run all due frequencies (respects the time gate)
php flarum digest:send

# Force a specific frequency, bypassing the time gate
php flarum digest:send --frequency=weekly

# Dry run — lists eligible recipients and content without dispatching jobs
php flarum digest:send --frequency=daily --dry-run

# Restrict to a single user (useful for testing)
php flarum digest:send --frequency=daily --user=1
```

---

## User Settings

Each user manages their digest preference from their account settings page under Notifications:

| Option | Description |
|---|---|
| **Off** | No digest emails sent |
| **Daily** | Digest sent every day at the configured hour *(if enabled by admin)* |
| **Weekly** | Digest sent on the configured day each week |
| **Monthly** | Digest sent on the configured day each month |

---

## License

MIT

