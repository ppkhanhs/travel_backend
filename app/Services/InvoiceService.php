<?php

namespace App\Services;

use App\Mail\InvoiceIssuedMail;
use App\Models\Booking;
use App\Models\Invoice;
use App\Notifications\InvoiceIssuedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

use App\Services\NotificationService;

class InvoiceService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function buildLineItems(Booking $booking): array
    {
        $items = [];
        $tourTitle = $booking->tourSchedule?->tour?->title ?? 'Tour';
        $promotions = $booking->promotions()
            ->withPivot(['discount_amount'])
            ->get();
        $discountTotal = $promotions->sum(function ($promotion) {
            return (float) $promotion->pivot->discount_amount;
        });
        $netTotal = max(0, (float) $booking->total_price - $discountTotal);

        return [[
            'description' => $tourTitle,
            'quantity' => 1,
            'unit_price' => $netTotal,
            'amount' => $netTotal,
        ]];
    }

    public function calculateSubtotal(array $lineItems): float
    {
        return round(collect($lineItems)->sum('amount'), 2);
    }

    public function generatePdf(Invoice $invoice): string
    {
        $booking = $invoice->booking()->with(['tourSchedule.tour', 'package'])->first();
        $partner = $invoice->partner()->first();

        $data = [
            'invoice' => $invoice,
            'booking' => $booking,
            'partner' => $partner,
        ];

        $pdf = Pdf::loadView('invoices.default', $data);

        $path = sprintf('invoices/%s/%s.pdf', $partner->id ?? 'general', $invoice->invoice_number);
        Storage::disk('public')->put($path, $pdf->output());

        return $path;
    }

    public function emailInvoice(Invoice $invoice): void
    {
        if (!$invoice->customer_email) {
            return;
        }

        Mail::to($invoice->customer_email)->send(new InvoiceIssuedMail($invoice));
        $invoice->emailed_at = now();
        $invoice->save();

        $this->notifyIssued($invoice);
    }

    public function notifyIssued(Invoice $invoice): void
    {
        $user = $invoice->booking?->user;
        $this->notifications->notify($user, new InvoiceIssuedNotification($invoice));
    }
}
