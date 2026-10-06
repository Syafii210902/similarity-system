<?php

namespace App\Http\Requests\Dosen;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('course'));
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', User::ROLE_MAHASISWA)],
        ];
    }

    public function messages(): array
    {
        return [
            'student_ids.required' => 'Pilih minimal satu mahasiswa.',
            'student_ids.*.exists' => 'Mahasiswa yang dipilih tidak valid.',
        ];
    }
}
