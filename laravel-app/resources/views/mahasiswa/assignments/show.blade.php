@extends('layouts.app')

@section('title', $assignment->title)

@section('content')
@php $course = $assignment->course; @endphp

<x-page-header :title="$assignment->title"
    :crumbs="['Beranda' => route('mahasiswa.dashboard'), $course->code => route('mahasiswa.courses.show', $course), 'Tugas' => null]"
    meta="{{ $course->name }} · {{ $course->dosen->name }}" />

<div class="grid gap-8 lg:grid-cols-[1fr_380px]">
    <section>
        <dl class="mb-6 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
            <dt class="text-muted">Tenggat</dt>
            <dd><x-due-date :assignment="$assignment" /></dd>
            <dt class="text-muted">Pengumpulan</dt>
            <dd>
                <x-assignment-state :assignment="$assignment" />
                @if ($assignment->isClosedManually())
                    <span class="text-[13px] text-muted">oleh dosen, {{ $assignment->closed_at->translatedFormat('d M Y, H:i') }}</span>
                @endif
            </dd>
            <dt class="text-muted">Status Anda</dt>
            <dd><x-submission-status :assignment="$assignment" :submission="$submission" /></dd>
        </dl>

        <h2 class="mb-2 text-sm font-semibold">Deskripsi tugas</h2>
        <div class="max-w-prose whitespace-pre-line text-[15px] leading-relaxed">{{ $assignment->description ?: 'Tidak ada deskripsi.' }}</div>
    </section>

    <section class="panel h-fit">
        <div class="panel-head">
            <h2 class="panel-title">Pengumpulan Anda</h2>
        </div>
        <div class="p-4">
            @if ($submission)
                <table class="w-full text-sm">
                    <tr>
                        <td class="w-28 py-1 align-top text-muted">Berkas</td>
                        <td class="py-1 [overflow-wrap:anywhere]">
                            @if ($fileSize !== null)
                                <a href="{{ route('mahasiswa.submissions.download', $assignment) }}">{{ $submission->file_name }}</a>
                            @else
                                {{ $submission->file_name }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1 text-muted">Ukuran</td>
                        <td class="num py-1 text-[13px]">
                            @if ($fileSize === null)
                                <span class="text-danger">berkas tidak ditemukan</span>
                            @elseif ($fileSize >= 1048576)
                                {{ number_format($fileSize / 1048576, 1, ',', '.') }} MB
                            @else
                                {{ number_format($fileSize / 1024, 1, ',', '.') }} KB
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1 text-muted">Dikirim</td>
                        <td class="num py-1 text-[13px]">{{ $submission->created_at->translatedFormat('d M Y, H:i') }}</td>
                    </tr>
                    @if ($submission->updated_at->gt($submission->created_at))
                        <tr>
                            <td class="py-1 text-muted">Diperbarui</td>
                            <td class="num py-1 text-[13px]">{{ $submission->updated_at->translatedFormat('d M Y, H:i') }}</td>
                        </tr>
                    @endif
                </table>

                @if ($canSubmit)
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-line-soft pt-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-toggle-replace>Ganti berkas</button>
                        <form method="POST" action="{{ route('mahasiswa.submissions.destroy', $assignment) }}"
                            data-confirm-title="Hapus pengumpulan?" data-confirm-label="Hapus" data-confirm-tone="danger"
                            data-confirm="Berkas akan dihapus dan tugas kembali berstatus belum dikumpulkan. Anda masih bisa mengunggah lagi selama pengumpulan dibuka.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus pengumpulan</button>
                        </form>
                    </div>

                    <div id="replace-form" class="mt-4 border-t border-line-soft pt-4 {{ $errors->has('file') ? '' : 'hidden' }}">
                        <x-upload-form :action="route('mahasiswa.submissions.update', $assignment)" method="PUT" submit-label="Simpan berkas baru">
                            <button type="button" class="btn btn-secondary" data-toggle-replace>Batal</button>
                        </x-upload-form>
                    </div>
                @else
                    <p class="mt-4 border-t border-line-soft pt-4 text-[13px] text-muted">
                        Pengumpulan sudah ditutup. Berkas tidak dapat diganti atau dihapus.
                    </p>
                @endif
            @elseif ($canSubmit)
                <x-upload-form :action="route('mahasiswa.submissions.store', $assignment)" />
            @else
                <p class="alert alert-danger">Pengumpulan sudah ditutup dan Anda tidak mengumpulkan tugas ini.</p>
            @endif
        </div>
    </section>
</div>

@push('scripts')
    <script>
        document.querySelectorAll('[data-toggle-replace]').forEach((button) => {
            button.addEventListener('click', () => document.getElementById('replace-form')?.classList.toggle('hidden'));
        });
    </script>
@endpush
@endsection
