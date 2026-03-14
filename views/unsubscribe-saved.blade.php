@extends('flarum.forum::layouts.basic')
@php
    $forumTitle = $settings->get('forum_title', 'Forum');
    // $forumUrl is passed from UnsubscribeController
@endphp

@section('title', 'Preferences Saved')

@section('content')

<style>
    .digest-card {
        background: #fff;
        border-radius: 10px;
        padding: 36px 32px;
        box-shadow: 0 2px 12px rgba(0,0,0,.08);
        max-width: 420px;
        margin: 0 auto;
        text-align: center;
    }
    .digest-card .icon { font-size: 40px; margin-bottom: 16px; }
    .digest-card h2 { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 10px; }
    .digest-card p { font-size: 14px; color: #6b7280; margin: 0 0 20px; line-height: 1.6; }
</style>

<div class="digest-card">
    <div class="icon">✅</div>
    <h2>Preferences saved</h2>
    <p>Your digest email preferences have been updated successfully.</p>
    <p>
        <a href="{{ $forumUrl }}">Return to {{ $forumTitle }}</a>
    </p>
</div>

@endsection
