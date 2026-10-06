@extends('layouts.app')

@php $editing = $course->exists; @endphp

@section('title', $editing ? 'Ubah ' . $course->code : 'Tambah Mata Kuliah')

@section('content')
<x-page-header :title="$editing ? 'Ubah mata kuliah' : 'Tambah mata kuliah'"
    :crumbs="$editing
        ? ['Mata kuliah' => route('dosen.courses.index'), $course->code => route('dosen.courses.show', $course), 'Ubah' => null]
        : ['Mata kuliah' => route('dosen.courses.index'), 'Tambah' => null]" />

<form method="POST" action="{{ $editing ? route('dosen.courses.update', $course) : route('dosen.courses.store') }}" class="max-w-xl space-y-5">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div>
        <label for="code" class="form-label">Kode</label>
        <input id="code" name="code" value="{{ old('code', $course->code) }}" required maxlength="20"
            class="form-input max-w-48 font-mono uppercase" @error('code') aria-invalid="true" @enderror>
        @error('code')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="name" class="form-label">Nama mata kuliah</label>
        <input id="name" name="name" value="{{ old('name', $course->name) }}" required maxlength="150"
            class="form-input" @error('name') aria-invalid="true" @enderror>
        @error('name')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="description" class="form-label">Deskripsi <span class="font-normal text-muted">(opsional)</span></label>
        <textarea id="description" name="description" rows="4" maxlength="2000"
            class="form-input" @error('description') aria-invalid="true" @enderror>{{ old('description', $course->description) }}</textarea>
        @error('description')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="flex gap-2 border-t border-line pt-5">
        <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Tambah' }}</button>
        <a href="{{ $editing ? route('dosen.courses.show', $course) : route('dosen.courses.index') }}" class="btn btn-secondary">Batal</a>
    </div>
</form>
@endsection
