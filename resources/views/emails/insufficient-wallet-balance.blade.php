@extends('emails.layout')

@section('title', 'Wallet top-up needed')
@section('preheader', 'Your scheduled ChopEasy plan payment could not be completed.')

@section('content')
    <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#222;">Top up your wallet</h1>
    <p style="margin:0 0 14px;">Hi {{ $name }},</p>
    <p style="margin:0 0 20px;">We could not process your <strong>{{ $paymentType }}</strong> plan payment because your Food-Bank Wallet did not have enough funds.</p>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;background:#fff9e5;border:1px solid #f4df9a;border-radius:10px;">
        <tr><td style="padding:16px 18px;font-size:14px;line-height:1.8;color:#374151;">
            <strong>Payment amount:</strong> NGN {{ $amount }}<br>
            <strong>Order:</strong> {{ $orderNumber }}<br>
            <strong>Next scheduled attempt:</strong> {{ $nextDueLabel }}
        </td></tr>
    </table>
    <p style="margin:0 0 22px;">Add funds to your wallet to keep your plan on track. We will retry according to your plan schedule.</p>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;">
        <tr><td align="center" bgcolor="#ffc107" style="border-radius:8px;"><a href="{{ $walletUrl }}" style="display:inline-block;padding:13px 24px;color:#222;text-decoration:none;font-weight:700;">Open ChopEasy</a></td></tr>
    </table>
    <p style="margin:0;font-size:13px;color:#6b7280;">If you have already topped up, no action is needed.</p>
@endsection