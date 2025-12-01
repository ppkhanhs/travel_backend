<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Tour;
use App\Models\TourPackage;
use App\Models\TourSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class E2EHappyPathTest extends TestCase
{
    use DatabaseTransactions;

    private bool $e2eEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e2eEnabled = (bool) env('RUN_E2E_TESTS', false);

        if (!$this->e2eEnabled) {
            $this->markTestSkipped('Set RUN_E2E_TESTS=true to run E2E flow tests.');
        }
    }

    public function test_full_booking_payment_notification_flow(): void
    {
        [$customer, $tour, $schedule, $package] = $this->seedTourData();

        $payload = [
            'tour_id' => $tour->id,
            'schedule_id' => $schedule->id,
            'package_id' => $package->id,
            'adults' => 1,
            'children' => 0,
            'contact_name' => 'Test Customer',
            'contact_email' => 'customer@example.com',
            'contact_phone' => '0900000000',
            'payment_method' => 'offline',
            'passengers' => [
                [
                    'type' => 'adult',
                    'full_name' => 'Test Customer',
                    'gender' => 'male',
                ],
            ],
        ];

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/bookings', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'booking' => ['id', 'status', 'total_price']]);

        $bookingId = $response->json('booking.id');

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/bookings/{$bookingId}/payment-status")
            ->assertStatus(200)
            ->assertJsonStructure(['status']);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/notifications')
            ->assertStatus(200);
    }

    public function test_chatbot_and_recommendations_endpoints_respond(): void
    {
        [$customer] = $this->seedTourData();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/recommendations')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/chatbot', [
                'message' => 'Xin chào, kiểm tra trạng thái đơn hàng?',
                'language' => 'vi',
                'history' => [],
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['reply']);
    }

    /**
     * Seed minimal partner/tour/schedule/package fixtures for end-to-end flows.
     *
     * @return array{User, Tour, TourSchedule, TourPackage}
     */
    private function seedTourData(): array
    {
        $customer = User::create([
            'name' => 'E2E Customer',
            'email' => 'e2e-customer-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'role' => 'customer',
        ]);

        $partnerUser = User::create([
            'name' => 'Partner Owner',
            'email' => 'partner-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'role' => 'partner',
        ]);

        $partner = Partner::create([
            'user_id' => $partnerUser->id,
            'company_name' => 'E2E Partner Co.',
            'tax_code' => '123456789',
            'address' => '123 Test St',
            'status' => 'approved',
            'contact_name' => 'Partner Owner',
            'contact_email' => $partnerUser->email,
            'contact_phone' => '0900111222',
        ]);

        $tour = Tour::create([
            'id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'title' => 'E2E Tour',
            'description' => 'Integration test tour',
            'destination' => 'Test City',
            'type' => 'domestic',
            'duration' => 2,
            'base_price' => 1000,
            'policy' => 'Flexible',
            // Postgres native array column; keep null to avoid malformed array literal in tests
            'tags' => null,
            'media' => [],
            'itinerary' => [],
            'status' => 'approved',
            'child_age_limit' => 12,
            'requires_passport' => false,
            'requires_visa' => false,
        ]);

        $schedule = TourSchedule::create([
            'tour_id' => $tour->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
            'seats_total' => 20,
            'seats_available' => 20,
            'min_participants' => 1,
        ]);

        $package = TourPackage::create([
            'tour_id' => $tour->id,
            'name' => 'Standard',
            'description' => 'Standard package',
            'adult_price' => 1000,
            'child_price' => 500,
            'is_active' => true,
        ]);

        return [$customer, $tour, $schedule, $package];
    }
}
