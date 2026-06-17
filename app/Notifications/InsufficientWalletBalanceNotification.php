<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class InsufficientWalletBalanceNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order, public float $amount)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $amount = number_format($this->amount, 2);
        $orderNumber = $this->order->order_number ?? $this->order->id;
        $paymentType = ucfirst($this->order->payment_type ?? 'installment');

        $nextDueLabel = 'as soon as you top up';
        if ($this->order->next_due_date) {
            $nextDueLabel = $this->order->next_due_date instanceof \Carbon\CarbonInterface
                ? $this->order->next_due_date->format('M j, Y')
                : Carbon::parse($this->order->next_due_date)->format('M j, Y');
        }

        $name = $notifiable->firstname
            ?? $notifiable->fullname
            ?? $notifiable->email
            ?? 'there';

        return (new MailMessage)
            ->subject('Top up your wallet to continue your food plan')
            ->greeting("Hi {$name}")
            ->line("We couldn't process your {$paymentType} installment of NGN {$amount} for order {$orderNumber} because your main wallet balance is low.")
            ->line('Please top up your main wallet to keep your food plan on track.')
            ->line("Next due date: {$nextDueLabel}")
            ->salutation('Thanks, ChopEasy');
    }
}
