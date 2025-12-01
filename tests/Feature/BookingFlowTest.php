<?php

namespace Tests\Feature;

use App\Models\Tour;
use App\Models\TourPackage;
use App\Models\TourSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_booking_and_payment_pending(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $tour = Tour::factory()->create(['status' => 'approved', 'base_price' => 1000]);
        $schedule = TourSchedule::factory()->create([
            'tour_id' => $tour->id,
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(12),
            'seats_total' => 20,
            'seats_available' => 20,
        ]);
        $package = TourPackage::factory()->create([
            'tour_id' => $tour->id,
            'adult_price' => 1000,
            'child_price' => 500,
        ]);

        $payload = [
            'tour_id' => $tour->id,
            'schedule_id' => $schedule->id,
            'package_id' => $package->id,
            'adults' => 2,
            'children' => 1,
            'payment_method' => 'sepay',
            'passengers' => [
                ['type' => 'adult', 'full_name' => 'A'],
                ['type' => 'adult', 'full_name' => 'B'],
                ['type' => 'child', 'full_name' => 'C', 'date_of_birth' => '2018-01-01'],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/bookings', $payload);

        $response->assertCreated();
        $response->assertJsonPath('booking.payment_status', 'pending');

        $this->assertDatabaseHas('bookings', [
            'user_id' => $user->id,
            'tour_schedule_id' => $schedule->id,
            'total_adults' => 2,
            'total_children' => 1,
        ]);

        $bookingId = $response->json('booking.id');

        $this->assertDatabaseHas('payments', [
            'booking_id' => $bookingId,
            'status' => 'pending',
        ]);
    }
}
