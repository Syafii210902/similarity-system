@extends('layouts.app')

@section('title', 'Beranda Dosen')

@section('content')
<x-page-header title="Beranda">
    <x-slot:actions>
        <a href="{{ route('dosen.courses.index') }}" class="btn btn-secondary">Kelola mata kuliah</a>
    </x-slot:actions>
</x-page-header>

<x-figures class="mb-6" :items="[
    'Mata kuliah' => $stats['courses'],
    'Tugas' => $stats['assignments'],
    'Pengumpulan' => $stats['submissions'],
    'Pasangan ditandai' => $stats['pairs_flagged'],
    'Terindikasi AI' => $stats['likely_ai'],
]" />

<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Tugas terbaru</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Tugas</th><th>Mata kuliah</th><th>Tenggat</th><th>Pengumpulan</th><th class="text-right">Berkas</th><th>Analisis</th></tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr>
                        <td><a href="{{ route('dosen.assignments.show', $assignment) }}" class="font-medium">{{ $assignment->title }}</a></td>
                        <td class="whitespace-nowrap"><span class="code-tag">{{ $assignment->course->code }}</span></td>
                        <td class="whitespace-nowrap"><x-due-date :assignment="$assignment" :relative="false" /></td>
                        <td><x-assignment-state :assignment="$assignment" /></td>
                        <td class="num text-right">{{ $assignment->submissions_count }}</td>
                        <td class="text-[13px]">
                            @php $run = $assignment->latestAnalysisRun; @endphp
                            @if ($run?->isActive())
                                <x-status tone="warn">Sedang diproses</x-status>
                            @elseif ($run?->status === 'failed')
                                <x-status tone="danger">Gagal</x-status>
                            @elseif ($assignment->similarity_results_count > 0)
                                <a href="{{ route('dosen.assignments.results', $assignment) }}">{{ $assignment->flagged_count }} pasangan ditandai, {{ $assignment->likely_ai_count }} terindikasi AI</a>
                            @else
                                <span class="text-muted">Belum dijalankan</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-muted">Belum ada tugas. Buat tugas dari halaman mata kuliah.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
