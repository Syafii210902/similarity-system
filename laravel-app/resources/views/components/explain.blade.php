@props(['key'])

{{-- Penjelasan bagian evaluasi (isi di App\Support\MetricGuide::SECTIONS), tertutup secara bawaan. --}}
@php $g = \App\Support\MetricGuide::SECTIONS[$key]; @endphp
<details {{ $attributes->merge(['class' => 'group mb-3 rounded-sm border border-line-soft bg-surface']) }}>
    <summary class="cursor-pointer list-none px-4 py-2 text-[13px] text-brand">
        <span class="group-open:hidden">Penjelasan: apa ini dan cara membacanya</span>
        <span class="hidden group-open:inline">Sembunyikan penjelasan</span>
    </summary>
    <dl class="grid gap-x-4 gap-y-2 border-t border-line-soft px-4 py-3 text-[13px] leading-relaxed sm:grid-cols-[150px_1fr]">
        <dt class="font-medium">Apa ini</dt><dd>{{ $g['what'] }}</dd>
        <dt class="font-medium">Menguji apa</dt><dd>{{ $g['tests'] }}</dd>
        <dt class="font-medium">Cara membaca</dt><dd>{{ $g['read'] }}</dd>
        <dt class="font-medium">Rentang nilai</dt><dd>{{ $g['range'] }}</dd>
        <dt class="font-medium">Dari mana datanya</dt><dd>{{ $g['source'] }}</dd>
    </dl>
</details>
