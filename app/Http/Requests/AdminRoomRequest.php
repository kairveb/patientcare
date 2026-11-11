<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'super-admin') === true
            && $this->user()->hasPermission('manage-wards');
    }

    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('rooms', 'number')
                    ->where('ward_id', $this->input('ward_id'))
                    ->ignore($room instanceof Room ? $room->id : null),
            ],
            'type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
