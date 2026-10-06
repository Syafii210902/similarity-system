@extends('layouts.app')

@php $editing = $assignment->exists; @endphp

@section('title', $editing ? 'Ubah Tugas' : 'Buat Tugas')

@section('content')
<x-page-header :title="$editing ? 'Ubah tugas' : 'Buat tugas'"
    :crumbs="array_merge(
        ['Mata kuliah' => route('dosen.courses.index'), $course->code => route('dosen.courses.show', $course)],
        $editing ? [$assignment->title => route('dosen.assignments.show', $assignment), 'Ubah' => null] : ['Buat tugas' => null]
    )" />

<form method="POST" action="{{ $editing ? route('dosen.assignments.update', $assignment) : route('dosen.courses.assignments.store', $course) }}"
    class="max-w-2xl space-y-5">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div>
        <label for="title" class="form-label">Judul</label>
        <input id="title" name="title" value="{{ old('title', $assignment->title) }}" required maxlength="200"
            class="form-input" @error('title') aria-invalid="true" @enderror>
        @error('title')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="description" class="form-label">Deskripsi / instruksi <span class="font-normal text-muted">(opsional)</span></label>
        <textarea id="description" name="description" rows="6" maxlength="5000"
            class="form-input" @error('description') aria-invalid="true" @enderror>{{ old('description', $assignment->description) }}</textarea>
        @error('description')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="due_date" class="form-label">Tenggat <span class="font-normal text-muted">(opsional)</span></label>
        <input id="due_date" name="due_date" type="datetime-local"
            value="{{ old('due_date', $assignment->due_date?->format('Y-m-d\TH:i')) }}"
            class="form-input max-w-64 font-mono text-sm" @error('due_date') aria-invalid="true" @enderror>
        <p class="form-hint">Waktu dalam WIB. Setelah tenggat lewat, mahasiswa tidak dapat mengunggah atau mengubah berkas.
            Tanpa tenggat, pengumpulan tetap dibuka sampai Anda menutupnya.</p>
        @error('due_date')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="flex gap-2 border-t border-line pt-5">
        <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Buat tugas' }}</button>
        <a href="{{ $editing ? route('dosen.assignments.show', $assignment) : route('dosen.courses.show', $course) }}" class="btn btn-secondary">Batal</a>
    </div>
</form>
@endsection
