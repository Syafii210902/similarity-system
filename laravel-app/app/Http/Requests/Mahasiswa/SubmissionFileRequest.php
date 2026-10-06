<?php

namespace App\Http\Requests\Mahasiswa;

use Illuminate\Foundation\Http\FormRequest;

class SubmissionFileRequest extends FormRequest
{
    /** Batas ukuran file dalam KB (10 MB). */
    public const MAX_SIZE_KB = 10240;

    public function authorize(): bool
    {
        return $this->user()->can('submit', $this->route('assignment'));
    }

    public function rules(): array
    {
        return [
            // Hanya PDF & DOCX: format yang dapat di-parse oleh FastAPI (pypdf / python-docx).
            'file' => ['required', 'file', 'extensions:pdf,docx', 'mimes:pdf,docx', 'max:' . self::MAX_SIZE_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Pilih file tugas terlebih dahulu.',
            'file.uploaded' => 'File gagal diunggah. Pastikan ukuran file tidak melebihi 10 MB.',
            'file.extensions' => 'Format file harus PDF (.pdf) atau Word (.docx).',
            'file.mimes' => 'Isi file tidak sesuai format PDF atau Word (.docx).',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ];
    }
}
