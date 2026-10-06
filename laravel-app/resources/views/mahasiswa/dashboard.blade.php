@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
<x-page-header title="Beranda"
    meta="{{ $stats['courses'] }} mata kuliah · {{ $stats['submitted'] }} dari {{ $stats['assignments'] }} tugas sudah dikumpulkan" />

<div class="grid gap-8 lg:grid-cols-[1fr_340px]">
    <section class="panel h-fit">
        <div class="panel-head">
            <h2 class="panel-title">Belum dikumpulkan</h2>
            <span class="text-[13px] text-muted">{{ $stats['pending'] }} tugas masih dibuka</span>
        </div>
        @if ($upcoming->isEmpty())
            <p class="px-4 py-6 text-sm text-muted">Tidak ada tugas terbuka yang belum Anda kumpulkan.</p>
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Tugas</th><th>Mata kuliah</th><th>Tenggat</th></tr></thead>
                    <tbody>
                        @foreach ($upcoming as $assignment)
                            <tr>
                                <td><a href="{{ route('mahasiswa.assignments.show', $assignment) }}" class="font-medium">{{ $assignment->title }}</a></td>
                                <td class="whitespace-nowrap"><span class="code-tag">{{ $assignment->course->code }}</span></td>
                                <td class="whitespace-nowrap"><x-due-date :assignment="$assignment" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel h-fit">
        <div class="panel-head">
            <h2 class="panel-title">Mata kuliah yang diikuti</h2>
        </div>
        @forelse ($courses as $course)
            <a href="{{ route('mahasiswa.courses.show', $course) }}"
                class="block border-b border-line-soft px-5 py-3.5 text-ink no-underline last:border-b-0 hover:bg-hover/60 hover:no-underline">
                <span class="flex items-center gap-2">
                    <span class="code-tag">{{ $course->code }}</span>
                    <span class="truncate font-medium">{{ $course->name }}</span>
                </span>
                <span class="mt-1 block text-[13px] text-muted">{{ $course->dosen->name }} · {{ $course->assignments_count }} tugas</span>
            </a>
        @empty
            <p class="px-5 py-6 text-muted">Anda belum terdaftar di mata kuliah mana pun. Hubungi dosen pengampu.</p>
        @endforelse
    </section>
</div>
@endsection
