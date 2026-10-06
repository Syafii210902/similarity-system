@extends('layouts.app')

@section('title', 'Lab Pengujian')

@section('content')
<x-page-header title="Lab Pengujian" meta="Uji efektivitas metode dengan korpus berlabel, terpisah dari data perkuliahan.">
    <x-slot:actions>
        <a href="{{ route('peneliti.compare') }}" class="btn btn-secondary">Uji cepat dua teks</a>
    </x-slot:actions>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">
    <div class="min-w-0 space-y-6">
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Dataset uji</h2>
                <span class="text-[13px] text-muted">{{ $datasets->count() }} dataset</span>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Nama</th><th class="text-right">Dokumen</th><th class="text-right">Eksperimen</th><th>Dibuat</th></tr></thead>
                    <tbody>
                        @forelse ($datasets as $dataset)
                            <tr>
                                <td>
                                    <a href="{{ route('peneliti.datasets.show', $dataset) }}" class="font-medium">{{ $dataset->name }}</a>
                                    @if ($dataset->is_sample)
                                        <x-status tone="warn" class="ml-1.5">contoh</x-status>
                                    @endif
                                </td>
                                <td class="num text-right">{{ $dataset->documents_count }}</td>
                                <td class="num text-right">{{ $dataset->runs_count }}</td>
                                <td class="num whitespace-nowrap text-[13px] text-muted">{{ $dataset->created_at->translatedFormat('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-muted">Belum ada dataset. Buat dataset baru atau coba korpus contoh.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Eksperimen terbaru</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Eksperimen</th><th>Dataset</th><th>Status</th><th>Waktu</th></tr></thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr>
                                <td><a href="{{ route('peneliti.runs.show', $run) }}" class="font-medium">{{ $run->name }}</a></td>
                                <td class="text-muted">{{ $run->dataset?->name }}</td>
                                <td><x-status :tone="match ($run->status) { 'completed' => 'ok', 'failed' => 'danger', default => 'warn' }">{{ $run->statusLabel() }}</x-status></td>
                                <td class="num whitespace-nowrap text-[13px] text-muted">{{ $run->created_at->translatedFormat('d M, H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-muted">Belum ada eksperimen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @include('peneliti.partials.corpus-guide')
    </div>

    <aside class="h-fit space-y-6">
        <form method="POST" action="{{ route('peneliti.datasets.store') }}" class="panel">
            @csrf
            <div class="panel-head"><h2 class="panel-title">Dataset baru</h2></div>
            <div class="space-y-4 p-5">
                <div>
                    <label for="name" class="form-label">Nama</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="150" class="form-input" placeholder="Mis. Korpus esai etika AI – angkatan 2026" @error('name') aria-invalid="true" @enderror>
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="description" class="form-label">Keterangan <span class="font-normal text-muted">(opsional)</span></label>
                    <textarea id="description" name="description" rows="3" maxlength="2000" class="form-input" placeholder="Sumber dokumen, cara pembuatan parafrase, model AI yang dipakai…">{{ old('description') }}</textarea>
                </div>
                <button type="submit" class="btn btn-primary w-full">Buat dataset</button>
            </div>
        </form>

        <form method="POST" action="{{ route('peneliti.datasets.sample') }}" class="panel p-5"
            data-confirm-title="Buat korpus contoh?" data-confirm-label="Buat contoh"
            data-confirm="Akan dibuat 14 dokumen contoh (2 topik × 7 kategori). Semua teksnya ditulis oleh AI, jadi hanya untuk mencoba alur Lab, bukan data penelitian.">
            @csrf
            <h2 class="panel-title">Belum punya korpus?</h2>
            <p class="mb-4 mt-1 text-[13px] leading-relaxed text-muted">Buat korpus contoh kecil untuk mencoba alur eksperimen dari awal sampai akhir.</p>
            <button type="submit" class="btn btn-secondary w-full">Buat korpus contoh</button>
        </form>
    </aside>
</div>
@endsection
