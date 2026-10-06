@props(['action', 'method' => 'POST', 'submitLabel' => 'Kumpulkan'])

@php $inputId = 'file-' . \Illuminate\Support\Str::random(6); @endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" data-upload-form {{ $attributes }}>
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    <label for="{{ $inputId }}"
        class="flex cursor-pointer flex-col items-center rounded-md border border-dashed border-line bg-paper/60 px-4 py-6 text-center transition hover:border-faint hover:bg-hover/60"
        data-dropzone>
        <span class="mb-2 text-muted">@include('partials.icon', ['name' => 'file'])</span>
        <span class="font-medium" data-file-label>Pilih berkas tugas</span>
        <span class="mt-0.5 text-[13px] text-muted" data-file-hint>atau seret ke sini · PDF / DOCX, maks. 10 MB</span>
        <input id="{{ $inputId }}" name="file" type="file" required class="sr-only"
            accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    </label>

    @error('file')
        <p class="form-error">{{ $message }}</p>
    @enderror

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <button type="submit" class="btn btn-primary" data-submit>{{ $submitLabel }}</button>
        {{ $slot }}
    </div>
</form>

@once
    @push('scripts')
        <script>
            document.querySelectorAll('[data-upload-form]').forEach((form) => {
                const input = form.querySelector('input[type=file]');
                const zone = form.querySelector('[data-dropzone]');
                const showName = () => {
                    if (!input.files.length) return;
                    const file = input.files[0];
                    form.querySelector('[data-file-label]').textContent = file.name;
                    form.querySelector('[data-file-hint]').textContent = `${(file.size / 1024 / 1024).toFixed(2)} MB · klik untuk mengganti`;
                };

                input.addEventListener('change', showName);
                zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('border-brand'); });
                zone.addEventListener('dragleave', () => zone.classList.remove('border-brand'));
                zone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    zone.classList.remove('border-brand');
                    input.files = e.dataTransfer.files;
                    showName();
                });
                form.addEventListener('submit', () => {
                    const button = form.querySelector('[data-submit]');
                    button.disabled = true;
                    button.textContent = 'Mengunggah…';
                });
            });
        </script>
    @endpush
@endonce
