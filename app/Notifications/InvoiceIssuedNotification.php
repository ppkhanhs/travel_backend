<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvoiceIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(private Invoice $invoice)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'invoice',
            'title' => 'Hóa đơn đã được phát hành',
            'message' => sprintf(
                'Hóa đơn %s cho booking %s đã sẵn sàng.',
                $this->invoice->invoice_number,
                $this->invoice->booking_id
            ),
            'invoice_id' => $this->invoice->id,
            'booking_id' => $this->invoice->booking_id,
            'delivery_method' => $this->invoice->delivery_method,
        ];
    }
}

