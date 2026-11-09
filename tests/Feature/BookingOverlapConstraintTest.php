<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingOverlapConstraintTest extends TestCase
{
    use DatabaseMigrations;

    public function test_returns_validation_error_if_slot_is_taken_after_the_initial_check(): void
    {
        $room = Room::factory()->create();
        $user = User::factory()->create();
        $concurrentConnection = (new PostgresConnector)->connect(DB::connection()->getConfig());
        Sanctum::actingAs($user);

        Booking::creating(function (Booking $booking) use ($concurrentConnection): void {
            $statement = $concurrentConnection->prepare(
                'INSERT INTO bookings (room_id, user_id, starts_at, ends_at, title) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $booking->room_id,
                $booking->user_id,
                $booking->getAttributes()['starts_at'],
                $booking->getAttributes()['ends_at'],
                'Concurrent booking',
            ]);
        });

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Requested booking',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', 'Комната занята в выбранное время.');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', ['room_id' => $room->id, 'title' => 'Concurrent booking']);
    }
}
