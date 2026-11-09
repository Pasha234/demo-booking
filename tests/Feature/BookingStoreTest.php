<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::factory()->create();
    }

    #[DataProvider('validBookings')]
    public function test_creates_booking_with_office_rules_and_utc_storage(
        string $timezone,
        string $startsAt,
        string $endsAt,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $this->room->office->update(['timezone' => $timezone]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]))
            ->assertCreated()
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('room_id', $this->room->id)
            ->assertJsonPath('starts_at', str_replace(' ', 'T', $expectedStart).'.000000Z')
            ->assertJsonPath('ends_at', str_replace(' ', 'T', $expectedEnd).'.000000Z');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'user_id' => $this->user->id,
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => $expectedStart,
            'ends_at' => $expectedEnd,
        ]);
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function validBookings(): array
    {
        return [
            'minimum duration at opening' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T09:15:00+03:00', '2026-11-02 06:00:00', '2026-11-02 06:15:00'],
            'maximum duration' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T13:00:00+03:00', '2026-11-02 06:00:00', '2026-11-02 10:00:00'],
            'end at closing' => ['Europe/Moscow', '2026-11-02T19:45:00+03:00', '2026-11-02T20:00:00+03:00', '2026-11-02 16:45:00', '2026-11-02 17:00:00'],
            'UTC input' => ['Europe/Moscow', '2026-11-02T06:00:00Z', '2026-11-02T07:00:00Z', '2026-11-02 06:00:00', '2026-11-02 07:00:00'],
            'numeric UTC offset' => ['Europe/Moscow', '2026-11-02T06:00:00+00:00', '2026-11-02T07:00:00+00:00', '2026-11-02 06:00:00', '2026-11-02 07:00:00'],
            'negative offset' => ['Europe/Moscow', '2026-11-02T02:00:00-05:00', '2026-11-02T03:00:00-05:00', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'different offsets at boundaries' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T15:00:00+07:00', '2026-11-02 07:00:00', '2026-11-02 08:00:00'],
            'Novosibirsk opening' => ['Asia/Novosibirsk', '2026-11-02T09:00:00+07:00', '2026-11-02T10:00:00+07:00', '2026-11-02 02:00:00', '2026-11-02 03:00:00'],
            'Novosibirsk closing with Moscow offset' => ['Asia/Novosibirsk', '2026-11-02T15:00:00+03:00', '2026-11-02T16:00:00+03:00', '2026-11-02 12:00:00', '2026-11-02 13:00:00'],
            'client date differs from office date' => ['Europe/Moscow', '2026-11-01T23:00:00-07:00', '2026-11-02T00:00:00-07:00', '2026-11-02 06:00:00', '2026-11-02 07:00:00'],
            'grid is checked in office timezone' => ['Europe/Moscow', '2026-11-02T06:07:00+00:07', '2026-11-02T07:07:00+00:07', '2026-11-02 06:00:00', '2026-11-02 07:00:00'],
            'past date is allowed' => ['Europe/Moscow', '2020-01-02T10:00:00+03:00', '2020-01-02T11:00:00+03:00', '2020-01-02 07:00:00', '2020-01-02 08:00:00'],
            'future date is allowed' => ['Europe/Moscow', '2030-01-02T10:00:00+03:00', '2030-01-02T11:00:00+03:00', '2030-01-02 07:00:00', '2030-01-02 08:00:00'],
        ];
    }

    public function test_assigns_authenticated_author_and_ignores_unvalidated_attributes(): void
    {
        $otherUser = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([
            'user_id' => $otherUser->id,
            'id' => 999999,
            'created_at' => '2000-01-01T00:00:00Z',
        ]))
            ->assertCreated()
            ->assertJsonPath('user_id', $this->user->id);

        $booking = Booking::sole();
        $this->assertSame($this->user->id, $booking->user_id);
        $this->assertNotSame(999999, $booking->id);
        $this->assertNotSame('2000-01-01', $booking->created_at->toDateString());
    }

    public function test_validates_required_fields(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_input(string $field, mixed $value): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFields(): array
    {
        return [
            'missing room' => ['room_id', null],
            'unknown room' => ['room_id', 999999],
            'noninteger room' => ['room_id', 1.5],
            'room array' => ['room_id', [1]],
            'SQL in room' => ['room_id', '1 OR 1=1'],
            'empty title' => ['title', ''],
            'nonstring title' => ['title', ['Meeting']],
            'long title' => ['title', str_repeat('a', 256)],
            'missing start' => ['starts_at', null],
            'missing end' => ['ends_at', null],
            'numeric date' => ['starts_at', 1793602800],
            'date array' => ['ends_at', ['2026-11-02T11:00:00+03:00']],
            'relative date' => ['starts_at', 'tomorrow'],
            'date without time' => ['starts_at', '2026-11-02'],
            'start without offset' => ['starts_at', '2026-11-02T10:00:00'],
            'end without offset' => ['ends_at', '2026-11-02T11:00:00'],
            'invalid calendar date' => ['starts_at', '2026-02-30T10:00:00+03:00'],
            'February 31' => ['starts_at', '2026-02-31T10:00:00+03:00'],
            'offset without colon' => ['starts_at', '2026-11-02T10:00:00+0300'],
            'seconds omitted' => ['starts_at', '2026-11-02T10:00+03:00'],
            'zero fractional seconds' => ['starts_at', '2026-11-02T10:00:00.000000+03:00'],
            'invalid hour' => ['starts_at', '2026-11-02T25:00:00+03:00'],
            'invalid minute' => ['ends_at', '2026-11-02T11:60:00+03:00'],
            'invalid offset' => ['starts_at', '2026-11-02T10:00:00+25:00'],
            'invalid negative offset' => ['starts_at', '2026-11-02T10:00:00-25:00'],
            'invalid end offset' => ['ends_at', '2026-11-02T11:00:00+25:00'],
            'SQL in date' => ['ends_at', "2026-11-02T11:00:00+03:00' OR '1'='1"],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_rejects_invalid_interval(string $timezone, string $startsAt, string $endsAt, string $field): void
    {
        $this->room->office->update(['timezone' => $timezone]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidIntervals(): array
    {
        return [
            'equal boundaries' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at'],
            'end before start' => ['Europe/Moscow', '2026-11-02T11:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at'],
            'end before start with different offsets' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+07:00', 'ends_at'],
            'shorter than minimum' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:10:00+03:00', 'ends_at'],
            'longer than maximum' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00', 'ends_at'],
            'start off grid' => ['Europe/Moscow', '2026-11-02T10:07:00+03:00', '2026-11-02T11:00:00+03:00', 'starts_at'],
            'end off grid' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:07:00+03:00', 'ends_at'],
            'start seconds' => ['Europe/Moscow', '2026-11-02T10:00:01+03:00', '2026-11-02T11:00:00+03:00', 'starts_at'],
            'end seconds' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:01+03:00', 'ends_at'],
            'start microseconds' => ['Europe/Moscow', '2026-11-02T10:00:00.000001+03:00', '2026-11-02T11:00:00+03:00', 'starts_at'],
            'end microseconds' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00.000001+03:00', 'ends_at'],
            'start before opening' => ['Europe/Moscow', '2026-11-02T08:45:00+03:00', '2026-11-02T09:00:00+03:00', 'starts_at'],
            'end after closing' => ['Europe/Moscow', '2026-11-02T19:00:00+03:00', '2026-11-02T20:15:00+03:00', 'ends_at'],
            'start at closing' => ['Europe/Moscow', '2026-11-02T20:00:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at'],
            'different office dates' => ['Europe/Moscow', '2026-11-02T19:00:00+03:00', '2026-11-03T10:00:00+03:00', 'ends_at'],
            'UTC looks valid but office is closed' => ['Europe/Moscow', '2026-11-02T19:00:00Z', '2026-11-02T20:00:00Z', 'starts_at'],
            'Novosibirsk end after closing' => ['Asia/Novosibirsk', '2026-11-02T16:00:00+03:00', '2026-11-02T17:00:00+03:00', 'ends_at'],
            'Novosibirsk start before opening' => ['Asia/Novosibirsk', '2026-11-02T08:45:00+07:00', '2026-11-02T09:00:00+07:00', 'starts_at'],
        ];
    }

    #[DataProvider('overlappingIntervals')]
    public function test_rejects_overlapping_bookings(string $startsAt, string $endsAt): void
    {
        Booking::factory()->for($this->room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-11-02T'.$startsAt.'+03:00',
            'ends_at' => '2026-11-02T'.$endsAt.'+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingIntervals(): array
    {
        return [
            'identical' => ['10:00:00', '11:00:00'],
            'contained' => ['10:15:00', '10:45:00'],
            'containing' => ['09:45:00', '11:15:00'],
            'overlaps start' => ['09:45:00', '10:15:00'],
            'overlaps end' => ['10:45:00', '11:15:00'],
        ];
    }

    #[DataProvider('adjacentIntervals')]
    public function test_allows_adjacent_bookings(string $startsAt, string $endsAt): void
    {
        Booking::factory()->for($this->room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-11-02T'.$startsAt.'+03:00',
            'ends_at' => '2026-11-02T'.$endsAt.'+03:00',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adjacentIntervals(): array
    {
        return [
            'before existing' => ['09:00:00', '10:00:00'],
            'after existing' => ['11:00:00', '12:00:00'],
        ];
    }

    public function test_rejects_same_slot_sent_with_different_offsets(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-11-02T14:00:00+07:00',
            'ends_at' => '2026-11-02T15:00:00+07:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_same_slot_in_different_rooms(): void
    {
        Booking::factory()->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'room_id' => $this->room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $overrides);
    }
}
