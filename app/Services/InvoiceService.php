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
        $package = $booking->package;

        $adultPrice = $package ? (float) $package->adult_price : (float) $booking->tourSchedule?->tour?->base_price;
        $childPrice = $package ? (float) $package->child_price : round($adultPrice * 0.75, 2);

        if ($booking->total_adults > 0) {
            $items[] = [
                'description' => "{$tourTitle} - Adult",
                'quantity' => $booking->total_adults,
                'unit_price' => $adultPrice,
                'amount' => round($booking->total_adults * $adultPrice, 2),
            ];
        }

        if ($booking->total_children > 0) {
            $items[] = [
                'description' => "{$tourTitle} - Child",
                'quantity' => $booking->total_children,
                'unit_price' => $childPrice,
                'amount' => round($booking->total_children * $childPrice, 2),
            ];
        }

        if (empty($items)) {
            $items[] = [
                'description' => $tourTitle,
                'quantity' => 1,
                'unit_price' => (float) $booking->total_price,
                'amount' => (float) $booking->total_price,
            ];
        }

        return $items;
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
