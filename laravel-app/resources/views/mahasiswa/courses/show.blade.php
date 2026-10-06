@extends('layouts.app')

@section('title', $course->name)

@section('content')
<x-page-header :title="$course->name"
    :crumbs="['Beranda' => route('mahasiswa.dashboard'), $course->code => null]"
    meta="{{ $course->code }} · Dosen pengampu: {{ $course->dosen->name }}" />

@if ($course->description)
    <p class="mb-6 max-w-3xl text-sm leading-relaxed text-muted">{{ $course->description }}</p>
@endif

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Tugas</h2>
        <span class="text-[13px] text-muted">{{ $assignments->count() }} tugas</span>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Judul</th><th>Tenggat</th><th>Pengumpulan</th><th>Status Anda</th></tr></thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr>
                        <td><a href="{{ route('mahasiswa.assignments.show', $assignment) }}" class="font-medium">{{ $assignment->title }}</a></td>
                        <td class="whitespace-nowrap"><x-due-date :assignment="$assignment" /></td>
                        <td><x-assignment-state :assignment="$assignment" /></td>
                        <td><x-submission-status :assignment="$assignment" :submission="$assignment->submissions->first()" /></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">Belum ada tugas pada mata kuliah ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
