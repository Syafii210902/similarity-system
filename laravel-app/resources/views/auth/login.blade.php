@extends('layouts.guest')

@section('title', 'Masuk')

@section('content')
<div class="flex min-h-screen flex-col bg-surface">
    <main class="flex flex-1 items-center justify-center px-4 py-12">
        <div class="w-full max-w-[360px]">
            <div class="mb-8 flex items-center gap-2.5">
                @include('partials.logo-mark', ['size' => 26])
                <span class="text-base font-semibold tracking-[-0.01em]">{{ config('app.name') }}</span>
            </div>

            <h1 class="text-2xl font-semibold tracking-[-0.02em]">Masuk</h1>
            <p class="mb-7 mt-1.5 text-muted">Gunakan akun yang diberikan oleh admin.</p>

            @if ($errors->any())
                <p class="alert alert-danger mb-5" role="alert">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="form-label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        placeholder="nama@kampus.ac.id" class="form-input py-2.5" @error('email') aria-invalid="true" @enderror>
                </div>
                <div>
                    <label for="password" class="form-label">Kata sandi</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="form-input py-2.5">
                </div>
                <label class="flex items-center gap-2 text-muted">
                    <input type="checkbox" name="remember" class="h-4 w-4 rounded accent-ink">
                    Tetap masuk di perangkat ini
                </label>
                <button type="submit" class="btn btn-primary w-full py-2.5">Masuk</button>
            </form>

            <p class="mt-8 text-[13px] text-muted">Lupa kata sandi atau belum punya akun? Hubungi admin program studi.</p>
        </div>
    </main>

    <footer class="px-4 pb-6 text-center text-xs text-faint">Proyek Akhir D4 Rekayasa Perangkat Lunak</footer>
</div>
@endsection
