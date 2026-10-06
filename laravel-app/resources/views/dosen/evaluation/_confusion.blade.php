{{-- Confusion matrix + metrik. Variabel: $title, $m (hasil EvaluationService::confusion), $positive, $negative --}}
@php $fmt = fn ($v) => $v === null ? '—' : number_format($v, 3, ',', '.'); @endphp
<div class="panel">
    <div class="panel-head">
        <h3 class="panel-title">{{ $title }}</h3>
        <span class="text-xs text-muted">n = {{ $m['n'] }}</span>
    </div>
    <div class="grid gap-5 p-5 sm:grid-cols-[auto_1fr]">
        <table class="text-center text-[13px]">
            <thead>
                <tr>
                    <th></th>
                    <th class="px-2 pb-1.5 text-xs font-medium text-muted" colspan="2">Prediksi sistem</th>
                </tr>
                <tr>
                    <th></th>
                    <th class="w-20 pb-1.5 text-xs font-medium text-muted">{{ $positive }}</th>
                    <th class="w-20 pb-1.5 text-xs font-medium text-muted">{{ $negative }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th class="pr-3 text-right text-xs font-medium text-muted">Label: {{ strtolower($positive) }}</th>
                    <td class="p-0.5"><div class="num rounded-[4px] bg-ok-soft py-3 font-semibold text-ok" title="True positive">{{ $m['tp'] }}</div></td>
                    <td class="p-0.5"><div class="num rounded-[4px] bg-danger-soft py-3 font-semibold text-danger" title="False negative">{{ $m['fn'] }}</div></td>
                </tr>
                <tr>
                    <th class="pr-3 text-right text-xs font-medium text-muted">Label: {{ strtolower($negative) }}</th>
                    <td class="p-0.5"><div class="num rounded-[4px] bg-warn-soft py-3 font-semibold text-warn" title="False positive">{{ $m['fp'] }}</div></td>
                    <td class="p-0.5"><div class="num rounded-[4px] bg-hover py-3 font-semibold" title="True negative">{{ $m['tn'] }}</div></td>
                </tr>
            </tbody>
        </table>
        <dl class="grid grid-cols-2 content-center gap-x-6 gap-y-3">
            @foreach (['precision' => 'Precision', 'recall' => 'Recall', 'f1' => 'F1-score', 'accuracy' => 'Akurasi'] as $key => $text)
                <div>
                    <dt class="text-xs text-muted">{{ $text }}</dt>
                    <dd class="num text-lg font-semibold tracking-[-0.01em]">{{ $fmt($m[$key]) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
    <p class="border-t border-line-soft px-5 py-2.5 text-xs text-muted">
        <span class="text-ok">TP</span> benar terdeteksi ·
        <span class="text-warn">FP</span> salah tuduh ·
        <span class="text-danger">FN</span> lolos ·
        TN benar dinyatakan aman
    </p>
</div>
