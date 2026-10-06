@props(['assignment'])

<x-status :tone="$assignment->isOpen() ? 'brand' : 'muted'" {{ $attributes }}>{{ $assignment->submissionStateLabel() }}</x-status>
