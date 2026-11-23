@extends('emails.layout')

@php
    $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
    if ($name === '') $name = $user->fullname ?? 'there';
@endphp

@section('title', 'Verify your ChopEasy email')
@section('preheader', 'Confirm your email address to finish setting up ChopEasy.')

@section('content')
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#222;">Welcome to ChopEasy</h1>
    <p style="margin:0 0 14px;">Hi {{ $name }},</p>
    <p style="margin:0 0 22px;">Thanks for joining ChopEasy. Verify your email address to finish setting up your account.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;">
        <tr><td align="center" bgcolor="#ffc107" style="border-radius:8px;"><a href="{{ $verificationUrl }}" style="display:inline-block;padding:13px 24px;color:#222;text-decoration:none;font-weight:700;">Verify email address</a></td></tr>
    </table>
    <p style="margin:0;font-size:13px;color:#6b7280;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $verificationUrl }}" style="color:#8a6500;word-break:break-all;">{{ $verificationUrl }}</a></p>
    <p style="margin:18px 0 0;font-size:13px;color:#6b7280;">If you did not create a ChopEasy account, you can ignore this email.</p>
@endsection