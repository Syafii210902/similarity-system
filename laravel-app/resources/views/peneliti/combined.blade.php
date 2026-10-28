@extends('layouts.app')

@section('title', 'Evaluasi gabungan')

@php
    $f = fn ($v, $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $pct = fn ($n, $d) => $d > 0 ? number_format($n / $d * 100, 0, ',', '.') . '%' : '—';
    $int = fn ($v) => number_format((int) $v, 0, ',', '.');
    $weightsLabel = fn (array $w) => implode(' / ', array_map(fn ($x) => number_format($x, 2, ',', '.'), $w));
@endphp

@section('content')
<x-page-header title="Evaluasi gabungan"
    :crumbs="['Lab Pengujian' => route('peneliti.dashboard'), 'Evaluasi gabungan' => null]"
    meta="Pilih satu run untuk setiap tugas. Perbandingan tetap hanya di dalam tugas; hasilnya digabung dengan menjumlahkan TP, FP, TN, dan FN." />

{{-- ============ PILIH RUN ============ --}}
<form method="GET" action="{{ route('peneliti.combined') }}" class="panel mb-8">
    <div class="panel-head">
        <h2 class="panel-title">Run yang digabung</h2>
        <span class="text-[13px] text-muted">minimal 2 run, satu per tugas</span>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th class="w-8"></th><th>Run</th><th>Dataset (tugas)</th><th class="text-right">Ambang</th><th>Bobot D/P/K</th><th>LLM</th><th>Selesai</th></tr></thead>
            <tbody>
                @forelse ($available as $run)
                    <tr>
                        <td><input type="checkbox" name="runs[]" value="{{ $run->id }}" @checked($selectedIds->contains($run->id)) aria-label="Pilih {{ $run->name }}"></td>
                        <td><a href="{{ route('peneliti.runs.show', $run) }}" class="font-medium">{{ $run->name }}</a></td>
                        <td class="text-[13px]">
                            {{ $run->dataset->name }}
                            @if ($run->dataset->is_sample)<x-status tone="warn" class="ml-1.5">contoh</x-status>@endif
                        </td>
                        <td class="num text-right">{{ $f($run->config['threshold'], 2) }}</td>
                        <td class="num text-[13px]">{{ $weightsLabel($run->config['weights']) }}</td>
                        <td class="text-[13px] text-muted">
                            {{ ($run->config['run_tier2'] ?? false) ? 'Tahap 2' : '' }}{{ ($run->config['run_tier2'] ?? false) && ($run->config['run_ai_detection'] ?? false) ? ' · ' : '' }}{{ ($run->config['run_ai_detection'] ?? false) ? 'deteksi AI' : '' }}
                            @unless (($run->config['run_tier2'] ?? false) || ($run->config['run_ai_detection'] ?? false))—@endunless
                        </td>
                        <td class="num whitespace-nowrap text-[13px] text-muted">{{ $run->finished_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-muted">Belum ada eksperimen yang selesai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-line-soft px-5 py-4">
        <button type="submit" class="btn btn-primary">Gabungkan</button>
    </div>
</form>

@if ($selectedIds->count() > 0 && !$result)
    <p class="alert alert-warn">Pilih minimal 2 run yang sudah selesai.</p>
@endif

@if ($result)
    @php $r = $result; $e = $r['efficiency']; @endphp

    @foreach ($r['warnings'] as $warning)
        <p class="alert alert-warn mb-3">{{ $warning }}</p>
    @endforeach
    @if (collect($r['tasks'])->contains(fn ($t) => $t['run']->dataset->is_sample))
        <p class="alert alert-warn mb-3">Gabungan memuat korpus contoh (teks buatan AI). Gunakan hanya untuk memahami tampilan, bukan sebagai temuan penelitian.</p>
    @endif

    {{-- ============ PER TUGAS ============ --}}
    <section class="mb-10 mt-6">
        <h2 class="text-base font-semibold">Hasil per tugas dan gabungan</h2>
        <p class="mb-3 text-muted">Keputusan run: Tahap 1 = skor gabungan ≥ ambang run; Tahap 1+2 = juga dikonfirmasi LLM. Baris gabungan menjumlahkan TP, FP, TN, dan FN semua tugas sebelum metrik dihitung.</p>
        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tugas</th><th class="text-right">Pasangan</th><th class="text-right">Plagiat</th><th class="text-right">Ambang</th>
                        <th class="text-right">P Tahap 1</th><th class="text-right">R Tahap 1</th><th class="text-right">F1 Tahap 1</th>
                        <th class="text-right">P Tahap 1+2</th><th class="text-right">R Tahap 1+2</th><th class="text-right">F1 Tahap 1+2</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['tasks'] as $t)
                        <tr>
                            <td><a href="{{ route('peneliti.runs.show', $t['run']) }}" class="font-medium">{{ $t['run']->dataset->name }}</a><span class="block text-xs text-muted">{{ $t['run']->name }}</span></td>
                            <td class="num text-right">{{ $t['pairs'] }}</td>
                            <td class="num text-right">{{ $t['positives'] }}</td>
                            <td class="num text-right">{{ $f($t['threshold'], 2) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier1']['precision']) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier1']['recall']) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier1']['f1']) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier12']['precision'] ?? null) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier12']['recall'] ?? null) }}</td>
                            <td class="num text-right">{{ $f($t['tiers']['tier12']['f1'] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td>Gabungan</td>
                        <td class="num text-right">{{ $r['rows']->count() }}</td>
                        <td class="num text-right">{{ $r['rows']->where('actual', true)->count() }}</td>
                        <td></td>
                        <td class="num text-right">{{ $f($r['tiers']['tier1']['precision']) }}</td>
                        <td class="num text-right">{{ $f($r['tiers']['tier1']['recall']) }}</td>
                        <td class="num text-right">{{ $f($r['tiers']['tier1']['f1']) }}</td>
                        <td class="num text-right">{{ $f($r['tiers']['tier12']['precision'] ?? null) }}</td>
                        <td class="num text-right">{{ $f($r['tiers']['tier12']['recall'] ?? null) }}</td>
                        <td class="num text-right">{{ $f($r['tiers']['tier12']['f1'] ?? null) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @include('dosen.evaluation._confusion', ['title' => 'Gabungan · Tahap 1 saja', 'm' => $r['tiers']['tier1'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
            @if ($r['tiers']['tier12'])
                @include('dosen.evaluation._confusion', ['title' => 'Gabungan · Tahap 1 + Tahap 2', 'm' => $r['tiers']['tier12'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
            @else
                <div class="panel flex items-center justify-center p-6 text-center text-muted">Tahap 2 tidak dijalankan pada semua run yang digabung.</div>
            @endif
        </div>
    </section>

    {{-- ============ EFISIENSI ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">Efisiensi pemanggilan LLM</h2>
        <p class="mb-3 text-muted">Tanpa Layer 1, LLM harus memeriksa semua pasangan. Total panggilan LLM = verifikasi pasangan + deteksi AI (satu per dokumen, tidak dipengaruhi ambang).</p>
        <x-figures class="mb-4" :items="[
            'Pasangan' => $int($e['pairs']),
            'Diteruskan ke LLM' => $int($e['above']),
            'Reduction rate' => $e['reduction'] === null ? '—' : $f($e['reduction'] * 100, 1) . '%',
            'Total panggilan LLM' => $int($e['llm_calls']),
            'Token masuk / keluar' => $int($e['input_tokens']) . ' / ' . $int($e['output_tokens']),
        ]" />
        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Tugas</th><th class="text-right">Pasangan</th><th class="text-right">Diteruskan</th><th class="text-right">Reduction rate</th><th class="text-right">Verifikasi pasangan</th><th class="text-right">Deteksi AI</th><th class="text-right">Total panggilan</th><th class="text-right">Token masuk</th><th class="text-right">Token keluar</th><th class="text-right">Waktu (dtk)</th></tr></thead>
                <tbody>
                    @foreach ($r['tasks'] as $t)
                        <tr>
                            <td class="font-medium">{{ $t['run']->dataset->name }}</td>
                            <td class="num text-right">{{ $t['pairs'] }}</td>
                            <td class="num text-right">{{ $t['above'] }}</td>
                            <td class="num text-right">{{ $t['reduction'] === null ? '—' : $f($t['reduction'] * 100, 1) . '%' }}</td>
                            <td class="num text-right">{{ $t['sim_calls'] }}</td>
                            <td class="num text-right">{{ $t['ai_calls'] }}</td>
                            <td class="num text-right">{{ $t['llm_calls'] }}</td>
                            <td class="num text-right">{{ $int($t['input_tokens']) }}</td>
                            <td class="num text-right">{{ $int($t['output_tokens']) }}</td>
                            <td class="num text-right">{{ $f($t['seconds'], 1) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td>Gabungan</td>
                        <td class="num text-right">{{ $e['pairs'] }}</td>
                        <td class="num text-right">{{ $e['above'] }}</td>
                        <td class="num text-right">{{ $e['reduction'] === null ? '—' : $f($e['reduction'] * 100, 1) . '%' }}</td>
                        <td class="num text-right">{{ $e['sim_calls'] }}</td>
                        <td class="num text-right">{{ $e['ai_calls'] }}</td>
                        <td class="num text-right">{{ $e['llm_calls'] }}</td>
                        <td class="num text-right">{{ $int($e['input_tokens']) }}</td>
                        <td class="num text-right">{{ $int($e['output_tokens']) }}</td>
                        <td class="num text-right">{{ $f($e['seconds'], 1) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    {{-- ============ ABLATION & CV GABUNGAN ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">Pemilihan ambang dan bobot lapis (5-fold CV gabungan)</h2>
        <p class="mb-3 text-muted">Layer 1 saja, pada seluruh {{ $r['rows']->count() }} pasangan gabungan ({{ $r['rows']->where('actual', true)->count() }} plagiat). Fold dibagi stratified per tugas dan label (seed {{ \App\Services\ExperimentEvaluator::SEED }}), sehingga ambang terpilih berlaku untuk semua tugas. Ambang diuji {{ $f(\App\Services\ExperimentEvaluator::THRESHOLDS[0], 2) }}–{{ $f(last(\App\Services\ExperimentEvaluator::THRESHOLDS), 2) }} dengan interval 0,05.</p>
        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Konfigurasi</th>
                        <th class="text-right">F1 CV<br><span class="font-normal">rata-rata ± sd</span></th>
                        <th class="text-right">Precision CV</th>
                        <th class="text-right">Recall CV</th>
                        <th>Ambang per fold</th>
                        <th class="text-right">F1 terbaik<br><span class="font-normal">data penuh</span></th>
                        <th>Bobot D/P/K · ambang terbaik</th>
                    </tr>
                </thead>
                <tbody>
                    @php $bestCv = collect($r['ablation'])->max(fn ($a) => $a['cv']['f1_mean'] ?? -1); @endphp
                    @foreach ($r['ablation'] as $a)
                        <tr @class(['bg-brand-soft/40' => ($a['cv']['f1_mean'] ?? -2) === $bestCv])>
                            <td class="font-medium">{{ $a['label'] }}</td>
                            <td class="num text-right @if (($a['cv']['f1_mean'] ?? -2) === $bestCv) font-semibold @endif">{{ $f($a['cv']['f1_mean']) }}<span class="text-muted"> ± {{ $f($a['cv']['f1_sd']) }}</span></td>
                            <td class="num text-right">{{ $f($a['cv']['pooled']['precision']) }}</td>
                            <td class="num text-right">{{ $f($a['cv']['pooled']['recall']) }}</td>
                            <td class="num whitespace-nowrap text-[13px]">{{ collect($a['cv']['thresholds'])->map(fn ($t) => $f($t, 2))->implode('; ') ?: '–' }}</td>
                            <td class="num text-right text-muted">{{ $f($a['in_sample']['f1']) }}</td>
                            <td class="num whitespace-nowrap text-[13px] text-muted">{{ $weightsLabel($a['best_weights']) }} · {{ $f($a['best_threshold'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ============ SWEEP AMBANG GABUNGAN ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">F1 terhadap ambang (gabungan)</h2>
        <p class="mb-3 text-muted">Layer 1 saja, data penuh. “Gabungan” memakai bobot {{ $weightsLabel($r['weights']) }}. Garis putus-putus = ambang awal 0,70.</p>
        <div class="panel p-5">
            <x-f1-chart :sweep="$r['sweep']" :mark-threshold="0.70" :series="['combined' => 'Gabungan', 'document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat']" />
            <details class="mt-4 border-t border-line-soft pt-3">
                <summary class="cursor-pointer text-[13px] text-brand">Tampilkan tabel data</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Ambang</th><th class="text-right">Diteruskan ke LLM</th><th class="text-right">Reduction rate</th><th class="text-right">P gabungan</th><th class="text-right">R gabungan</th><th class="text-right">F1 gabungan</th></tr></thead>
                        <tbody>
                            @foreach ($r['sweep']['combined'] as $t => $mm)
                                <tr>
                                    <td class="num">{{ $f((float) $t, 2) }}</td>
                                    <td class="num text-right">{{ $mm['tp'] + $mm['fp'] }}</td>
                                    <td class="num text-right">{{ $pct($mm['fn'] + $mm['tn'], $mm['n']) }}</td>
                                    <td class="num text-right">{{ $f($mm['precision']) }}</td>
                                    <td class="num text-right">{{ $f($mm['recall']) }}</td>
                                    <td class="num text-right">{{ $f($mm['f1']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </section>

    {{-- ============ DETEKSI AI GABUNGAN ============ --}}
    @if ($r['ai'])
        @php $ai = $r['ai']; @endphp
        <section class="mb-10">
            <h2 class="text-base font-semibold">Deteksi konten AI (gabungan)</h2>
            <p class="mb-3 text-muted">{{ $ai['validCount'] }} dokumen ({{ $ai['positives'] }} berlabel AI){{ $ai['errors'] > 0 ? '; ' . $ai['errors'] . ' dokumen gagal dianalisis dan tidak dihitung' : '' }}.</p>
            <div class="mb-4 grid gap-4 lg:grid-cols-2">
                @include('dosen.evaluation._confusion', ['title' => 'Ambang 0,50 (keputusan penelitian)', 'm' => $ai['sweep']['0.5'], 'positive' => 'AI', 'negative' => 'Manusia'])
                <div class="panel overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Kategori dokumen</th><th>Label</th><th class="text-right">n</th><th class="text-right">Rata-rata probabilitas AI</th></tr></thead>
                        <tbody>
                            @foreach ($ai['perCategory'] as $cat)
                                <tr>
                                    <td class="font-medium">{{ $cat['label'] }}</td>
                                    <td><x-status :tone="$cat['ai_written'] ? 'danger' : 'ok'">{{ $cat['ai_written'] ? 'AI' : 'Manusia' }}</x-status></td>
                                    <td class="num text-right">{{ $cat['n'] }}</td>
                                    <td class="num text-right">{{ $pct($cat['avg_probability'], 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <details class="panel">
                <summary class="panel-head cursor-pointer"><span class="panel-title">Pengaruh ambang deteksi AI</span><span class="text-[13px] text-brand">Tampilkan</span></summary>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Ambang</th><th class="text-right">Dianggap AI</th><th class="text-right">Precision</th><th class="text-right">Recall</th><th class="text-right">F1</th></tr></thead>
                        <tbody>
                            @foreach ($ai['sweep'] as $t => $mm)
                                <tr @class(['bg-brand-soft/40' => $ai['bestThreshold'] !== null && abs((float) $t - $ai['bestThreshold']) < 1e-9])>
                                    <td class="num">{{ $f((float) $t, 2) }}</td>
                                    <td class="num text-right">{{ $mm['tp'] + $mm['fp'] }}</td>
                                    <td class="num text-right">{{ $f($mm['precision']) }}</td>
                                    <td class="num text-right">{{ $f($mm['recall']) }}</td>
                                    <td class="num text-right">{{ $f($mm['f1']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
    @endif

    {{-- ============ KONSISTENSI GABUNGAN ============ --}}
    @if ($r['consistency'] && $r['consistency']['summary']['n'] > 0)
        @php $cs = $r['consistency']; @endphp
        <section class="mb-10">
            <h2 class="text-base font-semibold">Uji konsistensi deteksi AI (gabungan)</h2>
            <p class="mb-3 text-muted">Ulangan dijalankan dari halaman tiap run. Keputusan “teks AI” memakai ambang {{ $f($cs['threshold'], 2) }}.</p>
            @if ($cs['modelMismatch'])
                <p class="alert alert-warn mb-4">Model LLM ulangan ({{ implode(', ', $cs['repeatModels']) }}) berbeda dengan model run ({{ $cs['runModel'] ?? '—' }}), sehingga hasilnya tidak dapat dibandingkan sebagai uji konsistensi.</p>
            @endif
            @include('peneliti.partials.consistency-summary')
        </section>
    @endif
@endif
@endsection
