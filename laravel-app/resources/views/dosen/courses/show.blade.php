@extends('layouts.app')

@section('title', $course->name)

@section('content')
<x-page-header :title="$course->name"
    :crumbs="['Mata kuliah' => route('dosen.courses.index'), $course->code => null]"
    meta="{{ $course->code }} · {{ $students->count() }} mahasiswa · {{ $assignments->count() }} tugas">
    <x-slot:actions>
        <a href="{{ route('dosen.courses.edit', $course) }}" class="btn btn-secondary">Ubah</a>
        <form method="POST" action="{{ route('dosen.courses.destroy', $course) }}"
            data-confirm-title="Hapus {{ $course->code }}?" data-confirm-label="Hapus mata kuliah" data-confirm-tone="danger"
            data-confirm="Semua tugas, berkas pengumpulan, dan hasil analisis di mata kuliah ini ikut terhapus. Tindakan ini tidak dapat dibatalkan.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Hapus</button>
        </form>
    </x-slot:actions>
</x-page-header>

@if ($course->description)
    <p class="mb-6 max-w-3xl text-sm leading-relaxed text-muted">{{ $course->description }}</p>
@endif

<section class="panel mb-8">
    <div class="panel-head">
        <h2 class="panel-title">Tugas</h2>
        <a href="{{ route('dosen.courses.assignments.create', $course) }}" class="btn btn-primary btn-sm">Buat tugas</a>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Judul</th><th>Tenggat</th><th>Pengumpulan</th><th class="text-right">Terkumpul</th></tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr>
                        <td><a href="{{ route('dosen.assignments.show', $assignment) }}" class="font-medium">{{ $assignment->title }}</a></td>
                        <td class="whitespace-nowrap"><x-due-date :assignment="$assignment" :relative="false" /></td>
                        <td><x-assignment-state :assignment="$assignment" /></td>
                        <td class="num text-right">{{ $assignment->submissions_count }}<span class="text-faint">/{{ $students->count() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">Belum ada tugas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section id="mahasiswa" class="grid gap-6 lg:grid-cols-[1fr_340px]">
    <div class="panel h-fit">
        <div class="panel-head">
            <h2 class="panel-title">Mahasiswa terdaftar</h2>
            <span class="text-[13px] text-muted">{{ $students->count() }} orang</span>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Email</th><th></th></tr></thead>
                <tbody>
                    @forelse ($students as $student)
                        <tr>
                            <td class="font-medium">{{ $student->name }}</td>
                            <td class="text-muted">{{ $student->email }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('dosen.courses.students.destroy', [$course, $student]) }}"
                                    data-confirm-title="Keluarkan {{ $student->name }}?" data-confirm-label="Keluarkan" data-confirm-tone="danger"
                                    data-confirm="Mahasiswa tidak lagi dapat melihat mata kuliah ini. Pengumpulan yang sudah ada tetap tersimpan.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-[13px] text-danger hover:underline">Keluarkan</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-muted">Belum ada mahasiswa terdaftar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('dosen.courses.students.store', $course) }}" class="panel h-fit">
        @csrf
        <div class="panel-head">
            <h2 class="panel-title">Tambah mahasiswa</h2>
        </div>
        <div class="p-4">
            @if ($availableStudents->isEmpty())
                <p class="text-sm text-muted">Semua akun mahasiswa sudah terdaftar di mata kuliah ini.</p>
            @else
                <input type="search" placeholder="Cari nama atau email…" class="form-input mb-2 text-sm" data-student-filter>
                <div class="max-h-64 overflow-y-auto rounded-sm border border-line-soft">
                    @foreach ($availableStudents as $candidate)
                        <label class="flex cursor-pointer items-start gap-2 border-b border-line-soft px-3 py-2 text-sm last:border-b-0 hover:bg-paper"
                            data-student-option="{{ strtolower($candidate->name . ' ' . $candidate->email) }}">
                            <input type="checkbox" name="student_ids[]" value="{{ $candidate->id }}" class="mt-0.5 h-4 w-4 accent-brand"
                                @checked(in_array($candidate->id, old('student_ids', [])))>
                            <span>
                                <span class="block">{{ $candidate->name }}</span>
                                <span class="block text-[13px] text-muted">{{ $candidate->email }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('student_ids')<p class="form-error">{{ $message }}</p>@enderror
                @error('student_ids.*')<p class="form-error">{{ $message }}</p>@enderror
                <button type="submit" class="btn btn-primary btn-sm mt-3">Tambahkan yang dipilih</button>
            @endif
        </div>
    </form>
</section>

@push('scripts')
    <script>
        document.querySelector('[data-student-filter]')?.addEventListener('input', (e) => {
            const q = e.target.value.trim().toLowerCase();
            document.querySelectorAll('[data-student-option]').forEach((row) => {
                row.hidden = q !== '' && !row.dataset.studentOption.includes(q);
            });
        });
    </script>
@endpush
@endsection
