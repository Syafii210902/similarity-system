@props(['tone' => 'neutral'])

@php
    $classes = [
        'ok' => 'bg-ok-soft text-ok',
        'warn' => 'bg-warn-soft text-warn',
        'danger' => 'bg-danger-soft text-danger',
        'brand' => 'bg-brand-soft text-brand',
        'neutral' => 'bg-hover text-ink',
        'muted' => 'bg-hover text-muted',
    ][$tone] ?? 'bg-hover text-ink';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-[5px] px-2 py-0.5 text-xs font-medium {$classes}"]) }}>{{ $slot }}</span>
