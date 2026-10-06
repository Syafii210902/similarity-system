@extends('layouts.app')

@section('title', 'Uji cepat dua teks')

@php
    $f = fn ($v, $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $input = $input ?? [];
@endphp

@section('content')
<x-page-header title="Uji cepat dua teks"
    :crumbs="['Lab Pengujian' => route('peneliti.dashboard'), 'Uji cepat' => null]"
    meta="Bandingkan dua teks secara langsung. Cocok untuk demonstrasi dan analisis kasus per kasus." />

<form method="POST" action="{{ route('peneliti.compare.run') }}" class="mb-8" data-compare-form>
    @csrf
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (['text_a' => 'Teks A', 'text_b' => 'Teks B'] as $field => $label)
            <div>
                <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                <textarea id="{{ $field }}" name="{{ $field }}" rows="10" required class="form-input text-[13px] leading-relaxed" @error($field) aria-invalid="true" @enderror>{{ old($field, $input[$field] ?? '') }}</textarea>
                @error($field)<p class="form-error">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-4">
        <button type="submit" class="btn btn-primary" data-submit>Bandingkan</button>
        <label class="flex items-center gap-2 text-muted">
            <input type="checkbox" name="run_tier2" value="1" class="h-4 w-4 accent-ink" @checked(old('run_tier2', $input['run_tier2'] ?? false))>
            Sertakan analisis LLM (Tahap 2, memakai kuota)
        </label>
    </div>
</form>

@if (!empty($error))
    <p class="alert alert-danger">{{ $error }}</p>
@elseif ($result)
    @php
        $layers = $result['layer_scores'];
        $w = $result['params']['weights'];
        $llm = $result['llm_analysis'];
    @endphp
    <section class="mb-8 grid gap-4 lg:grid-cols-[1fr_1.3fr]">
        <div class="panel p-5">
            <p class="text-xs text-muted">Skor gabungan (bobot {{ implode(' / ', array_map(fn ($x) => $f($x, 2), $w)) }})</p>
            <p class="num mt-0.5 text-4xl font-semibold tracking-[-0.02em]">{{ $f($result['combined']) }}</p>
            <dl class="mt-4 space-y-2.5">
                @foreach (['document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat (proporsi kalimat hampir identik)'] as $key => $label)
                    <div>
                        <div class="flex justify-between text-[13px]"><dt class="text-muted">{{ $label }}</dt><dd class="num font-medium">{{ $f($layers[$key]) }}</dd></div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-hover"><div class="h-full rounded-full bg-ink" style="width: {{ round(max(0, $layers[$key]) * 100) }}%"></div></div>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 border-t border-line-soft pt-3 text-xs text-muted">
                A: {{ $result['counts']['sentences_a'] }} kalimat, {{ $result['counts']['passages_a'] }} passage ·
                B: {{ $result['counts']['sentences_b'] }} kalimat, {{ $result['counts']['passages_b'] }} passage ·
                Tahap 1 {{ $f($result['timings']['tier1_seconds'] ?? null, 2) }} dtk
                @isset($result['timings']['tier2_seconds']) · Tahap 2 {{ $f($result['timings']['tier2_seconds'], 1) }} dtk @endisset
                @if (($result['llm_usage']['input_tokens'] ?? 0) > 0) · {{ $result['llm_usage']['input_tokens'] }}/{{ $result['llm_usage']['output_tokens'] }} token @endif
            </p>
        </div>

        <div class="panel p-5">
            @if ($llm)
                @php [$actionLabel, $actionTone] = \App\Support\AnalysisLabels::action($llm['action_recommendation'] ?? null); @endphp
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs text-muted">Analisis LLM · {{ $result['llm']['model'] ?? '' }}</p>
                        <p class="mt-0.5 font-semibold">{{ $llm['verdict'] ?? '—' }}</p>
                    </div>
                    <x-status :tone="$actionTone">{{ $actionLabel }}</x-status>
                </div>
                <p class="mt-2 leading-relaxed text-muted">{{ $llm['summary'] ?? '' }}</p>
                @foreach ($llm['matched_segments'] ?? [] as $seg)
                    @php [$typeLabel, $typeTone] = \App\Support\AnalysisLabels::matchType($seg['match_type'] ?? null); @endphp
                    <div class="mt-3 grid gap-2 border-t border-line-soft pt-3 text-[13px] sm:grid-cols-2">
                        <blockquote class="rounded-sm bg-paper px-3 py-2">{{ $seg['text_doc_a'] }}</blockquote>
                        <blockquote class="rounded-sm bg-paper px-3 py-2">{{ $seg['text_doc_b'] }}</blockquote>
                        <x-status :tone="$typeTone" class="w-fit">{{ $typeLabel }}</x-status>
                    </div>
                @endforeach
            @else
                <p class="text-muted">Analisis LLM tidak disertakan. Centang “Sertakan analisis LLM” untuk melihat klasifikasi salinan langsung atau parafrase.</p>
            @endif
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-base font-semibold">Passage paling mirip (bukti untuk Tahap 2)</h2>
        <div class="panel divide-y divide-line-soft">
            @foreach ($result['evidence'] as $ev)
                <div class="grid gap-3 px-5 py-4 text-[13px] md:grid-cols-[4rem_1fr_1fr]">
                    <span class="num font-medium">{{ $f($ev['score'], 2) }}</span>
                    <p class="leading-relaxed">{{ $ev['text_a'] }}</p>
                    <p class="leading-relaxed text-muted">{{ $ev['text_b'] }}</p>
                </div>
            @endforeach
        </div>
    </section>
@endif

@push('scripts')
    <script>
        document.querySelector('[data-compare-form]')?.addEventListener('submit', (e) => {
            const button = e.target.querySelector('[data-submit]');
            button.disabled = true;
            button.textContent = 'Menghitung…';
        });
    </script>
@endpush
@endsection
