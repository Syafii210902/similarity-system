<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /**
     * Autentikasi kredensial + rate limiting + validasi role yang boleh masuk dashboard.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (!$this->attemptLogin()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Email atau password salah.',
            ]);
        }

        if (!Auth::user()->canAccessDashboard()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Akun Anda tidak memiliki akses ke dashboard.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Hash password yang rusak/bukan bcrypt di database dianggap login gagal
     * (bukan error 500), lalu dicatat ke log agar admin bisa mereset akunnya.
     */
    private function attemptLogin(): bool
    {
        try {
            return Auth::attempt($this->only('email', 'password'), $this->boolean('remember'));
        } catch (\RuntimeException $e) {
            Log::warning('Login gagal: hash password akun tidak valid.', [
                'email' => $this->string('email')->toString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')) . '|' . $this->ip());
    }
}
