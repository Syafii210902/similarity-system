@extends('layouts.app')

@section('title', $run->name)

@php
    $c = $run->config;
    $m = $run->metrics ?? [];
    $f = fn ($v, $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $pct = fn ($n, $d) => $d > 0 ? number_format($n / $d * 100, 0, ',', '.') . '%' : '—';
    $weightsLabel = fn (array $w) => implode(' / ', array_map(fn ($x) => number_format($x, 2, ',', '.'), $w));
@endphp

@section('content')
<x-page-header :title="$run->name"
    :crumbs="['Lab Pengujian' => route('peneliti.dashboard'), $dataset->name => route('peneliti.datasets.show', $dataset), 'Eksperimen' => null]"
    meta="Ambang {{ $f($c['threshold'], 2) }} · bobot D/P/K {{ $weightsLabel($c['weights']) }} · passage {{ $c['passage_max_words'] }} kata · ambang kalimat {{ $f($c['sentence_match_threshold'], 2) }}">
    <x-slot:actions>
        @if ($run->status === 'completed')
            <a href="{{ route('peneliti.runs.export', $run) }}" class="btn btn-secondary">Unduh CSV</a>
        @endif
        <form method="POST" action="{{ route('peneliti.runs.destroy', $run) }}"
            data-confirm-title="Hapus eksperimen?" data-confirm-label="Hapus" data-confirm-tone="danger"
            data-confirm="Hasil eksperimen “{{ $run->name }}” akan dihapus. Dataset dan dokumennya tetap ada.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Hapus</button>
        </form>
    </x-slot:actions>
</x-page-header>

@if ($dataset->is_sample)
    <p class="alert alert-warn mb-6">Hasil dari korpus contoh (teks buatan AI). Gunakan hanya untuk memahami tampilan, bukan sebagai temuan penelitian.</p>
@endif

@if ($run->isActive())
    <div class="panel px-5 py-10 text-center" data-run-poll="{{ route('peneliti.runs.status', $run) }}">
        <p class="font-medium">{{ $run->statusLabel() }}…</p>
        <p class="mt-1 text-muted">FastAPI sedang menghitung skor semua pasangan{{ ($c['run_tier2'] ?? false) ? ' dan menjalankan LLM' : '' }}. Halaman ini diperbarui otomatis.</p>
    </div>
@elseif ($run->status === 'failed')
    <p class="alert alert-danger">Eksperimen gagal: {{ $run->error_message }}</p>
@else
    {{-- ============ RINGKASAN ============ --}}
    @php
        // Dihitung dari skor tersimpan: tanpa Tahap 1, LLM harus memeriksa semua pasangan.
        $aboveThreshold = $rows->filter(fn ($r) => $r['combined'] >= $runThreshold - 1e-9)->count();
        $filtered = $rows->count() - $aboveThreshold;
    @endphp
    <x-figures class="mb-3" :items="[
        'Pasangan' => $rows->count(),
        'Plagiat (label)' => $rows->where('actual', true)->count(),
        'Di atas ambang' => $aboveThreshold,
        'Panggilan LLM dihemat' => $filtered . ' (' . $pct($filtered, $rows->count()) . ')',
        'Durasi' => $run->durationLabel() ?? '—',
    ]" />
    <p class="mb-1 text-[13px] text-muted">
        Tanpa Tahap 1, LLM harus memeriksa semua {{ $rows->count() }} pasangan. Tahap 1 hanya meneruskan {{ $aboveThreshold }} pasangan
        (skor gabungan ≥ {{ $f($runThreshold, 2) }}), sehingga {{ $filtered }} panggilan tidak diperlukan.
        @if ($tier2)
            Pada run ini LLM dipanggil {{ $m['llm_similarity_calls'] ?? $aboveThreshold }} kali.
        @else
            Tahap 2 tidak dijalankan pada run ini; angka di atas adalah penghematan bila Tahap 2 dijalankan dengan ambang yang sama.
        @endif
    </p>
    <p class="mb-8 text-[13px] text-muted">
        Model embedding {{ $m['embedding_model'] ?? '—' }}
        @if (!empty($m['llm']['model']) && (($c['run_tier2'] ?? false) || ($c['run_ai_detection'] ?? false))) · LLM {{ $m['llm']['model'] }}@if (!empty($m['llm']['effort'])) (effort {{ $m['llm']['effort'] }})@endif @endif
        · ekstraksi {{ $f($m['extraction_seconds'] ?? null, 1) }} dtk · Tahap 1 {{ $f($m['tier1_seconds'] ?? null, 1) }} dtk
        @if ($c['run_tier2'] ?? false) · Tahap 2 {{ $f($m['tier2_similarity_seconds'] ?? null, 1) }} dtk @endif
        @if (($m['llm_usage']['input_tokens'] ?? 0) > 0) · token {{ number_format($m['llm_usage']['input_tokens'], 0, ',', '.') }} masuk / {{ number_format($m['llm_usage']['output_tokens'], 0, ',', '.') }} keluar @endif
        @if (!empty($m['failed_extractions'])) · <span class="text-danger">{{ count($m['failed_extractions']) }} dokumen gagal diekstrak</span> @endif
    </p>

    @php
        $simErrors = (int) ($m['llm_similarity_errors'] ?? 0);
        $simCalls = (int) ($m['llm_similarity_calls'] ?? 0);
        $aiErrors = (int) ($m['ai_detection_errors'] ?? 0);
        $aiCalls = (int) ($m['llm_ai_detection_calls'] ?? 0);
    @endphp
    @if ($simErrors > 0 || $aiErrors > 0)
        <div class="alert alert-danger mb-8">
            <p class="font-medium">Sebagian panggilan LLM gagal, sehingga hasil yang melibatkan LLM tidak valid untuk penelitian.</p>
            <p class="mt-1">
                @if ($simErrors > 0) Tahap 2: {{ $simErrors }} dari {{ $simCalls }} panggilan gagal (pasangan tersebut dianggap “perlu diperiksa manual”, sehingga Tahap 1+2 tampak sama dengan Tahap 1). @endif
                @if ($aiErrors > 0) Deteksi AI: {{ $aiErrors }} dari {{ $aiCalls }} dokumen gagal dianalisis. @endif
                Hasil Tahap 1 (embedding) tetap valid. Penyebab umum: kuota atau rate limit layanan LLM habis. Periksa log FastAPI, lalu jalankan ulang eksperimen.
            </p>
        </div>
    @endif

    {{-- ============ ABLATION ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">Ablation: kontribusi tiap lapis embedding</h2>
        <p class="mb-3 text-muted">Tahap 1 saja. F1 cross-validation 5-fold (stratified, seed {{ \App\Services\ExperimentEvaluator::SEED }}): ambang, dan untuk baris terakhir juga bobot, dipilih di data latih lalu diuji di data uji. Kolom “terbaik” adalah nilai pada data penuh (optimistis).</p>
        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Konfigurasi</th>
                        <th class="text-right">F1 CV<br><span class="font-normal">rata-rata ± sd</span></th>
                        <th class="text-right">Precision CV<br><span class="font-normal">gabungan fold</span></th>
                        <th class="text-right">Recall CV<br><span class="font-normal">gabungan fold</span></th>
                        <th class="text-right">F1 terbaik<br><span class="font-normal">data penuh</span></th>
                        <th>Bobot D/P/K · ambang terbaik</th>
                    </tr>
                </thead>
                <tbody>
                    @php $bestCv = collect($ablation)->max(fn ($a) => $a['cv']['f1_mean'] ?? -1); @endphp
                    @foreach ($ablation as $a)
                        <tr @class(['bg-brand-soft/40' => ($a['cv']['f1_mean'] ?? -2) === $bestCv])>
                            <td class="font-medium">{{ $a['label'] }}</td>
                            <td class="num text-right @if (($a['cv']['f1_mean'] ?? -2) === $bestCv) font-semibold @endif">
                                {{ $f($a['cv']['f1_mean']) }}<span class="text-muted"> ± {{ $f($a['cv']['f1_sd']) }}</span>
                            </td>
                            <td class="num text-right">{{ $f($a['cv']['pooled']['precision']) }}</td>
                            <td class="num text-right">{{ $f($a['cv']['pooled']['recall']) }}</td>
                            <td class="num text-right text-muted">{{ $f($a['in_sample']['f1']) }}</td>
                            <td class="num whitespace-nowrap text-[13px] text-muted">{{ $weightsLabel($a['best_weights']) }} · {{ $f($a['best_threshold'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($rows->where('actual', true)->count() < 15)
            <p class="form-hint">Pasangan plagiat hanya {{ $rows->where('actual', true)->count() }}, sehingga tiap fold berisi sangat sedikit contoh positif dan simpangan baku akan besar. Perbesar korpus untuk hasil yang stabil.</p>
        @endif
    </section>

    {{-- ============ PER KATEGORI ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">Deteksi per jenis plagiat</h2>
        <p class="mb-3 text-muted">Keputusan run: Tahap 1 = skor gabungan ≥ {{ $f($runThreshold, 2) }}{{ $tier2 ? '; Tahap 1+2 = juga dikonfirmasi LLM' : '' }}. Baris “bukan plagiat” menunjukkan salah tuduh (false positive).</p>
        <div class="panel overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kategori pasangan</th><th class="text-right">n</th>
                        <th class="text-right">Ditandai Tahap 1</th>
                        @if ($tier2)<th class="text-right">Ditandai Tahap 1+2</th>@endif
                        <th class="text-right">Rata-rata D</th><th class="text-right">P</th><th class="text-right">K</th><th class="text-right">Gabungan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perCategory as $cat)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $cat['label'] }}</span>
                                @unless ($cat['positive'])<span class="block text-xs text-muted">seharusnya tidak ditandai</span>@endunless
                            </td>
                            <td class="num text-right">{{ $cat['n'] }}</td>
                            <td class="num text-right {{ $cat['positive'] ? '' : ($cat['tier1'] > 0 ? 'text-danger' : '') }}">{{ $cat['tier1'] }} <span class="text-muted">({{ $pct($cat['tier1'], $cat['n']) }})</span></td>
                            @if ($tier2)
                                <td class="num text-right {{ $cat['positive'] ? '' : ($cat['tier12'] > 0 ? 'text-danger' : '') }}">{{ $cat['tier12'] }} <span class="text-muted">({{ $pct($cat['tier12'], $cat['n']) }})</span></td>
                            @endif
                            <td class="num text-right text-muted">{{ $f($cat['avg']['document'], 2) }}</td>
                            <td class="num text-right text-muted">{{ $f($cat['avg']['passage'], 2) }}</td>
                            <td class="num text-right text-muted">{{ $f($cat['avg']['sentence'], 2) }}</td>
                            <td class="num text-right">{{ $f($cat['avg']['combined'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ============ TAHAP 1 vs 1+2 ============ --}}
    <section class="mb-10">
        <h2 class="mb-3 text-base font-semibold">Keputusan run: Tahap 1 vs Tahap 1 + Tahap 2</h2>
        <div class="grid gap-4 lg:grid-cols-2">
            @include('dosen.evaluation._confusion', ['title' => 'Tahap 1 saja (gabungan ≥ ' . $f($runThreshold, 2) . ')', 'm' => $tiers['tier1'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
            @if ($tiers['tier12'])
                @include('dosen.evaluation._confusion', ['title' => 'Tahap 1 + Tahap 2 (dikonfirmasi LLM)', 'm' => $tiers['tier12'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
            @else
                <div class="panel flex items-center justify-center p-6 text-center text-muted">Tahap 2 tidak dijalankan pada eksperimen ini.</div>
            @endif
        </div>
    </section>

    {{-- ============ SWEEP AMBANG ============ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold">F1 terhadap ambang</h2>
        <p class="mb-3 text-muted">Tahap 1 saja, pada data penuh. “Gabungan” memakai bobot run. Garis putus-putus = ambang run.</p>
        <div class="panel p-5">
            <x-f1-chart :sweep="$sweep" :mark-threshold="$runThreshold" :series="['combined' => 'Gabungan', 'document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat']" />
            <details class="mt-4 border-t border-line-soft pt-3">
                <summary class="cursor-pointer text-[13px] text-brand">Tampilkan tabel data</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr><th>Ambang</th><th class="text-right">P gabungan</th><th class="text-right">R gabungan</th><th class="text-right">F1 gabungan</th><th class="text-right">F1 dokumen</th><th class="text-right">F1 passage</th><th class="text-right">F1 kalimat</th></tr>
                        </thead>
                        <tbody>
                            @foreach (array_keys($sweep['combined']) as $t)
                                <tr>
                                    <td class="num">{{ $f((float) $t, 2) }}</td>
                                    <td class="num text-right">{{ $f($sweep['combined'][$t]['precision']) }}</td>
                                    <td class="num text-right">{{ $f($sweep['combined'][$t]['recall']) }}</td>
                                    <td class="num text-right">{{ $f($sweep['combined'][$t]['f1']) }}</td>
                                    <td class="num text-right">{{ $f($sweep['document'][$t]['f1']) }}</td>
                                    <td class="num text-right">{{ $f($sweep['passage'][$t]['f1']) }}</td>
                                    <td class="num text-right">{{ $f($sweep['sentence'][$t]['f1']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </section>

    {{-- ============ COBA BOBOT ============ --}}
    <section id="coba" class="mb-10">
        <h2 class="text-base font-semibold">Coba bobot &amp; ambang lain</h2>
        <p class="mb-3 text-muted">Dihitung ulang dari skor tersimpan, tanpa memanggil FastAPI atau LLM.</p>
        <div class="grid gap-4 lg:grid-cols-[340px_1fr]">
            <form method="GET" action="{{ route('peneliti.runs.show', $run) }}#coba" class="panel space-y-4 p-5">
                <fieldset>
                    <legend class="form-label">Bobot dokumen / passage / kalimat</legend>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['wd' => 0, 'wp' => 1, 'ws' => 2] as $field => $i)
                            <input name="{{ $field }}" type="number" step="0.05" min="0" max="1" value="{{ $tryWeights[$i] }}" class="form-input num" aria-label="{{ $field }}">
                        @endforeach
                    </div>
                </fieldset>
                <div>
                    <label for="t" class="form-label">Ambang</label>
                    <input id="t" name="t" type="number" step="0.01" min="0" max="1" value="{{ $tryThreshold }}" class="form-input num">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">Hitung</button>
                    <a href="{{ route('peneliti.runs.show', $run) }}#coba" class="btn btn-secondary">Kembali ke run</a>
                </div>
            </form>
            @include('dosen.evaluation._confusion', ['title' => 'Bobot ' . $weightsLabel($tryWeights) . ' · ambang ' . $f($tryThreshold, 2), 'm' => $tryResult, 'positive' => 'Plagiat', 'negative' => 'Bukan'])
        </div>
    </section>

    {{-- ============ DETEKSI AI ============ --}}
    @if ($ai)
        <section class="mb-10">
            <h2 class="text-base font-semibold">Deteksi konten AI</h2>
            <p class="mb-3 text-muted">Label: “Ditulis AI” dan “Parafrase oleh AI” = teks AI. {{ $ai['errors'] > 0 ? $ai['errors'] . ' dokumen gagal dianalisis dan tidak dihitung.' : '' }}</p>
            <div class="mb-4 grid gap-4 lg:grid-cols-2">
                @include('dosen.evaluation._confusion', ['title' => 'Ketat: hanya “Kemungkinan AI”', 'm' => $ai['strict'], 'positive' => 'AI', 'negative' => 'Manusia'])
                @include('dosen.evaluation._confusion', ['title' => 'Longgar: “Kemungkinan AI” atau “Campuran”', 'm' => $ai['lenient'], 'positive' => 'AI', 'negative' => 'Manusia'])
            </div>
            <div class="panel overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Kategori dokumen</th><th>Label</th><th class="text-right">n</th><th class="text-right">Rata-rata probabilitas AI</th><th class="text-right">Dinilai “Kemungkinan AI”</th></tr></thead>
                    <tbody>
                        @foreach ($ai['perCategory'] as $cat)
                            <tr>
                                <td class="font-medium">{{ $cat['label'] }}</td>
                                <td><x-status :tone="$cat['ai_written'] ? 'danger' : 'ok'">{{ $cat['ai_written'] ? 'AI' : 'Manusia' }}</x-status></td>
                                <td class="num text-right">{{ $cat['n'] }}</td>
                                <td class="num text-right">{{ $pct($cat['avg_probability'], 1) }}</td>
                                <td class="num text-right">{{ $cat['likely_ai'] }} <span class="text-muted">({{ $pct($cat['likely_ai'], $cat['n']) }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ============ SEMUA PASANGAN ============ --}}
    <section>
        <details class="panel">
            <summary class="panel-head cursor-pointer"><span class="panel-title">Semua pasangan ({{ $rows->count() }})</span><span class="text-[13px] text-brand">Tampilkan</span></summary>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Pasangan</th><th>Kategori</th><th>Label</th><th class="text-right">D</th><th class="text-right">P</th><th class="text-right">K</th><th class="text-right">Gabungan</th><th>LLM</th></tr></thead>
                    <tbody>
                        @foreach ($rows->sortByDesc('combined') as $r)
                            <tr>
                                <td class="text-[13px]"><span class="block">{{ $r['a']->title }}</span><span class="block text-muted">{{ $r['b']->title }}</span></td>
                                <td class="text-[13px] text-muted">{{ \App\Services\ExperimentEvaluator::PAIR_CATEGORIES[$r['category']] }}</td>
                                <td><x-status :tone="$r['actual'] ? 'warn' : 'muted'">{{ $r['actual'] ? 'Plagiat' : 'Bukan' }}</x-status></td>
                                <td class="num text-right">{{ $f($r['scores']['document'], 2) }}</td>
                                <td class="num text-right">{{ $f($r['scores']['passage'], 2) }}</td>
                                <td class="num text-right">{{ $f($r['scores']['sentence'], 2) }}</td>
                                <td class="num text-right font-medium">{{ $f($r['combined'], 3) }}</td>
                                <td class="text-[13px] text-muted">{{ $r['llm_checked'] ? ($r['pair']->llm_action === 'AMAN' ? 'Aman' : 'Plagiat') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>
@endif
@endsection

@push('scripts')
    <script>
        (() => {
            const box = document.querySelector('[data-run-poll]');
            if (!box) return;
            const check = async () => {
                try {
                    const res = await fetch(box.dataset.runPoll, { headers: { Accept: 'application/json' } });
                    const { status } = await res.json();
                    if (!['pending', 'processing'].includes(status)) return window.location.reload();
                } catch (e) { /* coba lagi */ }
                setTimeout(check, 4000);
            };
            setTimeout(check, 4000);
        })();
    </script>
@endpush
