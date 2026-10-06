@props(['action', 'options', 'fields' => [], 'current' => null])

{{-- Tombol validasi manual (ground truth). Klik pilihan yang sedang aktif untuk menghapus label. --}}
<form method="POST" action="{{ $action }}" data-label-form
    {{ $attributes->merge(['class' => 'inline-flex rounded-sm border border-line bg-surface p-0.5 text-xs']) }}>
    @csrf
    @foreach ($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    @foreach ($options as $value => $text)
        @php $pressed = $current === $value; @endphp
        <button type="submit" name="label" value="{{ $pressed ? 'clear' : $value }}" data-value="{{ $value }}"
            aria-pressed="{{ $pressed ? 'true' : 'false' }}"
            title="{{ $pressed ? 'Klik lagi untuk menghapus label' : 'Tandai sebagai ' . strtolower($text) }}"
            class="whitespace-nowrap rounded-[4px] px-2 py-1 font-medium text-muted transition hover:text-ink aria-pressed:bg-ink aria-pressed:text-white">
            {{ $text }}
        </button>
    @endforeach
</form>

@once
    @push('scripts')
        <script>
            document.addEventListener('submit', async (e) => {
                const form = e.target.closest('[data-label-form]');
                if (!form) return;
                e.preventDefault();

                const body = new FormData(form);
                if (e.submitter) body.set('label', e.submitter.value);
                form.style.opacity = '0.6';

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        body,
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error(res.status);
                    const { label } = await res.json();
                    form.querySelectorAll('button[data-value]').forEach((button) => {
                        const pressed = button.dataset.value === label;
                        button.setAttribute('aria-pressed', pressed);
                        button.value = pressed ? 'clear' : button.dataset.value;
                    });
                    form.dispatchEvent(new CustomEvent('label-saved', { bubbles: true, detail: { label } }));
                } catch (err) {
                    form.submit(); // fallback: kirim sebagai form biasa
                } finally {
                    form.style.opacity = '';
                }
            });
        </script>
    @endpush
@endonce
