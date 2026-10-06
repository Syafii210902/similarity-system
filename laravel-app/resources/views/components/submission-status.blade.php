@props(['assignment', 'submission' => null])

@if ($submission !== null)
    <x-status tone="ok" {{ $attributes }}>Sudah dikumpulkan</x-status>
@elseif ($assignment->isOpen())
    <x-status tone="warn" {{ $attributes }}>Belum dikumpulkan</x-status>
@else
    <x-status tone="danger" {{ $attributes }}>Tidak dikumpulkan</x-status>
@endif
