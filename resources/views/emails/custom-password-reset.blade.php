@extends('emails.layout')

@php
    $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
    if ($name === '') $name = $user->fullname ?? 'there';
@endphp

@section('title', 'Reset your ChopEasy password')
@section('preheader', 'Use this secure link to reset your ChopEasy password.')

@section('content')
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#222;">Password reset request</h1>
    <p style="margin:0 0 14px;">Hi {{ $name }},</p>
    <p style="margin:0 0 22px;">We received a request to reset the password for your ChopEasy account. Use the button below to choose a new password.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;">
        <tr><td align="center" bgcolor="#ffc107" style="border-radius:8px;"><a href="{{ $resetUrl }}" style="display:inline-block;padding:13px 24px;color:#222;text-decoration:none;font-weight:700;">Reset password</a></td></tr>
    </table>
    <p style="margin:0;font-size:13px;color:#6b7280;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $resetUrl }}" style="color:#8a6500;word-break:break-all;">{{ $resetUrl }}</a></p>
    <p style="margin:18px 0 0;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;">If you did not request a password reset, you can ignore this email. Your password will not change.</p>
@endsection