@extends('emails.layout')

@section('title', 'Order invoice ' . $order->order_number)
@section('preheader', 'Your ChopEasy order invoice is ready.')

@section('content')
    @php
        $address = $order->shipping_address_snapshot ?? [];
        $customerName = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''));
    @endphp
    <h1 style="margin:0 0 6px;font-size:24px;line-height:1.3;color:#222;">Order invoice</h1>
    <p style="margin:0 0 22px;color:#6b7280;font-size:14px;">Order #{{ $order->order_number }}  -  {{ optional($order->created_at)->format('F j, Y') }}</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:24px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
        <tr><td style="padding:15px 17px;font-size:13px;line-height:1.7;color:#4b5563;">
            <strong style="color:#222;">Billed to</strong><br>
            {{ $customerName ?: ($order->user->fullname ?? 'Customer') }}<br>
            {{ $address['address_line_1'] ?? ($order->user->address ?? '') }}<br>
            {{ $address['city'] ?? '' }}{{ !empty($address['state']) ? ', ' . $address['state'] : '' }}<br>
            {{ $order->user->email ?? '' }}
        </td></tr>
    </table>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin-bottom:18px;">
        <thead><tr>
            <th align="left" style="padding:11px 8px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;text-transform:uppercase;">Item</th>
            <th align="center" style="padding:11px 8px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;text-transform:uppercase;">Qty</th>
            <th align="right" style="padding:11px 8px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;text-transform:uppercase;">Amount</th>
        </tr></thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td style="padding:12px 8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#374151;">{{ $item->product_snapshot['name'] ?? 'Product' }}</td>
                    <td align="center" style="padding:12px 8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#374151;">{{ $item->quantity }}</td>
                    <td align="right" style="padding:12px 8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#374151;">NGN {{ number_format($item->quantity * $item->price_at_order, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr><td align="right" style="padding:12px 8px;font-size:14px;color:#6b7280;">Total paid</td><td align="right" style="padding:12px 8px;font-size:20px;font-weight:800;color:#222;">NGN {{ number_format((float) $order->total_amount, 2) }}</td></tr>
    </table>
    <p style="margin:22px 0 0;padding-top:18px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Thank you for choosing ChopEasy.</p>
@endsection