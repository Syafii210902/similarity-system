<section class="panel">
    <details class="group" @if ($open ?? false) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-5 py-3.5">
            <h2 class="panel-title">Panduan menyusun korpus uji berlabel</h2>
            <span class="text-[13px] text-brand group-open:hidden">Tampilkan</span>
            <span class="hidden text-[13px] text-brand group-open:inline">Sembunyikan</span>
        </summary>
        <div class="space-y-4 border-t border-line-soft px-5 py-4 text-[13px] leading-relaxed">
            <p>Label pasangan dibentuk otomatis dari kategori dokumen. Dua dokumen dianggap <strong>plagiat</strong> bila berasal dari
                <em>keluarga</em> yang sama, yaitu salah satunya turunan dari yang lain atau keduanya turunan dari dokumen sumber yang sama.
                Label deteksi AI: dokumen <em>Ditulis AI</em> dan <em>Parafrase oleh AI</em> dianggap teks AI.</p>

            <table class="w-full text-left">
                <thead class="text-xs text-muted"><tr class="border-b border-line-soft"><th class="py-1.5 pr-3 font-medium">Kategori</th><th class="py-1.5 font-medium">Cara membuat</th></tr></thead>
                <tbody class="align-top">
                    <tr class="border-b border-line-soft"><td class="py-1.5 pr-3 font-medium">Asli</td><td class="py-1.5">Esai tulisan manusia, misalnya tugas lama yang dipakai dengan izin penulisnya atau esai yang ditulis relawan.</td></tr>
                    <tr class="border-b border-line-soft"><td class="py-1.5 pr-3 font-medium">Salinan langsung</td><td class="py-1.5">Salin sebagian atau seluruh dokumen asli. Variasikan: salin penuh, salin 50%, sisipkan paragraf sendiri.</td></tr>
                    <tr class="border-b border-line-soft"><td class="py-1.5 pr-3 font-medium">Parafrase manual</td><td class="py-1.5">Relawan (bukan AI) menulis ulang dokumen asli dengan kata-kata sendiri tanpa mengubah gagasan.</td></tr>
                    <tr class="border-b border-line-soft"><td class="py-1.5 pr-3 font-medium">Parafrase oleh AI</td><td class="py-1.5">Minta LLM (ChatGPT/Gemini/Claude) memparafrasekan dokumen asli. Catat nama model dan prompt-nya di keterangan dataset.</td></tr>
                    <tr class="border-b border-line-soft"><td class="py-1.5 pr-3 font-medium">Independen</td><td class="py-1.5">Esai lain tulisan manusia dengan <strong>topik/soal yang sama</strong> tetapi ditulis terpisah. Ini kontrol negatif terpenting, karena mensimulasikan tugas satu kelas.</td></tr>
                    <tr><td class="py-1.5 pr-3 font-medium">Ditulis AI</td><td class="py-1.5">Minta LLM menulis esai dari soal tugas yang sama tanpa diberi dokumen asli.</td></tr>
                </tbody>
            </table>

            <ul class="list-disc space-y-1 pl-5">
                <li><strong>Ukuran:</strong> usahakan minimal 10 dokumen asli per topik dengan turunannya. Sebanyak 30–60 dokumen sudah menghasilkan ratusan pasangan. Gunakan 2–3 topik agar hasil tidak bergantung pada satu tema.</li>
                <li><strong>Keseimbangan:</strong> pasangan plagiat biasanya jauh lebih sedikit daripada pasangan bukan plagiat. Ini wajar, karena kondisi kelas nyata juga begitu. Laporkan precision, recall, dan F1, bukan akurasi.</li>
                <li><strong>Panjang:</strong> buat panjang dokumen mendekati tugas sebenarnya, misalnya 300–1.000 kata.</li>
                <li><strong>Etika:</strong> minta izin penulis, hilangkan identitas (nama, NIM), dan jelaskan penggunaannya untuk penelitian.</li>
                <li><strong>Dokumentasi:</strong> catat siapa yang membuat parafrase, model AI dan prompt yang dipakai, serta tanggalnya. Ini dibutuhkan untuk bab metodologi.</li>
            </ul>
            <p class="text-muted">Rujukan: korpus plagiat simulasi pada evaluasi PAN (Potthast dkk., 2010, <em>COLING</em>).</p>
        </div>
    </details>
</section>
