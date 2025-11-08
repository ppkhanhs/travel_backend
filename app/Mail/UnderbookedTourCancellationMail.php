<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class UnderbookedTourCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;

    public Collection $alternativeSchedules;

    public Collection $vouchers;

    public function __construct(Booking $booking, Collection $alternativeSchedules, Collection $vouchers)
    {
        $this->booking = $booking;
        $this->alternativeSchedules = $alternativeSchedules;
        $this->vouchers = $vouchers;
    }

    public function build(): self
    {
        $tour = optional($this->booking->tourSchedule)->tour;

        return $this->subject('Thông báo huỷ tour do chưa đủ số lượng khách')
            ->view('emails.tours.underbooked_cancellation', [
                'booking' => $this->booking,
                'tour' => $tour,
                'schedule' => $this->booking->tourSchedule,
                'alternatives' => $this->alternativeSchedules,
                'vouchers' => $this->vouchers,
            ]);
    }
}
