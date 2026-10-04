<?php

namespace App\Http\Requests;

use App\Models\Bed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super-admin') === true
            && $this->user()->hasPermission('manage-beds');
    }

    public function rules(): array
    {
        $bed = $this->route('bed');

        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('beds', 'number')
                    ->where('room_id', $this->input('room_id'))
                    ->ignore($bed instanceof Bed ? $bed->id : null),
            ],
        ];
    }
}
