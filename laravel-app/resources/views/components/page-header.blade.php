@props(['title', 'crumbs' => [], 'meta' => null])

{{-- crumbs: ['Label' => url, ...]; item tanpa url ditampilkan sebagai teks --}}
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($crumbs)
            <p class="mb-2 flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                @foreach ($crumbs as $label => $url)
                    @if ($url)
                        <a href="{{ $url }}" class="text-muted hover:text-ink">{{ $label }}</a>
                    @else
                        <span class="text-ink">{{ $label }}</span>
                    @endif
                    @unless ($loop->last)<span class="text-faint">/</span>@endunless
                @endforeach
            </p>
        @endif
        <h1 class="text-[22px] font-semibold leading-tight tracking-[-0.02em]">{{ $title }}</h1>
        @if ($meta)
            <p class="mt-1 text-muted">{{ $meta }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
