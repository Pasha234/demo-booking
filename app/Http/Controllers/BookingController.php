<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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

        try {
            $booking = DB::transaction(fn (): Booking => $request->user()->bookings()->create($attributes));
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23P01'
                || ! str_contains($exception->errorInfo[2] ?? '', '"bookings_room_time_no_overlap"')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'starts_at' => 'Комната занята в выбранное время.',
            ]);
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'request' => $request->validated(),
        ]);

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
