@extends('emails.layout')

@php
    $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
    if ($name === '') $name = $user->fullname ?? 'there';
    $isPasswordReset = ($purpose ?? 'email_verification') === 'password_reset';
@endphp

@section('title', $isPasswordReset ? 'Reset your ChopEasy password' : 'Verify your ChopEasy email')
@section('preheader', 'Your ChopEasy one-time code expires in 10 minutes.')

@section('content')
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#222;">
        {{ $isPasswordReset ? 'Reset your password' : 'Verify your email' }}
    </h1>
    <p style="margin:0 0 14px;">Hi {{ $name }},</p>
    <p style="margin:0 0 22px;">
        {{ $isPasswordReset
            ? 'Use this one-time code to reset your ChopEasy password.'
            : 'Use this one-time code to verify your ChopEasy email address.' }}
        It expires in <strong>10 minutes</strong>.
    </p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 22px;">
        <tr><td align="center" style="padding:15px 28px;background:#fff7d6;border:1px solid #ffe08a;border-radius:10px;font-size:32px;font-weight:800;letter-spacing:8px;color:#222;">{{ $otp }}</td></tr>
    </table>
    <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">Enter the code in the ChopEasy app to continue.</p>
    <p style="margin:18px 0 0;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;">
        If you did not request this, you can ignore this email. Never share your code with anyone.
    </p>
@endsection