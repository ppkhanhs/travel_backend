<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    public function request(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'customer_tax_code' => 'nullable|string|max:100',
            'customer_address' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'delivery_method' => 'nullable|in:download,email',
        ]);

        $booking = Booking::with(['tourSchedule.tour.partner', 'payments', 'invoice'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($bookingId);

        if ($booking->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'booking' => ['Invoice can only be issued after payment is completed.'],
            ]);
        }

        if (!in_array($booking->status, ['completed'], true)) {
            throw ValidationException::withMessages([
                'booking' => ['Invoice can only be requested after the tour is completed.'],
            ]);
        }

        if ($booking->invoice) {
            throw ValidationException::withMessages([
                'booking' => ['Invoice has already been issued for this booking.'],
            ]);
        }

        $partner = optional(optional($booking->tourSchedule)->tour)->partner;
        if (!$partner || !$partner->invoice_company_name || !$partner->invoice_tax_code) {
            throw ValidationException::withMessages([
                'partner' => ['Partner has not provided invoice information. Please contact support.'],
            ]);
        }

        $lineItems = $this->invoiceService->buildLineItems($booking);
        $subtotal = $this->invoiceService->calculateSubtotal($lineItems);
        $vatRate = $partner->invoice_vat_rate ?? 10;
        $taxAmount = round($subtotal * ($vatRate / 100), 2);
        $total = round($subtotal + $taxAmount, 2);

        $deliveryMethod = $data['delivery_method'] ?? 'download';
        if ($deliveryMethod === 'email' && empty($data['customer_email']) && empty($booking->contact_email)) {
            throw ValidationException::withMessages([
                'customer_email' => ['Email is required to send invoice via email.'],
            ]);
        }

        $invoice = Invoice::create([
            'booking_id' => $booking->id,
            'partner_id' => $partner->id,
            'invoice_number' => $this->generateInvoiceNumber($partner->id),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'vat_rate' => $vatRate,
            'customer_name' => $data['customer_name'] ?? $booking->contact_name,
            'customer_tax_code' => $data['customer_tax_code'] ?? null,
            'customer_address' => $data['customer_address'] ?? ($booking->contact_phone ? 'Phone: ' . $booking->contact_phone : $booking->contact_email),
            'customer_email' => $data['customer_email'] ?? $booking->contact_email,
             'delivery_method' => $deliveryMethod,
            'file_path' => '',
            'line_items' => $lineItems,
        ]);

        $invoice->load(['booking.tourSchedule.tour', 'partner']);
        $filePath = $this->invoiceService->generatePdf($invoice);
        $invoice->update(['file_path' => $filePath]);
        $invoice->refresh();

        if ($deliveryMethod === 'email') {
            $this->invoiceService->emailInvoice($invoice);
        } else {
            $this->invoiceService->notifyIssued($invoice);
        }

        return response()->json([
            'message' => $deliveryMethod === 'email'
                ? 'Invoice generated and sent via email.'
                : 'Invoice generated successfully.',
            'invoice' => $invoice,
        ], 201);
    }

    public function show(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::with(['invoice'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($bookingId);

        if (!$booking->invoice) {
            return response()->json(['message' => 'Invoice has not been issued for this booking.'], 404);
        }

        return response()->json([
            'invoice' => $booking->invoice,
        ]);
    }

    public function download(Request $request, string $bookingId)
    {
        $booking = Booking::with(['invoice'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($bookingId);

        $invoice = $booking->invoice;
        if (!$invoice || !$invoice->file_path) {
            return response()->json(['message' => 'Invoice file not available.'], 404);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($invoice->file_path)) {
            return response()->json(['message' => 'Invoice file missing.'], 404);
        }

        $path = storage_path('app/public/' . ltrim($invoice->file_path, '/'));

        return response()->download($path, $invoice->invoice_number . '.pdf');
    }

    private function generateInvoiceNumber(string $partnerId): string
    {
        $prefix = now()->format('Ymd');
        $count = Invoice::where('partner_id', $partnerId)
            ->whereDate('issued_at', now()->toDateString())
            ->count();

        return sprintf('INV-%s-%04d', $prefix, $count + 1);
    }
}
