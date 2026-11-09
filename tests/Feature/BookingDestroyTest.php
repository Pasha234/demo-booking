<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('bookingDates')]
    public function test_author_can_cancel_past_current_and_future_bookings(string $startsAt, string $endsAt): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:00:00Z'));
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        $this->assertModelMissing($booking);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bookingDates(): array
    {
        return [
            'past' => ['2026-10-31 07:00:00', '2026-10-31 08:00:00'],
            'current' => ['2026-11-02 11:30:00', '2026-11-02 12:30:00'],
            'future' => ['2026-11-03 07:00:00', '2026-11-03 08:00:00'],
        ];
    }

    public function test_cannot_cancel_another_authors_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/'.$booking->id)->assertForbidden();

        $this->assertModelExists($booking);
    }

    public function test_returns_not_found_for_unknown_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_returns_not_found_for_invalid_identifier(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/invalid')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertModelExists($booking);
    }
}
