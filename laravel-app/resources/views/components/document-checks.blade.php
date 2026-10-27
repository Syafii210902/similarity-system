{{--
    Dokumen yang isinya kemungkinan tidak terbaca utuh (gambar/scan tidak dianalisis, tanpa OCR).
    $checks: metrics['document_checks'] dari FastAPI.
--}}
@props(['checks' => [], 'subject' => 'dokumen'])

@php
    $flagged = collect($checks)->filter(fn ($c) => !empty($c['warning']))
        ->sortBy(fn ($c) => $c['extracted'] ? 1 : 0)
        ->values();
@endphp

@if ($flagged->isNotEmpty())
    <section {{ $attributes->merge(['class' => 'panel']) }}>
        <div class="panel-head">
            <h2 class="panel-title">Perlu diperiksa manual</h2>
            <span class="text-[13px] text-muted">{{ $flagged->count() }} {{ $subject }}</span>
        </div>
        <p class="border-b border-line px-5 py-3 text-[13px] text-muted">
            Sistem hanya membaca teks. Gambar, screenshot, dan halaman hasil scan tidak ikut dianalisis,
            sehingga kemiripan atau tulisan AI di bagian tersebut tidak terdeteksi.
        </p>
        <ul class="divide-y divide-line">
            @foreach ($flagged as $check)
                <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-1 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $check['name'] ?? '—' }}</p>
                        <p class="text-[13px] text-muted">{{ $check['warning'] }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-[13px] text-muted">
                        <span class="num">
                            {{ number_format((int) ($check['words'] ?? 0), 0, ',', '.') }} kata
                            @if (!empty($check['pages'])) · {{ $check['pages'] }} hlm @endif
                            @if (!empty($check['images'])) · {{ $check['images'] }} gambar @endif
                        </span>
                        @if ($check['extracted'])
                            <x-status tone="warn">Sebagian terbaca</x-status>
                        @else
                            <x-status tone="danger">Tidak dianalisis</x-status>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
