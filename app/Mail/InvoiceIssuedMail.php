<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build(): self
    {
        $booking = $this->invoice->booking()->with(['tourSchedule.tour'])->first();
        $partner = $this->invoice->partner()->first();

        $email = $this->subject('Invoice ' . $this->invoice->invoice_number)
            ->view('emails.invoices.issued', [
                'invoice' => $this->invoice,
                'booking' => $booking,
                'partner' => $partner,
            ]);

        if ($this->invoice->file_path) {
            $file = storage_path('app/public/' . $this->invoice->file_path);
            if (file_exists($file)) {
                $email->attach($file, [
                    'as' => $this->invoice->invoice_number . '.pdf',
                    'mime' => 'application/pdf',
                ]);
            }
        }

        return $email;
    }
}

