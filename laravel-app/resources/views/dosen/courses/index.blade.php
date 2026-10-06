@extends('layouts.app')

@section('title', 'Mata Kuliah')

@section('content')
<x-page-header title="Mata kuliah">
    <x-slot:actions>
        <a href="{{ route('dosen.courses.create') }}" class="btn btn-primary">Tambah mata kuliah</a>
    </x-slot:actions>
</x-page-header>

<section class="panel">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Kode</th><th>Nama</th><th class="text-right">Mahasiswa</th><th class="text-right">Tugas</th></tr>
            </thead>
            <tbody>
                @forelse ($courses as $course)
                    <tr>
                        <td class="code-tag whitespace-nowrap">{{ $course->code }}</td>
                        <td><a href="{{ route('dosen.courses.show', $course) }}" class="font-medium">{{ $course->name }}</a></td>
                        <td class="num text-right">{{ $course->mahasiswa_count }}</td>
                        <td class="num text-right">{{ $course->assignments_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">Belum ada mata kuliah.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
