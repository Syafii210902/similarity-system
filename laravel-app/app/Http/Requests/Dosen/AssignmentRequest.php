<?php

namespace App\Http\Requests\Dosen;

use Illuminate\Foundation\Http\FormRequest;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment
            ? $this->user()->can('manage', $assignment)
            : $this->user()->can('manage', $this->route('course'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Input datetime-local, diinterpretasikan dalam zona waktu aplikasi (WIB).
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'judul tugas',
            'description' => 'deskripsi',
            'due_date' => 'tenggat',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'max' => ':Attribute maksimal :max karakter.',
            'date' => ':Attribute tidak valid.',
        ];
    }
}
