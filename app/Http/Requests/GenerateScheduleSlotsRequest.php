<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateScheduleSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slot_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'duration_minutes' => ['required', 'integer', 'in:15,30,45,60'],
            'break_start_time' => ['nullable', 'date_format:H:i', 'required_with:break_end_time'],
            'break_end_time' => ['nullable', 'date_format:H:i', 'required_with:break_start_time', 'after:break_start_time'],
        ];
    }
}
