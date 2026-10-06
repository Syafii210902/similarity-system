# Ringkasan Peningkatan Proyek

Dokumen ini menjelaskan apa saja yang ditingkatkan pada sistem **Deteksi Kemiripan Teks & Konten AI-Generated**
dibandingkan kondisi awal (salinan kondisi awal tersedia di folder `similarity-system-asli`).

Arsitektur tetap sama: **Laravel** (web, database, webhook) dan **FastAPI** (ekstraksi teks, embedding, LLM)
yang berkomunikasi secara asinkron. Seluruh pemrosesan NLP tetap berada di FastAPI.

---

## 1. Gambaran Singkat: Sebelum vs Sesudah

| Aspek | Kondisi awal | Sekarang |
|---|---|---|
| Antarmuka web | Hanya halaman `welcome` bawaan Laravel | Aplikasi lengkap untuk Admin, Dosen, dan Mahasiswa |
| Login & hak akses | Tidak ada | Login, 3 role, pembatasan akses per role dan per kepemilikan data |
| Pengumpulan tugas | Hanya data dummy dari seeder | Mahasiswa mengunggah, mengganti, dan menghapus berkas PDF/DOCX |
| Memicu analisis | Endpoint API terbuka tanpa login; daftar berkas dikirim pemanggil | Tombol dosen; daftar berkas dibangun dari database; hanya setelah pengumpulan ditutup |
| Status analisis | Tidak tercatat; gagal secara diam-diam | Tabel `analysis_runs` (menunggu, diproses, selesai, gagal) beserta metrik |
| Tier 1 (embedding) | `all-MiniLM-L6-v2` (bahasa Inggris), seluruh dokumen dalam satu input (terpotong ±256 token) | Model multilingual + **embedding multi-lapis** (kalimat, passage, dokumen) |
| Tier 2 (LLM) | Hanya membaca 1.500 karakter pertama | Membaca passage paling mirip dari **seluruh** dokumen |
| Deteksi AI | 2.000 karakter pertama; kegagalan dicatat sebagai "tulisan manusia" | Cuplikan awal, tengah, dan akhir; kegagalan dicatat sebagai `ERROR` |
| Hasil analisis | Hanya JSON via API | Halaman hasil: daftar pasangan, matriks heatmap, detail segmen |
| Evaluasi metode | Tidak ada | Validasi dosen (ground truth) + precision/recall/F1 + ekspor CSV |
| Keamanan antar-service | Token ditulis langsung di kode; webhook tanpa verifikasi | Kunci acak dari `.env`, diverifikasi di kedua arah |
| Provider LLM | Gemini saja | Claude atau Gemini (dipilih otomatis lewat `.env`) |
| Pengujian otomatis | 2 test bawaan | 63 test fitur (273 assertion) |

---

## 2. Perbaikan Bug dan Konfigurasi

| Masalah | Dampak | Perbaikan |
|---|---|---|
| `APP_KEY` di `.env` root berupa placeholder tidak valid dan menimpa key yang benar | Session dan login tidak bisa berjalan | Diganti dengan key yang valid |
| `php artisan serve` tanpa `--no-reload` | Variabel env dari Docker (mis. kunci API) tidak terbaca saat request web | Server dijalankan dengan `--no-reload` |
| Server PHP hanya 1 worker | Request saling antre (polling status, simpan validasi) | `PHP_CLI_SERVER_WORKERS=4` |
| Batas upload PHP 2 MB | Berkas tugas lebih dari 2 MB gagal diunggah | `upload_max_filesize` 12 MB (validasi aplikasi: maks. 10 MB) |
| Zona waktu UTC | Tenggat dan waktu tampil 7 jam lebih awal dari WIB | `Asia/Jakarta`; data lama digeser +7 jam lewat migration |
| Test memakai database MySQL asli | Menjalankan test bisa menghapus data | Test dipaksa memakai SQLite di memori |
| Hash password akun Syafii terpotong | Error 500 saat login | Password direset; hash rusak sekarang ditangani sebagai "password salah" |
| FastAPI berhenti tanpa memberi kabar bila dokumen kurang dari 2 atau terjadi error | Laravel tidak pernah tahu analisis gagal | FastAPI **selalu** mengirim callback, termasuk status `failed` |
| Enum MySQL bisa ditolak oleh output LLM yang tidak sesuai | Seluruh hasil analisis gagal tersimpan | Semua nilai dinormalisasi sebelum disimpan, dalam satu transaksi database |
| `UserFactory` mengisi kolom `email_verified_at` yang tidak ada | Factory/test gagal | Kolom dihapus, ditambah state `admin()` dan `dosen()` |

---

## 3. Fitur Baru per Role

### Semua pengguna
- Halaman login dengan pembatasan percobaan (5 kali per email+IP).
- Setelah login diarahkan ke beranda sesuai role.

### Mahasiswa
- Beranda: tugas yang belum dikumpulkan (urut tenggat terdekat) dan daftar mata kuliah yang diikuti.
- Halaman mata kuliah: daftar tugas beserta status pengumpulan.
- Halaman tugas: **unggah, ganti, dan hapus** berkas PDF/DOCX (maks. 10 MB), lalu unduh kembali.
- Pengumpulan otomatis terkunci setelah tenggat lewat atau ditutup dosen.

### Dosen
- **Kelola mata kuliah**: tambah, ubah, hapus.
- **Kelola mahasiswa** per mata kuliah: tambahkan dari daftar akun (dengan pencarian) atau keluarkan.
- **Kelola tugas**: buat, ubah, hapus, atur tenggat (WIB).
- **Tutup pengumpulan secara manual** dan buka kembali.
- **Rekap pengumpulan**: siapa yang sudah dan belum mengumpulkan, unduh berkas per mahasiswa.
- **Jalankan analisis** (lihat bagian 4), **lihat hasil**, **validasi hasil**, dan **evaluasi metode**.

### Admin
- Beranda berisi ringkasan jumlah pengguna, data akademik, hasil analisis, dan status koneksi ke FastAPI.
- *Belum ada*: pengelolaan akun (lihat bagian 11).

---

## 4. Alur Analisis Kemiripan & Deteksi AI

```
Dosen menekan "Jalankan analisis" (pengumpulan sudah ditutup, minimal 2 berkas)
      │
      ▼
Laravel: buat baris analysis_runs (pending) → kirim daftar berkas ke FastAPI (dengan kunci API)
      │                                       FastAPI langsung membalas 202 Accepted
      ▼
FastAPI (background):
  0. Unduh & ekstrak teks PDF/DOCX
  1. Tier 1  – embedding multi-lapis, skor setiap pasangan
  2. Tier 2  – pasangan di atas ambang diperiksa LLM (salinan langsung vs parafrase)
  3. Deteksi AI per dokumen
      │
      ▼
Callback ke webhook Laravel (dengan kunci API) → hasil disimpan → run "selesai" atau "gagal"
      │
      ▼
Halaman tugas memeriksa status tiap 5 detik dan memuat ulang otomatis saat selesai
```

Fitur pendukung:
- Ambang batas bisa diatur dosen per run (0,50–0,95; bawaan 0,70).
- Berkas yang hilang dari penyimpanan dilaporkan dan tidak diikutkan.
- Run yang lebih dari 30 menit tanpa kabar otomatis ditandai gagal.
- Riwayat 5 run terakhir per tugas.
- Mengganti berkas menghapus hasil analisis dan validasi lama milik berkas itu.

---

## 5. Peningkatan Metode (Inti Penelitian)

### 5.1 Model embedding multilingual
`all-MiniLM-L6-v2` (dilatih untuk bahasa Inggris) diganti dengan **`paraphrase-multilingual-MiniLM-L12-v2`**
(Reimers & Gurevych, 2020). Uji awal pada kalimat berbahasa Indonesia: pasangan parafrase mendapat skor 0,944,
kalimat berbeda topik −0,066.

### 5.2 Embedding multi-lapis (Tier 1)
Model hanya membaca ±128 token pertama dari setiap input, sehingga dokumen utuh tidak bisa di-encode sekaligus.
Dokumen kini dipecah menjadi tiga lapis (`fastapi-service/multilayer.py`):

| Lapis | Cara hitung | Yang ditangkap |
|---|---|---|
| Dokumen | cosine dari rata-rata embedding semua passage | kesamaan topik |
| Passage (±60 kata) | rata-rata kecocokan terbaik tiap passage ke dokumen lawan | gagasan sama / parafrase |
| Kalimat | proporsi kalimat yang punya pasangan hampir identik (cosine ≥ 0,85) | salinan langsung |

Skor gabungan = 0,2 × dokumen + 0,4 × passage + 0,4 × kalimat.

Hasil uji pada teks bahasa Indonesia:

| Pasangan | Gabungan | Dokumen | Passage | Kalimat |
|---|---|---|---|---|
| Salinan (+1 kalimat tambahan) | 0,944 | 0,97 | 0,97 | 0,90 |
| Parafrase | 0,728 | 0,88 | 0,88 | 0,50 |
| Topik sama, isi berbeda | 0,369 | 0,61 | 0,61 | 0,00 |

Lapis dokumen saja memberi 0,61 untuk esai yang tidak menyalin; lapis kalimat dan passage yang membedakannya.

> **Catatan:** bobot 0,2 / 0,4 / 0,4 adalah **nilai awal heuristik**, bukan dari studi. Bobot akhir sebaiknya
> ditentukan dari data validasi (grid search + cross-validation atau regresi logistik), dengan tabel ablation.
> Bobot, ukuran passage, dan ambang kalimat dapat diubah lewat env (`LAYER_WEIGHTS`, `PASSAGE_MAX_WORDS`,
> `SENTENCE_MATCH_THRESHOLD`) dan tercatat di setiap run.

### 5.3 Tier 2 berbasis bukti
LLM tidak lagi membaca 1.500 karakter pertama, melainkan **5 pasangan passage paling mirip dari seluruh dokumen**
hasil Tier 1. Pada uji yang sama, segmen salinan yang ditemukan naik dari 1 menjadi 5.
Label segmen diseragamkan menjadi `VERBATIM_COPY` (salinan langsung) dan `PARAPHRASED` (parafrase).

### 5.4 Deteksi AI
- Teks yang dinilai berupa cuplikan merata dari awal, tengah, dan akhir dokumen.
- Verdict diturunkan dari probabilitas dengan ambang yang sama seperti di prompt (≥0,70 *Kemungkinan AI*,
  0,35–0,69 *Campuran*, <0,35 *Tulisan manusia*), sehingga konsisten.
- Kegagalan dicatat sebagai `ERROR` (probabilitas kosong), tidak lagi disamarkan sebagai *tulisan manusia*,
  sehingga tidak mencemari data penelitian.

### 5.5 Metrik setiap run
Setiap run menyimpan: ambang, model embedding, model LLM, parameter multi-lapis, waktu tiap tahap
(ekstraksi, Tier 1, Tier 2, deteksi AI), jumlah panggilan LLM, **jumlah panggilan LLM yang dihemat oleh Tier 1**,
jumlah error, dan pemakaian token. Data ini langsung dapat dipakai di bab hasil dan pembahasan.

---

## 6. Halaman Hasil Analisis

- Ringkasan: waktu run, ambang, model, durasi, pemakaian token, dan angka-angka utama.
- **Daftar pasangan** urut skor, berisi skor per lapis (D · P · K), status ambang, jumlah segmen, dan rekomendasi.
  Ada filter "hanya yang melewati ambang".
- **Matriks heatmap** N×N dengan tooltip; pasangan di atas ambang bergaris tepi dan bisa diklik.
- **Detail pasangan**: segmen kedua dokumen berdampingan, filter salinan/parafrase, unduh berkas, hasil deteksi AI keduanya.
- **Deteksi AI**: probabilitas, penilaian, ringkasan alasan, dan pola kalimat yang ditandai.

---

## 7. Validasi & Evaluasi Metode

### Validasi dosen (ground truth)
- Tombol **Plagiat / Bukan** per pasangan dan **AI / Manusia** per dokumen, tersimpan tanpa memuat ulang halaman.
- Disimpan di tabel terpisah (`pair_labels`, `ai_labels`), sehingga tidak hilang saat analisis dijalankan ulang.
  Validasi otomatis dihapus jika mahasiswa mengganti berkasnya.

### Halaman Evaluasi (menu "Evaluasi")
- Confusion matrix, precision, recall, F1, dan akurasi untuk:
  - **Tahap 1 saja** vs **Tahap 1 + Tahap 2** (menunjukkan kontribusi LLM),
  - deteksi AI versi **ketat** (hanya *Kemungkinan AI*) dan **longgar** (+ *Campuran*).
- **Sweep ambang 0,50–0,95** untuk skor gabungan dan tiap lapis; F1 terbaik ditandai.
- **Ekspor CSV** (UTF-8, pemisah koma, desimal titik) untuk Excel/SPSS/Python.
- Filter per tugas atau seluruh tugas.

---

## 8. Integrasi LLM: Claude & Gemini

Modul `fastapi-service/llm.py` mendukung dua provider:

- **Claude** (Anthropic API) dipakai otomatis bila `ANTHROPIC_API_KEY` terisi di `.env` root;
  selain itu **Gemini**. Bisa dipaksa dengan `LLM_PROVIDER=anthropic|gemini`.
- Model bawaan Claude: **`claude-opus-5-5` dengan `effort: low`** (model terkuat dengan penalaran secukupnya agar
  hemat token). Dapat diganti lewat `CLAUDE_MODEL` dan `LLM_EFFORT`.
- Claude memakai **structured outputs** (JSON Schema), sehingga jawaban selalu JSON valid sesuai skema.
- Fallback otomatis bila model menolak permintaan, retry untuk rate limit/error server, dan penanganan
  API key salah, kuota habis, serta output terpotong tanpa menghentikan proses.

> **Penting:** langganan Claude Pro (claude.ai) **tidak** mencakup akses API. API key dibuat terpisah di
> console.anthropic.com dengan saldo prabayar.
> Untuk penelitian, **jangan mencampur hasil dari dua provider** dalam satu evaluasi; provider dan model
> setiap run sudah tercatat di metrik.

---

## 9. Keamanan

- Endpoint analisis lama yang bisa dipanggil tanpa login **dihapus**; diganti route web khusus dosen pengampu.
- Endpoint hasil yang terbuka tanpa login **dihapus**; hasil kini hanya tampil di halaman dosen.
- Komunikasi Laravel ↔ FastAPI memakai dua kunci acak (`ANALYSIS_ENGINE_KEY`, `ANALYSIS_WEBHOOK_KEY`) di `.env` root,
  dicek dengan perbandingan aman (*constant-time*). Token yang dulu ditulis langsung di kode sudah dihapus.
- Otorisasi berbasis Policy: mahasiswa hanya mengakses mata kuliah yang diikutinya, dosen hanya mengelola
  mata kuliah yang diampunya.
- Berkas disimpan dengan nama acak (UUID) sehingga URL-nya tidak bisa ditebak.
- Validasi berkas berdasarkan ekstensi **dan** isi (MIME), maksimal 10 MB.
- Webhook menolak hasil untuk submission yang bukan milik tugas tersebut, dan run milik tugas lain.

---

## 10. Tampilan

- Desain modern-minimalis: sidebar dengan daftar mata kuliah, panel putih bergaris tipis, tombol utama hitam,
  aksen teal hemat, font **Plus Jakarta Sans** (buatan Indonesia) dan JetBrains Mono, badge status halus.
- Responsif untuk ponsel (sidebar menjadi menu geser).
- Semua konfirmasi aksi penting (hapus, tutup pengumpulan, jalankan ulang) memakai **modal**, bukan popup browser.
- Bahasa Indonesia di seluruh antarmuka, tanggal dan durasi dalam format Indonesia (WIB).

---

## 10b. Lab Pengujian (role Peneliti)

Area khusus eksperimen metode, terpisah dari data perkuliahan (tabel `experiment_*`).
Akun: `peneliti@kampus.ac.id` / `password`. Hanya role **peneliti** yang dapat mengakses.

- **Dataset uji berlabel.** Dokumen diberi kategori saat ditambahkan: Asli, Salinan langsung, Parafrase manual,
  Parafrase oleh AI, Independen (topik sama), Ditulis AI. Dokumen turunan menunjuk dokumen sumbernya.
  **Label terbentuk otomatis**: dua dokumen = plagiat bila berasal dari keluarga (sumber akar) yang sama;
  "Ditulis AI" dan "Parafrase oleh AI" = teks AI. Dokumen bisa ditempel sebagai teks atau diunggah (PDF/DOCX/TXT).
- **Korpus contoh** (14 dokumen, 2 topik) untuk mencoba alur. Semua teksnya ditulis AI, jadi **bukan data penelitian**.
  Panduan menyusun korpus yang sah tersedia di halaman Lab.
- **Eksperimen dengan parameter per run**: ambang, bobot lapis, panjang passage, ambang kalimat, Tahap 2 (LLM)
  ya/tidak, deteksi AI ya/tidak. FastAPI kini menerima parameter ini per request.
- **Skor per lapis disimpan mentah**, sehingga bobot dan ambang lain dihitung ulang seketika tanpa memanggil FastAPI/LLM.
- **Hasil eksperimen:**
  - **Ablation** (dokumen saja, passage saja, kalimat saja, sama rata, bobot run, bobot hasil optimasi) dengan
    **5-fold stratified cross-validation** (seed 42) dan grid search bobot (langkah 0,1) + ambang.
  - **Deteksi per jenis plagiat**: tingkat tertangkap untuk salinan langsung, parafrase manual, parafrase AI,
    serta salah tuduh pada pasangan bukan plagiat.
  - **Tahap 1 vs Tahap 1+2** (confusion matrix), **grafik F1 terhadap ambang** per lapis + tabel data,
    form "coba bobot & ambang lain", evaluasi deteksi AI per kategori dokumen, efisiensi (LLM yang dihemat, waktu, token).
  - Peringatan otomatis bila ada panggilan LLM yang gagal (hasil Tahap 2/deteksi AI tidak valid).
  - Ekspor CSV berisi metadata konfigurasi.
- **Uji cepat dua teks**: tempel dua teks, langsung terlihat skor per lapis, passage bukti, dan (opsional) hasil LLM.
  Cocok untuk demonstrasi saat sidang.

---

## 11. Yang Belum Dikerjakan

1. **Pengelolaan akun oleh admin** (tambah/ubah/hapus akun, reset password, impor mahasiswa dari CSV).
   Saat ini akun dibuat lewat seeder atau tinker.
2. **Proyek belum memakai git.** Disarankan `git init` dengan `.env` dikecualikan.
3. **Berkas pengumpulan Tugas 1 dan 2 (dari seeder) tidak ada di penyimpanan**, sehingga tugas itu tidak bisa dianalisis.
4. **Panduan instalasi** (README) untuk menjalankan sistem di komputer lain.
5. **Optimasi bobot lapis** (grid search + cross-validation) dan tabel ablation di halaman Evaluasi.
6. Ganti password oleh pengguna, notifikasi, dan konfigurasi server produksi
   (saat ini masih mode pengembangan: `artisan serve`, `APP_DEBUG=true`).

---

## 12. Perubahan pada Data yang Sedang Berjalan

Selama pengembangan, beberapa data di database lokal ikut diubah:

- Akun **admin** ditambahkan: `admin@kampus.ac.id` / `password`.
- Ketiga mahasiswa didaftarkan ke kedua mata kuliah, dan **Tugas 3 – Desain REST API** ditambahkan sebagai tugas terbuka.
- Password akun Syafii direset ke `password` (hash sebelumnya rusak).
- Semua waktu yang tersimpan digeser +7 jam (UTC → WIB).
- Tugas-tugas uji sementara yang dibuat saat pengujian sudah dihapus beserta berkasnya.

---

## 13. Konfigurasi Baru (`.env` root)

| Variabel | Fungsi |
|---|---|
| `ANALYSIS_ENGINE_KEY` | Kunci Laravel → FastAPI (sudah diisi acak) |
| `ANALYSIS_WEBHOOK_KEY` | Kunci FastAPI → webhook Laravel (sudah diisi acak) |
| `ANTHROPIC_API_KEY` | Isi untuk memakai Claude; kosongkan untuk Gemini |
| `CLAUDE_MODEL`, `LLM_EFFORT` | Opsional: model dan tingkat penalaran Claude |
| `LAYER_WEIGHTS`, `PASSAGE_MAX_WORDS`, `SENTENCE_MATCH_THRESHOLD`, `EVIDENCE_PASSAGES` | Opsional: parameter metode multi-lapis (diatur lewat `docker-compose.yml`) |

Setelah mengubah `.env` root, jalankan `docker compose up -d` agar container membaca nilai baru.

---

## 14. Pengujian

- **63 test fitur otomatis** (273 assertion) mencakup login dan hak akses, pengumpulan mahasiswa,
  pengelolaan dosen, alur analisis, kontrak webhook, halaman hasil, validasi, dan perhitungan metrik evaluasi.
- Jalankan dengan: `docker exec campus_laravel php artisan test`
- Selain test otomatis, setiap fitur utama juga diuji langsung di aplikasi yang berjalan
  (termasuk analisis sungguhan dengan FastAPI dan LLM) dan diperiksa tampilannya lewat screenshot.

---

## 15. Referensi Metode

1. Reimers, N., & Gurevych, I. (2020). Making monolingual sentence embeddings multilingual using knowledge distillation. *EMNLP 2020*, 4512–4525.
2. Sanchez-Perez, M. A., Sidorov, G., & Gelbukh, A. (2014). A winning approach to text alignment for text reuse detection at PAN 2014. *CLEF 2014 Working Notes*, 1004–1011.
3. Kittler, J., Hatef, M., Duin, R. P. W., & Matas, J. (1998). On combining classifiers. *IEEE TPAMI, 20*(3), 226–239.
4. Lipton, Z. C., Elkan, C., & Naryanaswamy, B. (2014). Optimal thresholding of classifiers to maximize F1 measure. *ECML PKDD 2014*, 225–239.
5. Potthast, M., Stein, B., Barrón-Cedeño, A., & Rosso, P. (2010). An evaluation framework for plagiarism detection. *COLING 2010: Posters*, 997–1005.
6. Foltýnek, T., Meuschke, N., & Gipp, B. (2019). Academic plagiarism detection: A systematic literature review. *ACM Computing Surveys, 52*(6), Art. 112.
