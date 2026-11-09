<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $dateRules = [
            'bail',
            'required',
            'string',
            'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp',
        ];

        return [
            'room_id' => ['bail', 'required', 'integer', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $dates = [];

                foreach (['starts_at', 'ends_at'] as $field) {
                    try {
                        $dates[$field] = CarbonImmutable::parse($this->input($field));
                    } catch (InvalidFormatException) {
                        $validator->errors()->add($field, 'Укажите корректную дату и время с допустимым смещением.');
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $room = Room::with('office')->findOrFail($this->integer('room_id'));
                $startsAt = $dates['starts_at']->setTimezone($room->office->timezone);
                $endsAt = $dates['ends_at']->setTimezone($room->office->timezone);

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', 'Окончание брони должно быть позже начала.');

                    return;
                }

                $durationInSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

                if ($durationInSeconds < 15 * 60 || $durationInSeconds > 4 * 60 * 60) {
                    $validator->errors()->add('ends_at', 'Длительность брони должна быть от 15 минут до 4 часов.');
                }

                foreach (['starts_at' => $startsAt, 'ends_at' => $endsAt] as $field => $date) {
                    if ($date->minute % 15 !== 0 || $date->second !== 0 || $date->microsecond !== 0) {
                        $validator->errors()->add($field, 'Время должно соответствовать шагу 15 минут без секунд и долей секунды по времени офиса.');
                    }
                }

                $openingTime = $startsAt->setTime(9, 0);
                $closingTime = $startsAt->setTime(20, 0);

                if ($startsAt->lessThan($openingTime) || $startsAt->greaterThanOrEqualTo($closingTime)) {
                    $validator->errors()->add('starts_at', 'Бронь должна начинаться с 09:00 до 20:00 по времени офиса.');
                }

                if ($endsAt->toDateString() !== $startsAt->toDateString() || $endsAt->greaterThan($closingTime)) {
                    $validator->errors()->add('ends_at', 'Бронь должна заканчиваться не позже 20:00 того же дня по времени офиса.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Поле :attribute обязательно.',
            'string' => 'Поле :attribute должно быть строкой.',
            'date_format' => 'Укажите корректную дату и время в ISO 8601 с секундами и смещением ±ЧЧ:ММ или Z.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Комната не найдена.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
        ];
    }
}
