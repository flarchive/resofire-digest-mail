@extends('flarum.forum::layouts.basic')
@php
    $primaryColor = $settings->get('theme_primary_color', '#4f46e5');
    $forumTitle   = $settings->get('forum_title', 'Forum');

    $weekDayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $weeklyDayLabel = $weekDayNames[(int) $settings->get('resofire-digest-mail.weekly_day', 1)] ?? 'Monday';

    $monthlyDayInt = (int) $settings->get('resofire-digest-mail.monthly_day', 1);
    $suffix = match (true) {
        ($monthlyDayInt % 100 >= 11 && $monthlyDayInt % 100 <= 13) => 'th',
        ($monthlyDayInt % 10 === 1) => 'st',
        ($monthlyDayInt % 10 === 2) => 'nd',
        ($monthlyDayInt % 10 === 3) => 'rd',
        default => 'th',
    };
    $monthlyDayLabel = $monthlyDayInt . $suffix;

    // Only show frequencies the admin has enabled
    $allowDaily   = $settings->get('resofire-digest-mail.allow_daily',   '0') === '1';
    $allowWeekly  = $settings->get('resofire-digest-mail.allow_weekly',  '1') === '1';
    $allowMonthly = $settings->get('resofire-digest-mail.allow_monthly', '1') === '1';

    $options = [];
    if ($allowDaily)   $options[] = ['value' => 'daily',   'emoji' => '☀️',  'title' => 'Daily',   'desc' => 'One email per day with the latest activity.'];
    if ($allowWeekly)  $options[] = ['value' => 'weekly',  'emoji' => '📅',  'title' => 'Weekly',  'desc' => 'A weekly roundup every ' . $weeklyDayLabel . '.'];
    if ($allowMonthly) $options[] = ['value' => 'monthly', 'emoji' => '📆',  'title' => 'Monthly', 'desc' => 'A monthly summary on the ' . $monthlyDayLabel . ' of each month.'];
    $options[] =                    ['value' => 'off',     'emoji' => '🔕',  'title' => 'Off',     'desc' => "Don't send me any digest emails.", 'off' => true];

    // Build GET URLs for each option — avoids CSRF entirely.
    // $postUrl already contains the full base URL with token and frequency param
@endphp

@section('title', 'Email Digest Preferences')

@section('content')

<style>
    .digest-card {
        background: #fff;
        border-radius: 10px;
        padding: 36px 32px;
        box-shadow: 0 2px 12px rgba(0,0,0,.08);
        text-align: left;
        max-width: 420px;
        margin: 0 auto;
    }
    .digest-card h2 {
        font-size: 20px;
        font-weight: 700;
        color: #111827;
        margin: 0 0 8px;
        letter-spacing: -0.3px;
    }
    .digest-card .subtitle {
        font-size: 14px;
        color: #6b7280;
        margin: 0 0 28px;
    }
    .freq-option {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: border-color .15s;
        text-decoration: none;
        color: inherit;
    }
    .freq-option:hover {
        border-color: {{ $primaryColor }};
        text-decoration: none;
        color: inherit;
    }
    .freq-option.is-selected {
        border-color: {{ $primaryColor }};
    }
    .freq-label { flex: 1; }
    .freq-title {
        font-size: 15px;
        font-weight: 600;
        color: #111827;
        display: block;
        margin-bottom: 2px;
    }
    .freq-option.is-selected .freq-title {
        color: {{ $primaryColor }};
    }
    .freq-desc {
        font-size: 13px;
        color: #6b7280;
    }
    .click-hint {
        font-size: 13px;
        color: #9ca3af;
        margin: -16px 0 20px;
        font-style: italic;
    }
    .freq-option.is-off {
        border-style: dashed;
    }
    .check-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
        margin-top: 1px;
        color: {{ $primaryColor }};
        visibility: hidden;
    }
    .freq-option.is-selected .check-icon {
        visibility: visible;
    }
</style>

<div class="digest-card">

    <h2>📧 Email Digest Preferences</h2>
    <p class="subtitle">
        Hi, <strong>{{ $user->display_name }}</strong>. Choose how often you'd
        like to receive a digest of what's happening in
        <strong>{{ $forumTitle }}</strong>.
    </p>
    <p class="click-hint">👆 Click an option to save your preference instantly.</p>

    @foreach ($options as $option)
    <a href="{{ $postUrl }}{{ $option['value'] }}"
       class="freq-option{{ $currentFrequency === $option['value'] || ($option['value'] === 'off' && $currentFrequency === null) ? ' is-selected' : '' }}{{ !empty($option['off']) ? ' is-off' : '' }}">
        <span class="freq-label">
            <span class="freq-title">{{ $option['emoji'] }} {{ $option['title'] }}</span>
            <span class="freq-desc">{{ $option['desc'] }}</span>
        </span>
        <svg class="check-icon" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L8 12.586 4.707 9.293a1 1 0 00-1.414 1.414l4 4a1 1 0 001.414 0l8-8a1 1 0 000-1.414z" clip-rule="evenodd"/>
        </svg>
    </a>
    @endforeach

</div>

@endsection
