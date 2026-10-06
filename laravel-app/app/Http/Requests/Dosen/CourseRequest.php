<?php

namespace App\Http\Requests\Dosen;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course ? $this->user()->can('manage', $course) : $this->user()->can('create', \App\Models\Course::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('courses', 'code')->ignore($this->route('course'))],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode mata kuliah',
            'name' => 'nama mata kuliah',
            'description' => 'deskripsi',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'max' => ':Attribute maksimal :max karakter.',
            'code.unique' => 'Kode mata kuliah sudah dipakai.',
            'code.alpha_dash' => 'Kode hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah.',
        ];
    }
}
