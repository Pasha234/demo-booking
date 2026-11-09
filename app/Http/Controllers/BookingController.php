<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): Booking
    {
        $attributes = $request->safe()->only(['room_id', 'title', 'starts_at', 'ends_at']);
        $attributes['starts_at'] = CarbonImmutable::parse($attributes['starts_at'])->utc();
        $attributes['ends_at'] = CarbonImmutable::parse($attributes['ends_at'])->utc();

        $hasConflict = Booking::where('room_id', $attributes['room_id'])
            ->where('starts_at', '<', $attributes['ends_at'])
            ->where('ends_at', '>', $attributes['starts_at'])
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'Комната занята в выбранное время.',
            ]);
        }

        $booking = $request->user()->bookings()->create($attributes);

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy(Request $request, string $id): Response
    {
        $booking = Booking::findOrFail($id);

        if ($booking->user_id !== $request->user()->id) {
            throw new AuthorizationException('Отменить бронь может только её автор.');
        }

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
