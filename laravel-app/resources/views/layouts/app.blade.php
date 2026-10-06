<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => trim($__env->yieldContent('title')) ?: 'Beranda'])
</head>
@php
    $user = auth()->user();
    $roleLabel = ['admin' => 'Administrator', 'dosen' => 'Dosen', 'mahasiswa' => 'Mahasiswa', 'peneliti' => 'Peneliti'][$user->role] ?? $user->role;
    $initials = collect(preg_split('/\s+/', preg_replace('/[^\pL\s]/u', '', $user->name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('');

    $navigation = match ($user->role) {
        'dosen' => [
            ['label' => 'Beranda', 'icon' => 'home', 'url' => route('dosen.dashboard'), 'active' => request()->routeIs('dosen.dashboard')],
            ['label' => 'Mata kuliah', 'icon' => 'book', 'url' => route('dosen.courses.index'), 'active' => request()->routeIs('dosen.courses.index', 'dosen.courses.create')],
            ['label' => 'Evaluasi', 'icon' => 'chart', 'url' => route('dosen.evaluation'), 'active' => request()->routeIs('dosen.evaluation*')],
        ],
        'peneliti' => [
            ['label' => 'Lab Pengujian', 'icon' => 'flask', 'url' => route('peneliti.dashboard'), 'active' => request()->routeIs('peneliti.dashboard')],
            ['label' => 'Uji cepat dua teks', 'icon' => 'compare', 'url' => route('peneliti.compare'), 'active' => request()->routeIs('peneliti.compare*')],
        ],
        'mahasiswa' => [
            ['label' => 'Beranda', 'icon' => 'home', 'url' => route('mahasiswa.dashboard'), 'active' => request()->routeIs('mahasiswa.dashboard')],
        ],
        default => [
            ['label' => 'Beranda', 'icon' => 'home', 'url' => route('admin.dashboard'), 'active' => request()->routeIs('admin.*')],
        ],
    };

    // Daftar mata kuliah di sidebar (diampu untuk dosen, diikuti untuk mahasiswa).
    $sidebarCourses = match ($user->role) {
        'dosen' => $user->coursesTaught()->orderBy('code')->get(['id', 'code', 'name']),
        'mahasiswa' => $user->coursesEnrolled()->orderBy('code')->get(['courses.id', 'courses.code', 'courses.name']),
        // Peneliti: daftar dataset uji (kode = jumlah dokumen).
        'peneliti' => \App\Models\ExperimentDataset::withCount('documents')->latest()->limit(12)->get(['id', 'name'])
            ->each(fn ($d) => $d->code = $d->documents_count . ' dok'),
        default => collect(),
    };
    $courseRoute = match ($user->role) {
        'dosen' => 'dosen.courses.show',
        'peneliti' => 'peneliti.datasets.show',
        default => 'mahasiswa.courses.show',
    };
    $activeCourseId = $user->isPeneliti()
        ? (request()->route('dataset')?->id ?? request()->route('run')?->dataset_id ?? request()->route('document')?->dataset_id)
        : (request()->route('course')?->id ?? request()->route('assignment')?->course_id);
@endphp
<body>
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside id="sidebar"
        class="fixed inset-y-0 left-0 z-30 flex w-60 -translate-x-full flex-col border-r border-line bg-surface transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <a href="{{ route('dashboard') }}" class="flex h-14 items-center gap-2.5 px-5 text-ink no-underline hover:no-underline">
            @include('partials.logo-mark')
            <span class="text-[15px] font-semibold tracking-[-0.01em]">{{ config('app.name') }}</span>
        </a>

        <nav class="flex-1 overflow-y-auto px-3 py-2">
            <ul class="space-y-0.5">
                @foreach ($navigation as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                            @class([
                                'flex items-center gap-2.5 rounded-sm px-2.5 py-2 font-medium no-underline hover:no-underline',
                                'bg-hover text-ink' => $item['active'],
                                'text-muted hover:bg-hover hover:text-ink' => !$item['active'],
                            ])>
                            @include('partials.icon', ['name' => $item['icon'], 'class' => $item['active'] ? 'shrink-0 text-brand' : 'shrink-0'])
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($sidebarCourses->isNotEmpty())
                <p class="mb-1 mt-6 px-2.5 text-xs font-medium text-faint">{{ match ($user->role) { 'dosen' => 'Diampu', 'peneliti' => 'Dataset', default => 'Diikuti' } }}</p>
                <ul class="space-y-0.5">
                    @foreach ($sidebarCourses as $navCourse)
                        @php $active = $activeCourseId === $navCourse->id; @endphp
                        <li>
                            <a href="{{ route($courseRoute, $navCourse) }}" title="{{ $navCourse->name }}"
                                @class([
                                    'flex items-baseline gap-2 rounded-sm px-2.5 py-1.5 no-underline hover:no-underline',
                                    'bg-hover text-ink' => $active,
                                    'text-muted hover:bg-hover hover:text-ink' => !$active,
                                ])>
                                <span class="w-14 shrink-0 font-mono text-[11px] {{ $active ? 'text-brand' : 'text-faint' }}">{{ $navCourse->code }}</span>
                                <span class="truncate">{{ $navCourse->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>

        <div class="flex items-center gap-2.5 border-t border-line-soft px-4 py-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hover text-xs font-semibold text-muted">{{ $initials }}</span>
            <span class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-[13px] font-medium">{{ $user->name }}</span>
                <span class="block text-xs text-muted">{{ $roleLabel }}</span>
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-sm p-1.5 text-muted hover:bg-hover hover:text-ink" title="Keluar" aria-label="Keluar">
                    @include('partials.icon', ['name' => 'logout'])
                </button>
            </form>
        </div>
    </aside>
    <div id="sidebar-backdrop" class="fixed inset-0 z-20 hidden bg-ink/30 lg:hidden"></div>

    {{-- Konten --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <div class="flex h-14 items-center gap-3 border-b border-line bg-surface px-4 lg:hidden">
            <button id="sidebar-toggle" type="button" class="rounded-sm p-1.5 text-muted hover:bg-hover" aria-label="Buka menu">
                @include('partials.icon', ['name' => 'menu'])
            </button>
            @include('partials.logo-mark', ['size' => 20])
            <span class="font-semibold">{{ config('app.name') }}</span>
        </div>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-8 sm:py-8">
            @if (session('success'))
                <p class="alert alert-ok mb-6" role="status">{{ session('success') }}</p>
            @endif
            @if (session('error'))
                <p class="alert alert-danger mb-6" role="alert">{{ session('error') }}</p>
            @endif

            @yield('content')
        </main>

        <footer class="mx-auto w-full max-w-6xl px-4 pb-6 text-xs text-faint sm:px-8">
            Waktu dalam WIB
        </footer>
    </div>
</div>

<script>
    (() => {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        const toggle = (open) => {
            sidebar.classList.toggle('-translate-x-full', !open);
            backdrop.classList.toggle('hidden', !open);
        };
        document.getElementById('sidebar-toggle').addEventListener('click', () => toggle(true));
        backdrop.addEventListener('click', () => toggle(false));
    })();

</script>
@include('partials.confirm-modal')
@stack('scripts')
</body>
</html>
