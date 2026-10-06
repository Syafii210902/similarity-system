@extends('layouts.app')

@use('App\Support\AnalysisLabels', 'L')

@php
    $course = $assignment->course;
    $nameA = $result->submissionA?->user?->name ?? 'Dokumen A';
    $nameB = $result->submissionB?->user?->name ?? 'Dokumen B';
    $segments = collect($result->matched_segments ?? []);
    $counts = $result->segmentCounts();
    [$actionLabel, $actionTone] = L::action($result->action_recommendation);
@endphp

@section('title', $nameA . ' × ' . $nameB)

@section('content')
<x-page-header :title="$nameA . ' × ' . $nameB"
    :crumbs="['Mata kuliah' => route('dosen.courses.index'), $course->code => route('dosen.courses.show', $course), $assignment->title => route('dosen.assignments.show', $assignment), 'Hasil' => route('dosen.assignments.results', $assignment), 'Pasangan' => null]">
    <x-slot:actions>
        <a href="{{ route('dosen.assignments.results', $assignment) }}" class="btn btn-secondary">Semua hasil</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-8 grid gap-4 lg:grid-cols-[1fr_1fr_1.4fr]">
    @foreach ([[$result->submissionA, $nameA, 'A'], [$result->submissionB, $nameB, 'B']] as [$submission, $name, $side])
        @php
            $ai = $submission ? $aiBySubmission->get($submission->id) : null;
            [$aiLabel, $aiTone] = L::aiVerdict($ai?->verdict);
        @endphp
        <div class="panel p-5">
            <p class="text-xs text-muted">Dokumen {{ $side }}</p>
            <p class="mt-0.5 font-semibold">{{ $name }}</p>
            @if ($submission)
                <a href="{{ route('dosen.submissions.download', $submission) }}" class="mt-1 inline-block text-[13px] [overflow-wrap:anywhere]">{{ $submission->file_name }}</a>
            @endif
            <div class="mt-3 flex items-center gap-2 text-[13px] text-muted">
                Deteksi AI:
                @if ($ai)
                    <x-status :tone="$aiTone">{{ $aiLabel }}</x-status>
                    @if ($ai->ai_probability !== null)<span class="num">{{ L::percent($ai->ai_probability) }}</span>@endif
                @else
                    <span>—</span>
                @endif
            </div>
        </div>
    @endforeach

    <div class="panel p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs text-muted">{{ $result->layer_scores ? 'Skor gabungan (Tahap 1)' : 'Skor cosine (Tahap 1)' }}</p>
                <p class="num mt-0.5 text-3xl font-semibold tracking-[-0.02em]">{{ L::score($result->similarity_score) }}</p>
            </div>
            <x-status :tone="$actionTone">{{ $actionLabel }}</x-status>
        </div>
        @if ($result->layer_scores)
            <dl class="mt-3 grid grid-cols-3 gap-2 border-y border-line-soft py-2.5 text-[13px]">
                @foreach (['document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat'] as $layer => $layerLabel)
                    <div>
                        <dt class="text-xs text-muted">{{ $layerLabel }}</dt>
                        <dd class="num font-medium">{{ L::score($result->layer_scores[$layer] ?? null) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        <p class="mt-3 font-medium">{{ $result->verdict }}</p>
        @if ($result->summary)
            <p class="mt-1 leading-relaxed text-muted">{{ $result->summary }}</p>
        @endif
    </div>
</div>

<section class="panel mb-8">
    <div class="panel-head">
        <h2 class="panel-title">Validasi dosen</h2>
        @if ($label)
            <span class="text-[13px] text-muted">
                Ditandai <span class="font-medium text-ink">{{ $label->is_plagiarism ? 'plagiat' : 'bukan plagiat' }}</span>
                oleh {{ $label->labeledBy?->name ?? '—' }}, {{ $label->updated_at->translatedFormat('d M Y, H:i') }}
            </span>
        @endif
    </div>
    <form method="POST" action="{{ route('dosen.assignments.labels.pair', $assignment) }}" class="space-y-3 p-5">
        @csrf
        <input type="hidden" name="submission_a_id" value="{{ $result->submission_a_id }}">
        <input type="hidden" name="submission_b_id" value="{{ $result->submission_b_id }}">
        <p class="text-muted">Setelah membaca kedua berkas, tentukan apakah pasangan ini benar plagiat. Keputusan Anda menjadi data acuan untuk mengukur akurasi sistem.</p>
        <div>
            <label for="note" class="form-label">Catatan <span class="font-normal text-muted">(opsional)</span></label>
            <textarea id="note" name="note" rows="2" maxlength="1000" class="form-input" placeholder="Mis. bagian metodologi disalin dari dokumen A">{{ old('note', $label?->note) }}</textarea>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" name="label" value="plagiarism" @class(['btn', 'btn-primary' => $label?->is_plagiarism === true, 'btn-secondary' => $label?->is_plagiarism !== true])>Plagiat</button>
            <button type="submit" name="label" value="not_plagiarism" @class(['btn', 'btn-primary' => $label?->is_plagiarism === false, 'btn-secondary' => $label?->is_plagiarism !== false])>Bukan plagiat</button>
            @if ($label)
                <button type="submit" name="label" value="clear" class="btn btn-secondary text-muted">Hapus validasi</button>
            @endif
        </div>
    </form>
</section>

<section>
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold">Segmen yang mirip</h2>
            <p class="text-muted">
                Ditemukan oleh LLM (Tahap 2):
                <span class="num">{{ $counts['VERBATIM_COPY'] }}</span> salinan langsung,
                <span class="num">{{ $counts['PARAPHRASED'] }}</span> parafrase.
            </p>
        </div>
        @if ($counts['VERBATIM_COPY'] > 0 && $counts['PARAPHRASED'] > 0)
            <div class="inline-flex rounded-sm border border-line bg-surface p-0.5 text-[13px]" data-segment-filter>
                <button type="button" class="rounded-[4px] bg-hover px-3 py-1 font-medium" data-type="">Semua</button>
                <button type="button" class="rounded-[4px] px-3 py-1 text-muted" data-type="VERBATIM_COPY">Salinan langsung</button>
                <button type="button" class="rounded-[4px] px-3 py-1 text-muted" data-type="PARAPHRASED">Parafrase</button>
            </div>
        @endif
    </div>

    @if ($segments->isEmpty())
        <div class="panel px-5 py-8 text-center text-muted">LLM tidak mengembalikan segmen spesifik untuk pasangan ini.</div>
    @else
        <div class="panel divide-y divide-line-soft">
            <div class="hidden grid-cols-[2.5rem_1fr_1fr] gap-4 px-5 py-2.5 text-xs font-medium text-muted md:grid">
                <span>#</span><span>{{ $nameA }}</span><span>{{ $nameB }}</span>
            </div>
            @foreach ($segments as $segment)
                @php [$typeLabel, $typeTone] = L::matchType($segment['match_type'] ?? null); @endphp
                <div class="grid gap-3 px-5 py-4 md:grid-cols-[2.5rem_1fr_1fr] md:gap-4" data-segment-type="{{ $segment['match_type'] ?? 'PARAPHRASED' }}">
                    <div class="flex items-center gap-2 md:block">
                        <span class="num text-muted">{{ $loop->iteration }}</span>
                        <x-status :tone="$typeTone" class="md:hidden">{{ $typeLabel }}</x-status>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-muted md:hidden">{{ $nameA }}</p>
                        <blockquote class="rounded-sm bg-paper px-3 py-2.5 leading-relaxed">{{ $segment['text_doc_a'] ?: '—' }}</blockquote>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-muted md:hidden">{{ $nameB }}</p>
                        <blockquote class="rounded-sm bg-paper px-3 py-2.5 leading-relaxed">{{ $segment['text_doc_b'] ?: '—' }}</blockquote>
                        <x-status :tone="$typeTone" class="mt-2 hidden md:inline-flex">{{ $typeLabel }}</x-status>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <p class="mt-4 text-[13px] text-muted">
        Catatan: LLM menerima beberapa pasangan passage dengan kemiripan tertinggi dari seluruh isi dokumen (hasil Tahap 1), bukan seluruh teks. Hasil analisis sebelum metode multi-lapis hanya memakai bagian awal dokumen.
    </p>
</section>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-segment-filter] [data-type]').forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelectorAll('[data-segment-filter] [data-type]').forEach((b) => {
                    const active = b === button;
                    b.classList.toggle('bg-hover', active);
                    b.classList.toggle('font-medium', active);
                    b.classList.toggle('text-muted', !active);
                });
                document.querySelectorAll('[data-segment-type]').forEach((row) => {
                    row.hidden = button.dataset.type !== '' && row.dataset.segmentType !== button.dataset.type;
                });
            });
        });
    </script>
@endpush
