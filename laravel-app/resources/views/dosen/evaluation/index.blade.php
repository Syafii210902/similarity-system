@extends('layouts.app')

@section('title', 'Evaluasi')

@php
    $s = $summary;
    $fmt = fn ($v) => $v === null ? '—' : number_format($v, 3, ',', '.');
    $query = $assignment ? ['assignment' => $assignment->id] : [];
    $defaultThreshold = (string) round(config('services.analysis.default_threshold'), 2);
@endphp

@section('content')
<x-page-header title="Evaluasi metode"
    meta="Membandingkan keputusan sistem dengan validasi manual Anda (ground truth).">
    <x-slot:actions>
        <form method="GET" action="{{ route('dosen.evaluation') }}">
            <label for="assignment" class="sr-only">Cakupan</label>
            <select id="assignment" name="assignment" class="form-input py-1.5 pr-8" onchange="this.form.submit()">
                <option value="">Semua tugas saya</option>
                @foreach ($assignments as $option)
                    <option value="{{ $option->id }}" @selected($assignment?->id === $option->id)>{{ $option->course->code }} · {{ $option->title }}</option>
                @endforeach
            </select>
        </form>
    </x-slot:actions>
</x-page-header>

<x-figures class="mb-3" :items="[
    'Pasangan divalidasi' => $s['pairCount'],
    'Di antaranya plagiat' => $s['pairPositives'],
    'Dokumen divalidasi (AI)' => $s['aiCount'],
    'Di antaranya AI' => $s['aiPositives'],
]" />
<p class="mb-8 text-[13px] text-muted">
    Belum divalidasi: {{ $s['unlabeledPairs'] }} pasangan dan {{ $s['unlabeledAi'] }} dokumen.
    Validasi dilakukan di halaman <span class="font-medium text-ink">Hasil analisis</span> tiap tugas.
    @if ($s['aiErrors'] > 0)
        {{ $s['aiErrors'] }} dokumen berlabel dengan hasil deteksi AI gagal tidak dihitung.
    @endif
</p>

@if ($s['pairCount'] === 0 && $s['aiCount'] === 0)
    <div class="panel px-5 py-10 text-center">
        <p class="font-medium">Belum ada data validasi.</p>
        <p class="mt-1 text-muted">Buka hasil analisis sebuah tugas, lalu tandai pasangan sebagai “Plagiat” atau “Bukan”, dan dokumen sebagai “AI” atau “Manusia”.</p>
    </div>
@else
    @if ($s['pairCount'] > 0 && $s['pairCount'] < 10)
        <p class="alert alert-warn mb-6">Baru {{ $s['pairCount'] }} pasangan divalidasi. Metrik akan lebih bermakna setelah lebih banyak pasangan (termasuk yang di bawah ambang) divalidasi.</p>
    @endif

    {{-- ============ KEMIRIPAN ============ --}}
    <section class="mb-10">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Deteksi kemiripan</h2>
                <p class="text-muted">Positif = pasangan dianggap plagiat.</p>
            </div>
            <a href="{{ route('dosen.evaluation.export', ['type' => 'pairs'] + $query) }}" class="btn btn-secondary btn-sm">Unduh CSV pasangan</a>
        </div>

        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            @include('dosen.evaluation._confusion', ['title' => 'Tahap 1 saja (skor gabungan ≥ ambang)', 'm' => $s['tier1'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
            @include('dosen.evaluation._confusion', ['title' => 'Tahap 1 + Tahap 2 (dikonfirmasi LLM)', 'm' => $s['tier12'], 'positive' => 'Plagiat', 'negative' => 'Bukan'])
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3 class="panel-title">Pengaruh ambang batas per lapis embedding</h3>
                <span class="text-xs text-muted">Nilai F1 tertinggi tiap kolom ditebalkan</span>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ambang</th>
                            <th class="text-right">Precision<br><span class="font-normal">gabungan</span></th>
                            <th class="text-right">Recall<br><span class="font-normal">gabungan</span></th>
                            @foreach ($layers as $layer => $layerLabel)
                                <th class="text-right">F1<br><span class="font-normal">{{ strtolower($layerLabel) }}</span></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($s['thresholds'] as $t)
                            @php $key = (string) $t; $combined = $s['sweep']['combined'][$key]; @endphp
                            <tr @class(['bg-brand-soft/40' => $key === $defaultThreshold])>
                                <td class="num">
                                    {{ number_format($t, 2, ',', '.') }}
                                    @if ($key === $defaultThreshold)<span class="ml-1 text-xs text-muted">bawaan</span>@endif
                                </td>
                                <td class="num text-right">{{ $fmt($combined['precision']) }}</td>
                                <td class="num text-right">{{ $fmt($combined['recall']) }}</td>
                                @foreach (array_keys($layers) as $layer)
                                    @php $m = $s['sweep'][$layer][$key]; @endphp
                                    <td @class(['num text-right', 'font-semibold text-ink' => $s['best'][$layer] === $key, 'text-muted' => $s['best'][$layer] !== $key])>
                                        {{ $fmt($m['f1']) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line-soft px-5 py-2.5 text-xs text-muted">
                Sweep memakai skor Tahap 1 saja. Hasil analisis lama (sebelum metode multi-lapis) hanya memiliki skor gabungan, sehingga tidak ikut dihitung pada kolom per lapis.
            </p>
        </div>
    </section>

    {{-- ============ DETEKSI AI ============ --}}
    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Deteksi konten AI</h2>
                <p class="text-muted">Positif = dokumen dianggap ditulis AI.</p>
            </div>
            <a href="{{ route('dosen.evaluation.export', ['type' => 'ai'] + $query) }}" class="btn btn-secondary btn-sm">Unduh CSV deteksi AI</a>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
            @include('dosen.evaluation._confusion', ['title' => 'Ketat: hanya “Kemungkinan AI”', 'm' => $s['aiStrict'], 'positive' => 'AI', 'negative' => 'Manusia'])
            @include('dosen.evaluation._confusion', ['title' => 'Longgar: “Kemungkinan AI” atau “Campuran”', 'm' => $s['aiLenient'], 'positive' => 'AI', 'negative' => 'Manusia'])
        </div>
    </section>
@endif
@endsection
