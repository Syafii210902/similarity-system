@props(['sweep', 'series', 'markThreshold' => null])

{{--
    Grafik garis F1 vs ambang. $sweep[key][(string) threshold] = hasil confusion();
    $series = [key => label]. Palet kategorikal tervalidasi (slot 1–4, latar terang);
    kontras aqua/kuning < 3:1 sehingga setiap garis diberi label langsung + tersedia tabel data.
--}}
@php
    $colors = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
    $thresholds = array_map('floatval', array_keys(reset($sweep)));
    $w = 640; $h = 280;
    $m = ['l' => 52, 'r' => 120, 't' => 12, 'b' => 34];
    $pw = $w - $m['l'] - $m['r']; $ph = $h - $m['t'] - $m['b'];
    $xMin = min($thresholds); $xMax = max($thresholds);
    $x = fn ($t) => $m['l'] + ($t - $xMin) / max(1e-9, $xMax - $xMin) * $pw;
    $y = fn ($v) => $m['t'] + (1 - $v) * $ph;
    $fmt = fn ($v) => number_format($v, 2, ',', '.');

    $lines = [];
    $i = 0;
    foreach ($series as $key => $label) {
        $points = [];
        foreach ($thresholds as $t) {
            $f1 = $sweep[$key][(string) $t]['f1'] ?? null;
            $points[] = ['t' => $t, 'f1' => $f1];
        }
        // Segmen terputus jika F1 tidak terdefinisi.
        $segments = [];
        $current = [];
        foreach ($points as $p) {
            if ($p['f1'] === null) {
                if (count($current) > 1) { $segments[] = $current; }
                $current = [];
                continue;
            }
            $current[] = round($x($p['t']), 1) . ',' . round($y($p['f1']), 1);
        }
        if (count($current) > 1) { $segments[] = $current; }
        $last = collect($points)->filter(fn ($p) => $p['f1'] !== null)->last();
        $lines[] = ['key' => $key, 'label' => $label, 'color' => $colors[$i++ % 4], 'points' => $points, 'segments' => $segments, 'last' => $last];
    }

    // Label ujung kanan: hindari tumpang tindih dengan jarak minimum 13px.
    $labels = collect($lines)->filter(fn ($l) => $l['last'])->map(fn ($l) => ['label' => $l['label'], 'color' => $l['color'], 'y' => $y($l['last']['f1'])])->sortBy('y')->values()->all();
    for ($k = 1; $k < count($labels); $k++) {
        $labels[$k]['y'] = max($labels[$k]['y'], $labels[$k - 1]['y'] + 13);
    }
@endphp

<figure {{ $attributes->merge(['class' => 'w-full']) }}>
    <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted" aria-hidden="true">
        @foreach ($lines as $line)
            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" style="background: {{ $line['color'] }}"></span>{{ $line['label'] }}</span>
        @endforeach
        @if ($markThreshold !== null)
            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-px bg-faint"></span>ambang run</span>
        @endif
    </div>
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-auto w-full" role="img" aria-label="Grafik F1-score terhadap ambang untuk tiap lapis embedding">
        {{-- grid & sumbu --}}
        @foreach ([0, 0.25, 0.5, 0.75, 1] as $g)
            <line x1="{{ $m['l'] }}" x2="{{ $m['l'] + $pw }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" stroke="#efeff1" stroke-width="1"/>
            <text x="{{ $m['l'] - 8 }}" y="{{ $y($g) + 4 }}" text-anchor="end" font-size="11" fill="#64646e">{{ $fmt($g) }}</text>
        @endforeach
        @foreach ($thresholds as $t)
            @if (fmod(round($t * 100), 10) == 0)
                <text x="{{ $x($t) }}" y="{{ $h - 14 }}" text-anchor="middle" font-size="11" fill="#64646e">{{ $fmt($t) }}</text>
            @endif
        @endforeach
        <text x="{{ $m['l'] + $pw / 2 }}" y="{{ $h - 1 }}" text-anchor="middle" font-size="11" fill="#64646e">Ambang</text>
        <text x="10" y="{{ $m['t'] + $ph / 2 }}" text-anchor="middle" font-size="11" fill="#64646e" transform="rotate(-90 10 {{ $m['t'] + $ph / 2 }})">F1</text>

        @if ($markThreshold !== null && $markThreshold >= $xMin && $markThreshold <= $xMax)
            <line x1="{{ $x($markThreshold) }}" x2="{{ $x($markThreshold) }}" y1="{{ $m['t'] }}" y2="{{ $m['t'] + $ph }}" stroke="#9a9aa3" stroke-width="1" stroke-dasharray="3 3"/>
        @endif

        {{-- garis --}}
        @foreach ($lines as $line)
            @foreach ($line['segments'] as $segment)
                <polyline points="{{ implode(' ', $segment) }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
            @endforeach
        @endforeach

        {{-- titik + area hover yang lebih besar dari penandanya --}}
        @foreach ($lines as $line)
            @foreach ($line['points'] as $p)
                @if ($p['f1'] !== null)
                    <g class="group">
                        <circle cx="{{ $x($p['t']) }}" cy="{{ $y($p['f1']) }}" r="9" fill="transparent"/>
                        <circle cx="{{ $x($p['t']) }}" cy="{{ $y($p['f1']) }}" r="3" fill="{{ $line['color'] }}" stroke="#fff" stroke-width="1.5"/>
                        <title>{{ $line['label'] }} · ambang {{ $fmt($p['t']) }} · F1 {{ number_format($p['f1'], 3, ',', '.') }}</title>
                    </g>
                @endif
            @endforeach
        @endforeach

        {{-- label langsung di ujung garis (teks berwarna tinta, bukan warna seri) --}}
        @foreach ($labels as $label)
            <circle cx="{{ $m['l'] + $pw + 10 }}" cy="{{ $label['y'] }}" r="3" fill="{{ $label['color'] }}"/>
            <text x="{{ $m['l'] + $pw + 17 }}" y="{{ $label['y'] + 4 }}" font-size="11" fill="#17171a">{{ $label['label'] }}</text>
        @endforeach
    </svg>
    <figcaption class="mt-1 text-xs text-muted">Arahkan kursor ke titik untuk melihat nilai. Data lengkap tersedia di tabel di bawah grafik.</figcaption>
</figure>
