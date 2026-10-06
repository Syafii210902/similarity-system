@extends('layouts.app')

@section('title', 'Beranda Admin')

@section('content')
<x-page-header title="Beranda">
    <x-slot:actions>
        <span class="text-sm text-muted">Layanan analisis:</span>
        <x-status :tone="$engineOnline ? 'ok' : 'danger'">{{ $engineOnline ? 'terhubung' : 'tidak terhubung' }}</x-status>
    </x-slot:actions>
</x-page-header>

<div class="space-y-8">
    <section>
        <h2 class="mb-2 text-sm font-semibold">Pengguna &amp; akademik</h2>
        <x-figures :items="[
            'Admin' => $stats['admin'],
            'Dosen' => $stats['dosen'],
            'Mahasiswa' => $stats['mahasiswa'],
            'Mata kuliah' => $stats['courses'],
            'Tugas' => $stats['assignments'],
            'Pengumpulan' => $stats['submissions'],
        ]" />
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold">Hasil analisis</h2>
        <x-figures :items="[
            'Pasangan dibandingkan' => $stats['pairs_evaluated'],
            'Pasangan ditandai' => $stats['pairs_flagged'],
            'Dokumen terindikasi AI' => $stats['likely_ai'],
        ]" />
        <p class="form-hint">Pasangan ditandai: skor kemiripan Tahap 1 di atas ambang batas, lalu diperiksa LLM.</p>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Pengguna terbaru</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr><th>Nama</th><th>Email</th><th>Peran</th><th>Terdaftar</th></tr>
                </thead>
                <tbody>
                    @forelse ($recentUsers as $u)
                        <tr>
                            <td class="font-medium">{{ $u->name }}</td>
                            <td class="text-muted">{{ $u->email }}</td>
                            <td class="capitalize">{{ $u->role }}</td>
                            <td class="num text-[13px] text-muted">{{ $u->created_at?->translatedFormat('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-muted">Belum ada pengguna.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
