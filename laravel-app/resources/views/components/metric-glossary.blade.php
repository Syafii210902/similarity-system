{{-- Daftar istilah metrik (isi di App\Support\MetricGuide::TERMS). --}}
<details {{ $attributes->merge(['class' => 'panel']) }}>
    <summary class="panel-head cursor-pointer list-none">
        <span class="panel-title">Istilah dan cara membaca nilai</span>
        <span class="text-[13px] text-brand">Tampilkan</span>
    </summary>
    <dl class="grid gap-x-4 gap-y-2 px-5 py-4 text-[13px] leading-relaxed sm:grid-cols-[190px_1fr]">
        @foreach (\App\Support\MetricGuide::TERMS as $term => $desc)
            <dt class="font-medium">{{ $term }}</dt><dd class="text-muted">{{ $desc }}</dd>
        @endforeach
    </dl>
</details>
