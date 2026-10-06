@extends('layouts.app')

@section('title', 'Hasil analisis · ' . $assignment->title)

@use('App\Support\AnalysisLabels', 'L')

@php
    $course = $assignment->course;
    $flagged = $pairs->where('is_flagged', true);
    $aiCounts = $aiResults->countBy('verdict');
    $showMatrix = $documents->count() >= 3 && $documents->count() <= 40;
    $pairKey = fn ($a, $b) => min($a, $b) . '-' . max($a, $b);
    // Skala sekuensial satu warna (teal); nilai negatif dianggap 0.
    $llmInfo = $run?->metrics['llm'] ?? null;
    $llmUsage = $run?->metrics['llm_usage'] ?? null;
    $cellColor = fn (float $score) => 'color-mix(in oklab, var(--color-brand) ' . round(max(0, min(1, $score)) * 100) . '%, var(--color-hover))';
@endphp

@section('content')
<x-page-header title="Hasil analisis"
    :crumbs="['Mata kuliah' => route('dosen.courses.index'), $course->code => route('dosen.courses.show', $course), $assignment->title => route('dosen.assignments.show', $assignment), 'Hasil' => null]"
    :meta="$run ? 'Dijalankan ' . $run->finished_at->translatedFormat('d M Y, H:i') . ' · ambang ' . L::score($run->threshold, 2) . ' · ' . ($run->embedding_model ?? 'model tidak tercatat') . ($llmInfo ? ' · LLM ' . $llmInfo['model'] . (!empty($llmInfo['effort']) ? ' (effort ' . $llmInfo['effort'] . ')' : '') : '') . ($run->durationLabel() ? ' · ' . $run->durationLabel() : '') : null">
    <x-slot:actions>
        <a href="{{ route('dosen.assignments.show', $assignment) }}" class="btn btn-secondary">Kembali ke tugas</a>
    </x-slot:actions>
</x-page-header>

@if ($pairs->isEmpty() && $aiResults->isEmpty())
    <div class="panel px-5 py-10 text-center">
        <p class="font-medium">Belum ada hasil analisis untuk tugas ini.</p>
        <p class="mt-1 text-muted">Jalankan analisis dari halaman tugas setelah pengumpulan ditutup.</p>
    </div>
@else
    @if ($llmUsage && ($llmUsage['input_tokens'] ?? 0) > 0)
        <p class="-mt-3 mb-6 text-[13px] text-muted">
            Pemakaian LLM: <span class="num">{{ $llmUsage['calls'] }}</span> panggilan,
            <span class="num">{{ number_format($llmUsage['input_tokens'], 0, ',', '.') }}</span> token masuk,
            <span class="num">{{ number_format($llmUsage['output_tokens'], 0, ',', '.') }}</span> token keluar{{ ($llmUsage['failures'] ?? 0) > 0 ? ', ' . $llmUsage['failures'] . ' gagal' : '' }}.
        </p>
    @endif
    <x-figures class="mb-8" :items="[
        'Dokumen' => $documents->count() ?: $aiResults->count(),
        'Pasangan dibandingkan' => $pairs->count(),
        'Melewati ambang' => $flagged->count(),
        'Kemungkinan AI' => $aiCounts->get('LIKELY_AI', 0),
        'Campuran' => $aiCounts->get('MIXED_AI', 0),
    ]" />

    {{-- ============ KEMIRIPAN ============ --}}
    <section class="mb-10">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Kemiripan antar dokumen</h2>
                <p class="text-muted">Tahap 1 membandingkan semua pasangan dengan embedding multi-lapis (D dokumen · P passage · K kalimat). Pasangan di atas ambang diperiksa LLM.</p>
                <p class="mt-1 text-[13px] text-muted">Kolom <span class="font-medium text-ink">Validasi dosen</span> menjadi data acuan untuk halaman <a href="{{ route('dosen.evaluation', ['assignment' => $assignment->id]) }}">Evaluasi</a>. Validasi juga pasangan di bawah ambang agar recall dapat dihitung.</p>
            </div>
            @if ($showMatrix)
                <div class="inline-flex rounded-sm border border-line bg-surface p-0.5 text-[13px]" role="tablist" data-view-switch>
                    <button type="button" class="rounded-[4px] bg-hover px-3 py-1 font-medium" data-view="list" aria-selected="true">Daftar</button>
                    <button type="button" class="rounded-[4px] px-3 py-1 text-muted" data-view="matrix" aria-selected="false">Matriks</button>
                </div>
            @endif
        </div>

        {{-- Daftar pasangan --}}
        <div class="panel" data-view-panel="list">
            <div class="panel-head">
                <span class="text-muted">{{ $pairs->count() }} pasangan, urut skor tertinggi</span>
                <label class="flex items-center gap-2 text-[13px] text-muted">
                    <input type="checkbox" class="h-4 w-4 accent-ink" data-flagged-only @checked($flagged->isNotEmpty() && $pairs->count() > 10)>
                    Hanya yang melewati ambang
                </label>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Pasangan</th><th>Skor</th><th>Status</th><th>Segmen mirip</th><th>Rekomendasi</th><th>Validasi dosen</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($pairs as $pair)
                            @php
                                $segments = $pair->segmentCounts();
                                [$actionLabel, $actionTone] = L::action($pair->action_recommendation);
                            @endphp
                            <tr data-flagged="{{ $pair->is_flagged ? '1' : '0' }}">
                                <td>
                                    <span class="block font-medium">{{ $pair->submissionA?->user?->name ?? '—' }}</span>
                                    <span class="block text-muted">{{ $pair->submissionB?->user?->name ?? '—' }}</span>
                                </td>
                                <td class="w-44">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-20 overflow-hidden rounded-full bg-hover">
                                            <span class="block h-full rounded-full {{ $pair->is_flagged ? 'bg-ink' : 'bg-faint' }}"
                                                style="width: {{ round(max(0, min(1, $pair->similarity_score)) * 100) }}%"></span>
                                        </span>
                                        <span class="num font-medium">{{ L::score($pair->similarity_score) }}</span>
                                    </div>
                                    @if ($pair->layer_scores)
                                        <p class="num mt-1 text-xs whitespace-nowrap text-muted" title="Skor per lapis: dokumen · passage · kalimat">
                                            D {{ L::score($pair->layer_scores['document'] ?? null, 2) }} ·
                                            P {{ L::score($pair->layer_scores['passage'] ?? null, 2) }} ·
                                            K {{ L::score($pair->layer_scores['sentence'] ?? null, 2) }}
                                        </p>
                                    @endif
                                </td>
                                <td>
                                    @if ($pair->is_flagged)
                                        <x-status tone="warn">Di atas ambang</x-status>
                                    @else
                                        <span class="text-[13px] text-muted">Di bawah ambang</span>
                                    @endif
                                </td>
                                <td class="text-[13px]">
                                    @if ($pair->is_flagged)
                                        <span class="num">{{ $segments['VERBATIM_COPY'] }}</span> salinan langsung<br>
                                        <span class="num">{{ $segments['PARAPHRASED'] }}</span> parafrase
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($pair->is_flagged)
                                        <x-status :tone="$actionTone">{{ $actionLabel }}</x-status>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @php $pairLabel = $pairLabels->get(\App\Models\PairLabel::keyFor($pair->submission_a_id, $pair->submission_b_id)); @endphp
                                    <x-label-toggle :action="route('dosen.assignments.labels.pair', $assignment)"
                                        :fields="['submission_a_id' => $pair->submission_a_id, 'submission_b_id' => $pair->submission_b_id]"
                                        :options="['plagiarism' => 'Plagiat', 'not_plagiarism' => 'Bukan']"
                                        :current="$pairLabel ? ($pairLabel->is_plagiarism ? 'plagiarism' : 'not_plagiarism') : null" />
                                </td>
                                <td class="text-right">
                                    @if ($pair->is_flagged)
                                        <a href="{{ route('dosen.assignments.results.pair', [$assignment, $pair]) }}" class="whitespace-nowrap font-medium">Lihat detail</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Matriks (heatmap) --}}
        @if ($showMatrix)
            <div class="panel hidden p-5" data-view-panel="matrix">
                <div class="overflow-x-auto">
                    <table class="border-separate border-spacing-[2px] text-[13px]" data-heatmap>
                        <thead>
                            <tr>
                                <th></th>
                                @foreach ($documents as $i => $doc)
                                    <th class="num w-9 pb-1 text-center text-xs font-medium text-muted" title="{{ $doc->user?->name }}">{{ $i + 1 }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($documents as $i => $rowDoc)
                                <tr>
                                    <th class="max-w-48 truncate pr-3 text-left font-normal whitespace-nowrap">
                                        <span class="num mr-1.5 text-xs text-muted">{{ $i + 1 }}</span>{{ $rowDoc->user?->name }}
                                    </th>
                                    @foreach ($documents as $colDoc)
                                        @if ($rowDoc->id === $colDoc->id)
                                            <td class="h-9 w-9 rounded-[4px] bg-paper"></td>
                                        @else
                                            @php $cell = $scores->get($pairKey($rowDoc->id, $colDoc->id)); @endphp
                                            <td class="h-9 w-9 p-0">
                                                @if ($cell)
                                                    <a href="{{ $cell->is_flagged ? route('dosen.assignments.results.pair', [$assignment, $cell]) : '#' }}"
                                                        @class(['block h-9 w-9 rounded-[4px]', 'ring-2 ring-ink ring-inset' => $cell->is_flagged, 'pointer-events-auto cursor-default' => !$cell->is_flagged])
                                                        style="background: {{ $cellColor($cell->similarity_score) }}"
                                                        data-tip="{{ $rowDoc->user?->name }} × {{ $colDoc->user?->name }}"
                                                        data-score="{{ L::score($cell->similarity_score) }}"
                                                        data-flag="{{ $cell->is_flagged ? 'Di atas ambang, klik untuk detail' : 'Di bawah ambang' }}"
                                                        @unless ($cell->is_flagged) onclick="return false" @endunless
                                                        aria-label="{{ $rowDoc->user?->name }} dan {{ $colDoc->user?->name }}: {{ L::score($cell->similarity_score) }}"></a>
                                                @else
                                                    <span class="block h-9 w-9 rounded-[4px] bg-paper"></span>
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-line-soft pt-4 text-[13px] text-muted">
                    <div class="flex items-center gap-2">
                        <span class="num">0</span>
                        <span class="relative h-2 w-40 rounded-full" style="background: linear-gradient(to right, var(--color-hover), var(--color-brand))">
                            <span class="absolute -top-1 h-4 w-0.5 bg-ink" style="left: {{ round($threshold * 100) }}%" title="Ambang {{ L::score($threshold, 2) }}"></span>
                        </span>
                        <span class="num">1</span>
                    </div>
                    <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-[3px] ring-2 ring-ink ring-inset"></span> di atas ambang {{ L::score($threshold, 2) }}</span>
                    <span>Skor negatif ditampilkan sebagai 0.</span>
                </div>
            </div>
        @endif
    </section>

    {{-- ============ DETEKSI AI ============ --}}
    <section>
        <div class="mb-3">
            <h2 class="text-base font-semibold">Deteksi konten AI</h2>
            <p class="text-muted">Setiap dokumen dinilai terpisah oleh LLM berdasarkan ciri gaya penulisan. Gunakan sebagai indikasi, bukan bukti.</p>
        </div>
        <div class="panel">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr><th>Mahasiswa</th><th>Probabilitas</th><th>Penilaian</th><th class="w-1/2">Catatan</th><th>Validasi dosen</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($aiResults as $ai)
                            @php [$verdictLabel, $verdictTone] = L::aiVerdict($ai->verdict); @endphp
                            <tr>
                                <td class="align-top font-medium">{{ $ai->submission?->user?->name ?? '—' }}</td>
                                <td class="w-40 align-top">
                                    @if ($ai->ai_probability !== null)
                                        <div class="flex items-center gap-2.5">
                                            <span class="h-1.5 w-16 overflow-hidden rounded-full bg-hover">
                                                <span class="block h-full rounded-full bg-ink" style="width: {{ round($ai->ai_probability * 100) }}%"></span>
                                            </span>
                                            <span class="num font-medium">{{ L::percent($ai->ai_probability) }}</span>
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="align-top"><x-status :tone="$verdictTone">{{ $verdictLabel }}</x-status></td>
                                <td class="align-top text-[13px] leading-relaxed">
                                    <p class="text-muted">{{ $ai->analysis_summary ?: '—' }}</p>
                                    @if (!empty($ai->flagged_patterns))
                                        <details class="mt-1.5">
                                            <summary class="cursor-pointer text-brand">{{ count($ai->flagged_patterns) }} pola kalimat ditandai</summary>
                                            <ul class="mt-1.5 space-y-1 border-l-2 border-line pl-3">
                                                @foreach ($ai->flagged_patterns as $pattern)
                                                    <li>“{{ $pattern }}”</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                                <td class="align-top">
                                    @php $aiLabel = $aiLabels->get($ai->submission_id); @endphp
                                    <x-label-toggle :action="route('dosen.assignments.labels.ai', $assignment)"
                                        :fields="['submission_id' => $ai->submission_id]"
                                        :options="['ai' => 'AI', 'human' => 'Manusia']"
                                        :current="$aiLabel === null ? null : ($aiLabel ? 'ai' : 'human')" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-muted">Tidak ada hasil deteksi AI.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div id="heatmap-tip" class="pointer-events-none fixed z-40 hidden rounded-sm bg-ink px-2.5 py-1.5 text-xs text-white shadow-lg"></div>
@endif
@endsection

@push('scripts')
    <script>
        (() => {
            // Filter "hanya yang melewati ambang"
            const flaggedOnly = document.querySelector('[data-flagged-only]');
            const applyFilter = () => document.querySelectorAll('tr[data-flagged]').forEach((row) => {
                row.hidden = flaggedOnly.checked && row.dataset.flagged !== '1';
            });
            flaggedOnly?.addEventListener('change', applyFilter);
            if (flaggedOnly) applyFilter();

            // Tab Daftar / Matriks
            document.querySelectorAll('[data-view-switch] [data-view]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('[data-view-switch] [data-view]').forEach((b) => {
                        const active = b === button;
                        b.setAttribute('aria-selected', active);
                        b.classList.toggle('bg-hover', active);
                        b.classList.toggle('font-medium', active);
                        b.classList.toggle('text-muted', !active);
                    });
                    document.querySelectorAll('[data-view-panel]').forEach((panel) => {
                        panel.classList.toggle('hidden', panel.dataset.viewPanel !== button.dataset.view);
                    });
                });
            });

            // Tooltip sel matriks
            const tip = document.getElementById('heatmap-tip');
            document.querySelectorAll('[data-heatmap] [data-tip]').forEach((cell) => {
                cell.addEventListener('mouseenter', () => {
                    tip.innerHTML = '';
                    [cell.dataset.tip, 'Skor ' + cell.dataset.score, cell.dataset.flag].forEach((line, i) => {
                        const el = document.createElement('div');
                        el.textContent = line;
                        if (i === 1) el.className = 'font-semibold tabular-nums';
                        if (i === 2) el.className = 'opacity-70';
                        tip.appendChild(el);
                    });
                    tip.classList.remove('hidden');
                });
                cell.addEventListener('mousemove', (e) => {
                    tip.style.left = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8) + 'px';
                    tip.style.top = (e.clientY + 14) + 'px';
                });
                cell.addEventListener('mouseleave', () => tip.classList.add('hidden'));
            });
        })();
    </script>
@endpush
