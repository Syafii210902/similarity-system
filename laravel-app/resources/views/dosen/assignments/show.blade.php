@extends('layouts.app')

@section('title', $assignment->title)

@section('content')
@php
    $course = $assignment->course;
    $missingCount = $rows->where('enrolled', true)->whereNull('submission')->count();
@endphp

<x-page-header :title="$assignment->title"
    :crumbs="['Mata kuliah' => route('dosen.courses.index'), $course->code => route('dosen.courses.show', $course), 'Tugas' => null]"
    meta="{{ $course->name }}">
    <x-slot:actions>
        @if ($assignment->isClosedManually())
            <form method="POST" action="{{ route('dosen.assignments.reopen', $assignment) }}">
                @csrf
                <button type="submit" class="btn btn-secondary">Buka kembali</button>
            </form>
        @elseif ($assignment->isOpen())
            <form method="POST" action="{{ route('dosen.assignments.close', $assignment) }}"
                data-confirm-title="Tutup pengumpulan?" data-confirm-label="Tutup pengumpulan"
                data-confirm="Mahasiswa tidak dapat lagi mengunggah, mengganti, atau menghapus berkas. Anda dapat membukanya kembali kapan saja.">
                @csrf
                <button type="submit" class="btn btn-secondary">Tutup pengumpulan</button>
            </form>
        @endif
        <a href="{{ route('dosen.assignments.edit', $assignment) }}" class="btn btn-secondary">Ubah</a>
        <form method="POST" action="{{ route('dosen.assignments.destroy', $assignment) }}"
            data-confirm-title="Hapus tugas ini?" data-confirm-label="Hapus tugas" data-confirm-tone="danger"
            data-confirm="Semua berkas pengumpulan dan hasil analisisnya ikut terhapus. Tindakan ini tidak dapat dibatalkan.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Hapus</button>
        </form>
    </x-slot:actions>
</x-page-header>

<div class="grid gap-8 lg:grid-cols-[1fr_320px]">
    <div class="min-w-0 space-y-6">
        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-muted">Tenggat</dt>
            <dd><x-due-date :assignment="$assignment" /></dd>
            <dt class="text-muted">Pengumpulan</dt>
            <dd>
                <x-assignment-state :assignment="$assignment" />
                @if ($assignment->isClosedManually())
                    <span class="text-[13px] text-muted">sejak {{ $assignment->closed_at->translatedFormat('d M Y, H:i') }}</span>
                @endif
            </dd>
            <dt class="text-muted">Terkumpul</dt>
            <dd><span class="num">{{ $submittedCount }}</span> dari <span class="num">{{ $enrolledCount }}</span> mahasiswa terdaftar</dd>
        </dl>

        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Rekap pengumpulan</h2>
                @if ($missingCount > 0)
                    <span class="text-[13px] text-muted">{{ $missingCount }} belum mengumpulkan</span>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Mahasiswa</th><th>Berkas</th><th>Waktu kirim</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    <span class="block font-medium">{{ $row['student']->name }}</span>
                                    <span class="block text-[13px] text-muted">
                                        {{ $row['student']->email }}
                                        @unless ($row['enrolled']) · tidak lagi terdaftar @endunless
                                    </span>
                                </td>
                                <td class="[overflow-wrap:anywhere]">
                                    @if ($row['submission'])
                                        <a href="{{ route('dosen.submissions.download', $row['submission']) }}">{{ $row['submission']->file_name }}</a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="num whitespace-nowrap text-[13px]">
                                    @if ($row['submission'])
                                        {{ $row['submission']->updated_at->translatedFormat('d M Y, H:i') }}
                                    @else
                                        <span class="font-sans text-muted">Belum mengumpulkan</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-muted">Belum ada mahasiswa terdaftar di mata kuliah ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($assignment->description)
            <section>
                <h2 class="mb-2 text-sm font-semibold">Deskripsi tugas</h2>
                <div class="max-w-prose whitespace-pre-line text-sm leading-relaxed text-muted">{{ $assignment->description }}</div>
            </section>
        @endif
    </div>

    @php
        $latestRun = $runs->first();
        $fmt = fn ($v) => number_format($v, 2, ',', '.');
    @endphp
    <aside class="h-fit space-y-6">
        <section class="panel" @if ($latestRun?->isActive()) data-analysis-poll="{{ route('dosen.assignments.analysis.status', $assignment) }}" data-run-id="{{ $latestRun->id }}" @endif>
            <div class="panel-head">
                <h2 class="panel-title">Analisis kemiripan &amp; AI</h2>
            </div>
            <div class="space-y-4 p-4 text-sm">
                @if ($latestRun?->isActive())
                    <p>
                        <x-status tone="warn">{{ $latestRun->statusLabel() }}</x-status>
                        sejak {{ ($latestRun->started_at ?? $latestRun->created_at)->translatedFormat('H:i') }}.
                    </p>
                    <p class="text-muted">{{ $latestRun->documents_sent }} berkas sedang dibandingkan. Halaman ini diperbarui sendiri setelah proses selesai.</p>
                @else
                    @if ($latestRun?->status === 'completed')
                        <p>
                            Terakhir dijalankan {{ $latestRun->finished_at->translatedFormat('d M Y, H:i') }}:
                            <span class="num">{{ $latestRun->pairs_evaluated }}</span> pasangan dibandingkan,
                            <span class="num font-medium">{{ $latestRun->pairs_flagged }}</span> melewati ambang {{ $fmt($latestRun->threshold) }}.
                        </p>
                        <a href="{{ route('dosen.assignments.results', $assignment) }}" class="btn btn-primary w-full">Lihat hasil analisis</a>
                    @elseif ($latestRun?->status === 'failed')
                        <p class="alert alert-danger">
                            Analisis terakhir gagal: {{ $latestRun->error_message }}
                        </p>
                    @endif

                    @if ($missingFiles->isNotEmpty())
                        <p class="alert alert-warn">
                            {{ $missingFiles->count() }} berkas tidak ditemukan di penyimpanan dan tidak akan diikutkan:
                            {{ $missingFiles->map(fn ($s) => $s->user?->name ?? $s->file_name)->join(', ') }}.
                        </p>
                    @endif

                    @if ($blockingReason)
                        <p class="text-muted">{{ $blockingReason }}</p>
                    @else
                        <form method="POST" action="{{ route('dosen.assignments.analysis.store', $assignment) }}"
                            @if ($latestRun?->status === 'completed') data-confirm-title="Jalankan ulang analisis?" data-confirm-label="Jalankan ulang" data-confirm="Hasil analisis sebelumnya akan diganti dengan hasil yang baru." @endif>
                            @csrf
                            <p class="mb-3">
                                <span class="num">{{ $readyCount }}</span> berkas,
                                <span class="num">{{ intdiv($readyCount * ($readyCount - 1), 2) }}</span> pasangan akan dibandingkan.
                            </p>
                            <label for="threshold" class="form-label">Ambang batas kemiripan</label>
                            <div class="flex items-center gap-2">
                                <input id="threshold" name="threshold" type="number" step="0.05"
                                    min="{{ config('services.analysis.min_threshold') }}" max="{{ config('services.analysis.max_threshold') }}"
                                    value="{{ old('threshold', number_format($latestRun?->threshold ?? $defaultThreshold, 2, '.', '')) }}"
                                    class="form-input num w-24" @error('threshold') aria-invalid="true" @enderror>
                                <button type="submit" class="btn {{ $latestRun?->status === 'completed' ? 'btn-secondary' : 'btn-primary' }}">{{ $latestRun ? 'Jalankan ulang' : 'Jalankan analisis' }}</button>
                            </div>
                            <p class="form-hint">Pasangan dengan skor cosine di atas nilai ini diperiksa lebih lanjut oleh LLM. Bawaan {{ $fmt($defaultThreshold) }}.</p>
                            @error('threshold')<p class="form-error">{{ $message }}</p>@enderror
                        </form>
                    @endif
                @endif
            </div>
        </section>

        @if ($runs->isNotEmpty())
            <section>
                <h2 class="mb-2 text-sm font-semibold">Riwayat analisis</h2>
                <table class="w-full text-[13px]">
                    <thead class="text-left text-muted">
                        <tr class="border-b border-line"><th class="py-1.5 font-normal">Waktu</th><th class="py-1.5 font-normal">Status</th><th class="py-1.5 text-right font-normal">Ambang</th><th class="py-1.5 text-right font-normal">Ditandai</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr class="border-b border-line-soft" title="Dijalankan oleh {{ $run->triggeredBy?->name ?? '—' }}{{ $run->durationLabel() ? ', durasi ' . $run->durationLabel() : '' }}">
                                <td class="num py-1.5">{{ $run->created_at->translatedFormat('d M, H:i') }}</td>
                                <td class="py-1.5">
                                    <x-status :tone="match ($run->status) { 'failed' => 'danger', 'completed' => 'ok', default => 'warn' }" class="text-[13px]">{{ $run->statusLabel() }}</x-status>
                                </td>
                                <td class="num py-1.5 text-right">{{ $fmt($run->threshold) }}</td>
                                <td class="num py-1.5 text-right">{{ $run->pairs_flagged ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </aside>
</div>
@push('scripts')
    <script>
        // Selama analisis berjalan, cek status tiap 5 detik lalu muat ulang halaman saat selesai.
        (() => {
            const box = document.querySelector('[data-analysis-poll]');
            if (!box) return;
            const check = async () => {
                try {
                    const res = await fetch(box.dataset.analysisPoll, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (String(data.run_id) !== box.dataset.runId || !['pending', 'processing'].includes(data.status)) {
                        window.location.reload();
                        return;
                    }
                } catch (e) { /* coba lagi pada interval berikutnya */ }
                setTimeout(check, 5000);
            };
            setTimeout(check, 5000);
        })();
    </script>
@endpush
@endsection
