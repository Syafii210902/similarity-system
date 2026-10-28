{{-- Ringkasan uji konsistensi deteksi AI. Butuh: $cs (ExperimentEvaluator::aiConsistency / aiConsistencyFor), $f, $pct. --}}
<x-figures class="mb-4" :items="[
    'Dokumen dibandingkan' => $cs['summary']['n'] . ' (' . $cs['complete'] . ' lengkap ' . $cs['target'] . '×)',
    'Rata-rata simpangan baku' => $f($cs['summary']['mean_sd']),
    'Rentang terbesar' => $f($cs['summary']['max_range']),
    'Verdict tidak berubah' => $cs['summary']['verdict_same'] . ' (' . $pct($cs['summary']['verdict_same'], $cs['summary']['n']) . ')',
    'Keputusan AI tidak berubah' => $cs['summary']['decision_same'] . ' (' . $pct($cs['summary']['decision_same'], $cs['summary']['n']) . ')',
]" />

<div class="mb-4 grid gap-4 lg:grid-cols-2">
    <div class="panel overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Ulangan</th><th class="text-right">n</th><th class="text-right">Precision</th><th class="text-right">Recall</th><th class="text-right">F1</th></tr></thead>
            <tbody>
                @foreach ($cs['perRepeat'] as $repeat => $mm)
                    <tr>
                        <td>Ke-{{ $repeat }}{{ $repeat === 1 ? ' (run)' : '' }}</td>
                        @if ($mm)
                            <td class="num text-right">{{ $mm['n'] }}</td>
                            <td class="num text-right">{{ $f($mm['precision']) }}</td>
                            <td class="num text-right">{{ $f($mm['recall']) }}</td>
                            <td class="num text-right">{{ $f($mm['f1']) }}</td>
                        @else
                            <td colspan="4" class="text-muted">belum ada</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            @if ($cs['f1_sd'] !== null)
                <tfoot><tr><td colspan="4" class="text-muted">F1 rata-rata ± sd</td><td class="num text-right font-semibold">{{ $f($cs['f1_mean']) }}<span class="font-normal text-muted"> ± {{ $f($cs['f1_sd']) }}</span></td></tr></tfoot>
            @endif
        </table>
    </div>
    <div class="panel overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Kategori</th><th class="text-right">n</th><th class="text-right">Rata-rata sd</th><th class="text-right">Verdict sama</th><th class="text-right">Keputusan sama</th></tr></thead>
            <tbody>
                @foreach ($cs['perCategory'] as $cat)
                    <tr>
                        <td class="font-medium">{{ $cat['label'] }}</td>
                        <td class="num text-right">{{ $cat['n'] }}</td>
                        <td class="num text-right">{{ $f($cat['mean_sd']) }}</td>
                        <td class="num text-right">{{ $pct($cat['verdict_same'], $cat['n']) }}</td>
                        <td class="num text-right">{{ $pct($cat['decision_same'], $cat['n']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
