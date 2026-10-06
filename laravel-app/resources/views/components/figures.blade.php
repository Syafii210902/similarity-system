@props(['items'])

{{-- Ringkasan angka dalam satu panel bersekat: ['Label' => nilai, ...].
     Sekat dibuat dari gap 1px di atas latar garis, sehingga rapi untuk jumlah item ganjil maupun genap. --}}
<dl {{ $attributes->merge(['class' => 'panel grid grid-cols-2 gap-px overflow-hidden bg-line-soft sm:flex']) }}>
    @foreach ($items as $label => $value)
        <div class="bg-surface px-5 py-4 last:odd:col-span-2 sm:flex-1">
            <dt class="text-xs text-muted">{{ $label }}</dt>
            <dd class="num mt-1 text-2xl font-semibold tracking-[-0.02em]">{{ $value }}</dd>
        </div>
    @endforeach
</dl>
