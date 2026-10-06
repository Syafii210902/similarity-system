@props(['assignment', 'relative' => true])

@php $due = $assignment->due_date; @endphp

<span {{ $attributes }}>
    @if ($due === null)
        <span class="text-muted">Tanpa tenggat</span>
    @else
        <span class="num text-[13px]">{{ $due->translatedFormat('d M Y, H:i') }}</span>
        @if ($relative)
            @if ($assignment->isOpen())
                <span @class(['text-[13px]', 'font-medium text-warn' => now()->diffInHours($due) < 48, 'text-muted' => now()->diffInHours($due) >= 48])>
                    ({{ $due->diffForHumans(['parts' => 2, 'join' => true]) }})
                </span>
            @elseif ($assignment->isPastDue())
                <span class="text-[13px] text-muted">(sudah lewat)</span>
            @endif
        @endif
    @endif
</span>
