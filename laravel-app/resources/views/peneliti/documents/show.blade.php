@extends('layouts.app')

@section('title', $document->title)

@section('content')
<x-page-header :title="$document->title"
    :crumbs="['Lab Pengujian' => route('peneliti.dashboard'), $document->dataset->name => route('peneliti.datasets.show', $document->dataset), 'Dokumen' => null]"
    meta="{{ $document->categoryLabel() }}{{ $document->source ? ' · sumber: ' . $document->source->title : '' }}{{ $document->word_count ? ' · ' . $document->word_count . ' kata' : '' }}" />

<article class="panel max-w-3xl p-6">
    <div class="whitespace-pre-line text-[15px] leading-relaxed">{{ $text }}</div>
</article>
@endsection
