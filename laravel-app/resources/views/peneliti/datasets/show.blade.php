@extends('layouts.app')

@section('title', $dataset->name)

@use('App\Models\ExperimentDocument')

@php
    $derived = ExperimentDocument::DERIVED;
@endphp

@section('content')
<x-page-header :title="$dataset->name"
    :crumbs="['Lab Pengujian' => route('peneliti.dashboard'), 'Dataset' => null]"
    :meta="$dataset->description">
    <x-slot:actions>
        <form method="POST" action="{{ route('peneliti.datasets.destroy', $dataset) }}"
            data-confirm-title="Hapus dataset ini?" data-confirm-label="Hapus dataset" data-confirm-tone="danger"
            data-confirm="Semua dokumen dan hasil eksperimen pada dataset ini ikut terhapus. Tindakan ini tidak dapat dibatalkan.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Hapus dataset</button>
        </form>
    </x-slot:actions>
</x-page-header>

@if ($dataset->is_sample)
    <p class="alert alert-warn mb-6">Ini korpus contoh. Semua teksnya ditulis oleh AI, termasuk yang berlabel “asli” dan “parafrase manual”, sehingga hasilnya <strong>tidak boleh</strong> dipakai sebagai data penelitian.</p>
@endif

<x-figures class="mb-8" :items="[
    'Dokumen' => $documents->count(),
    'Pasangan dibandingkan' => $totalPairs,
    'Pasangan plagiat (label)' => $positivePairs,
    'Dokumen AI (label)' => $documents->filter->isAiWritten()->count(),
]" />

{{-- ============ DOKUMEN ============ --}}
<section id="dokumen" class="mb-10 grid gap-6 xl:grid-cols-[1fr_360px]">
    <div class="panel h-fit min-w-0">
        <div class="panel-head">
            <h2 class="panel-title">Dokumen berlabel</h2>
            <span class="text-[13px] text-muted">
                @foreach (ExperimentDocument::CATEGORIES as $key => $label)
                    @if ($categoryCounts->get($key)){{ $label }} {{ $categoryCounts->get($key) }}@if (!$loop->last) · @endif @endif
                @endforeach
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Judul</th><th>Kategori</th><th>Sumber</th><th class="text-right">Kata</th><th></th></tr></thead>
                <tbody>
                    @forelse ($documents as $doc)
                        <tr>
                            <td class="min-w-48"><a href="{{ route('peneliti.documents.show', $doc) }}" class="font-medium">{{ $doc->title }}</a></td>
                            <td><x-status :tone="match (true) { $doc->category === 'ORIGINAL' => 'neutral', $doc->isDerived() => 'warn', $doc->category === 'AI_GENERATED' => 'danger', default => 'muted' }">{{ $doc->categoryLabel() }}</x-status></td>
                            <td class="text-[13px] text-muted">{{ $doc->source?->title ?? '—' }}</td>
                            <td class="num text-right text-[13px]">{{ $doc->word_count ?? '—' }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('peneliti.documents.destroy', $doc) }}"
                                    data-confirm-title="Hapus dokumen?" data-confirm-label="Hapus" data-confirm-tone="danger"
                                    data-confirm="“{{ $doc->title }}” akan dihapus. Dokumen turunannya kehilangan sumber dan tidak lagi dihitung sebagai pasangan plagiat.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-[13px] text-danger hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-muted">Belum ada dokumen.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('peneliti.documents.store', $dataset) }}" enctype="multipart/form-data" class="panel h-fit" data-document-form>
        @csrf
        <div class="panel-head"><h2 class="panel-title">Tambah dokumen</h2></div>
        <div class="space-y-4 p-5">
            <div>
                <label for="title" class="form-label">Judul</label>
                <input id="title" name="title" value="{{ old('title') }}" required maxlength="200" class="form-input" placeholder="Mis. Esai 07 – parafrase relawan B" @error('title') aria-invalid="true" @enderror>
                @error('title')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="category" class="form-label">Kategori</label>
                <select id="category" name="category" class="form-input" data-category>
                    @foreach (ExperimentDocument::CATEGORIES as $key => $label)
                        <option value="{{ $key }}" @selected(old('category', 'ORIGINAL') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div data-source-field @class(['hidden' => !in_array(old('category'), $derived, true)])>
                <label for="source_document_id" class="form-label">Dokumen sumber</label>
                <select id="source_document_id" name="source_document_id" class="form-input" @error('source_document_id') aria-invalid="true" @enderror>
                    <option value="">— pilih —</option>
                    @foreach ($documents as $doc)
                        <option value="{{ $doc->id }}" @selected((int) old('source_document_id') === $doc->id)>{{ $doc->title }}</option>
                    @endforeach
                </select>
                <p class="form-hint">Dokumen yang disalin atau diparafrasekan.</p>
                @error('source_document_id')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="text" class="form-label">Tempel teks</label>
                <textarea id="text" name="text" rows="6" class="form-input text-[13px]" placeholder="Tempel isi dokumen di sini…" @error('text') aria-invalid="true" @enderror>{{ old('text') }}</textarea>
                @error('text')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="file" class="form-label">atau unggah berkas <span class="font-normal text-muted">(PDF, DOCX, TXT · maks. 10 MB)</span></label>
                <input id="file" name="file" type="file" accept=".pdf,.docx,.txt" class="block w-full text-[13px] text-muted file:mr-3 file:rounded-sm file:border file:border-line file:bg-surface file:px-3 file:py-1.5 file:text-ink hover:file:bg-hover">
                @error('file')<p class="form-error">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn btn-primary w-full">Tambah dokumen</button>
        </div>
    </form>
</section>

{{-- ============ EKSPERIMEN ============ --}}
<section id="eksperimen" class="grid gap-6 xl:grid-cols-[1fr_360px]">
    <div class="panel h-fit min-w-0">
        <div class="panel-head"><h2 class="panel-title">Eksperimen</h2></div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Nama</th><th>Status</th><th>Ambang</th><th>Bobot D/P/K</th><th>Tahap 2</th><th>Deteksi AI</th><th>Durasi</th></tr></thead>
                <tbody>
                    @forelse ($runs as $run)
                        @php $c = $run->config; @endphp
                        <tr>
                            <td class="min-w-52"><a href="{{ route('peneliti.runs.show', $run) }}" class="font-medium">{{ $run->name }}</a>
                                <span class="block text-xs text-muted">{{ $run->created_at->translatedFormat('d M Y, H:i') }}</span></td>
                            <td><x-status :tone="match ($run->status) { 'completed' => 'ok', 'failed' => 'danger', default => 'warn' }">{{ $run->statusLabel() }}</x-status></td>
                            <td class="num">{{ number_format($c['threshold'] ?? 0, 2, ',', '.') }}</td>
                            <td class="num whitespace-nowrap text-[13px]">{{ implode(' / ', array_map(fn ($w) => number_format($w, 2, ',', '.'), $c['weights'] ?? [])) }}</td>
                            <td class="text-[13px]">{{ ($c['run_tier2'] ?? false) ? 'Ya' : 'Tidak' }}</td>
                            <td class="text-[13px]">{{ ($c['run_ai_detection'] ?? false) ? 'Ya' : 'Tidak' }}</td>
                            <td class="num text-[13px] text-muted">{{ $run->durationLabel() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-8 text-center text-muted">Belum ada eksperimen pada dataset ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('peneliti.runs.store', $dataset) }}" class="panel h-fit">
        @csrf
        <div class="panel-head"><h2 class="panel-title">Jalankan eksperimen</h2></div>
        <div class="space-y-4 p-5">
            @if ($activeRun)
                <p class="alert alert-warn">Eksperimen “{{ $activeRun->name }}” masih berjalan. <a href="{{ route('peneliti.runs.show', $activeRun) }}">Lihat status</a></p>
            @elseif ($documents->count() < 2)
                <p class="text-muted">Tambahkan minimal 2 dokumen untuk menjalankan eksperimen.</p>
            @endif
            <div>
                <label for="run-name" class="form-label">Nama eksperimen</label>
                <input id="run-name" name="name" value="{{ old('name', 'Eksperimen ' . ($runs->count() + 1)) }}" required maxlength="150" class="form-input">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="threshold" class="form-label">Ambang Tahap 2</label>
                    <input id="threshold" name="threshold" type="number" step="0.05" min="0.3" max="0.95" value="{{ old('threshold', number_format($defaults['threshold'], 2, '.', '')) }}" class="form-input num">
                </div>
                <div>
                    <label for="sentence_match_threshold" class="form-label">Ambang kalimat</label>
                    <input id="sentence_match_threshold" name="sentence_match_threshold" type="number" step="0.01" min="0.5" max="1" value="{{ old('sentence_match_threshold', $defaults['sentence_match_threshold']) }}" class="form-input num">
                </div>
            </div>
            <fieldset>
                <legend class="form-label">Bobot lapis (dokumen / passage / kalimat)</legend>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['weight_document' => 0, 'weight_passage' => 1, 'weight_sentence' => 2] as $field => $index)
                        <input name="{{ $field }}" type="number" step="0.05" min="0" max="1" value="{{ old($field, $defaults['weights'][$index]) }}" class="form-input num" aria-label="{{ $field }}">
                    @endforeach
                </div>
                @error('weight_document')<p class="form-error">{{ $message }}</p>@enderror
                <p class="form-hint">Bobot hanya menentukan pasangan mana yang dikirim ke LLM. Skor tiap lapis tetap disimpan, sehingga bobot lain dapat dicoba nanti tanpa menjalankan ulang.</p>
            </fieldset>
            <div>
                <label for="passage_max_words" class="form-label">Panjang passage (kata)</label>
                <input id="passage_max_words" name="passage_max_words" type="number" min="20" max="100" value="{{ old('passage_max_words', $defaults['passage_max_words']) }}" class="form-input num">
            </div>
            <div class="space-y-2 border-t border-line-soft pt-4">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="run_tier2" value="1" class="mt-0.5 h-4 w-4 accent-ink" @checked(old('run_tier2'))>
                    <span>Jalankan Tahap 2 (LLM) untuk pasangan di atas ambang<span class="block text-xs text-muted">Memakai kuota LLM.</span></span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="run_ai_detection" value="1" class="mt-0.5 h-4 w-4 accent-ink" @checked(old('run_ai_detection'))>
                    <span>Jalankan deteksi AI per dokumen<span class="block text-xs text-muted">1 panggilan LLM per dokumen ({{ $documents->count() }} panggilan).</span></span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary w-full" @disabled($activeRun || $documents->count() < 2)>Jalankan eksperimen</button>
        </div>
    </form>
</section>

@push('scripts')
    <script>
        (() => {
            const category = document.querySelector('[data-category]');
            const source = document.querySelector('[data-source-field]');
            const derived = @json($derived);
            const sync = () => source.classList.toggle('hidden', !derived.includes(category.value));
            category?.addEventListener('change', sync);
        })();
    </script>
@endpush
@endsection
