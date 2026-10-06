# Similarity System

Sistem deteksi **kemiripan teks** (salinan langsung & parafrase) antar-tugas mahasiswa dan **konten AI-generated**,
dikembangkan untuk Proyek Akhir D4 Rekayasa Perangkat Lunak:

> *Analisis Efektivitas Metode Multi-Layer Semantic Vector Embedding dan Large Language Model
> dalam Deteksi Kemiripan Teks serta Konten AI-Generated*

---

## Daftar isi

1. [Arsitektur](#arsitektur)
2. [Fitur](#fitur)
3. [Prasyarat](#prasyarat)
4. [Instalasi](#instalasi)
5. [Akun bawaan](#akun-bawaan)
6. [Konfigurasi](#konfigurasi)
7. [Menjalankan test](#menjalankan-test)
8. [Struktur proyek](#struktur-proyek)
9. [Pemecahan masalah](#pemecahan-masalah)
10. [Dokumentasi lain](#dokumentasi-lain)

---

## Arsitektur

Dua service terpisah yang berkomunikasi secara asinkron (*Decoupled Asynchronous Hybrid Service*):

```
┌──────────────────────────┐   POST /api/v1/analyze-similarity    ┌──────────────────────────────┐
│  Laravel (laravel)       │ ───────────────────────────────────▶ │  FastAPI (fastapi)           │
│  Web, auth, MySQL,       │        202 Accepted (non-blocking)   │  Ekstraksi PDF/DOCX,         │
│  penyimpanan berkas,     │ ◀─────────────────────────────────── │  embedding multi-lapis,      │
│  evaluasi                │   POST /api/webhooks/...-result      │  LLM (Claude / Gemini)       │
└────────────┬─────────────┘        (callback hasil)              └──────────────────────────────┘
             │
        ┌────▼────┐
        │ MySQL   │  (db)
        └─────────┘
```

Semua pemrosesan NLP berada di FastAPI; Laravel hanya mengirim daftar berkas, menyimpan hasil, dan mengevaluasi.
Kedua arah komunikasi dilindungi kunci API (`X-API-Key`).

**Metode dua tahap:**

| Tahap | Proses |
|---|---|
| **Tahap 1** – Multi-Layer Embedding | Dokumen dipecah menjadi kalimat dan passage (±60 kata), di-encode dengan `paraphrase-multilingual-MiniLM-L12-v2`. Skor pasangan dihitung di tiga lapis (dokumen, passage, kalimat) lalu digabung berbobot. |
| **Tahap 2** – Verifikasi LLM | Pasangan dengan skor ≥ ambang dikirim ke LLM bersama passage paling mirip sebagai bukti, untuk membedakan **salinan langsung** dan **parafrase**. |
| **Deteksi AI** | Setiap dokumen dinilai LLM: probabilitas ditulis AI dan pola kalimat yang ditandai. |

**Teknologi:** Laravel 12 (PHP 8.2), MySQL 8, Tailwind CSS 4 + Vite, FastAPI (Python 3.10), SentenceTransformers,
Anthropic SDK / Google GenAI SDK, Docker Compose.

---

## Fitur

| Peran | Fitur |
|---|---|
| **Mahasiswa** | Melihat mata kuliah & tugas; mengunggah, mengganti, dan menghapus berkas PDF/DOCX sebelum pengumpulan ditutup. |
| **Dosen** | Mengelola mata kuliah, mahasiswa, dan tugas; menutup pengumpulan; menjalankan analisis; melihat hasil (daftar pasangan, matriks heatmap, segmen berdampingan, deteksi AI); memvalidasi hasil; halaman evaluasi (precision/recall/F1) dan ekspor CSV. |
| **Peneliti** | **Lab Pengujian**: korpus uji berlabel (label otomatis dari kategori dokumen), eksperimen dengan parameter per run, ablation + 5-fold cross-validation + optimasi bobot, deteksi per jenis plagiat, grafik F1 terhadap ambang, uji cepat dua teks. |
| **Admin** | Ringkasan sistem dan status koneksi ke layanan analisis. |

Rincian lengkap perubahan dan alasannya ada di [PERUBAHAN.md](PERUBAHAN.md).

---

## Prasyarat

- **Docker Desktop** (Docker Compose v2)
- **Node.js 20+** dan npm, di komputer host, untuk membangun aset CSS/JS. Container Laravel tidak berisi Node.
- **Git**
- API key LLM, minimal salah satu:
  - **Claude**: [console.anthropic.com](https://console.anthropic.com). Langganan Claude Pro (claude.ai) **tidak** mencakup akses API.
  - **Gemini**: [aistudio.google.com](https://aistudio.google.com). Ada kuota gratis harian.
- Port **8081** (web), **8000** (FastAPI), dan **3306** (MySQL) belum dipakai aplikasi lain.

---

## Instalasi

Perintah di bawah memakai sintaks bash (Git Bash / WSL / macOS / Linux). Di PowerShell, ganti `cp` dengan `Copy-Item`.

### 1. Ambil kode & siapkan konfigurasi

```bash
git clone <url-repo> similarity-system
cd similarity-system

cp .env.example .env                          # konfigurasi docker-compose (rahasia)
cp laravel-app/.env.example laravel-app/.env  # konfigurasi Laravel
```

Isi `.env` di root:

```bash
# Kunci antar-service: dua string acak yang berbeda
openssl rand -hex 32   # tempel ke ANALYSIS_ENGINE_KEY
openssl rand -hex 32   # tempel ke ANALYSIS_WEBHOOK_KEY
```

Isi juga `ANTHROPIC_API_KEY` **atau** `GEMINI_API_KEY`. `APP_KEY` diisi di langkah 3.

### 2. Bangun & jalankan container

```bash
docker compose up -d --build
```

Saat pertama dijalankan, FastAPI mengunduh model embedding (±470 MB) ke volume `hf_cache`. Tunggu sampai log
`docker compose logs -f fastapi` menampilkan `Application startup complete`.

### 3. Pasang dependensi Laravel & kunci aplikasi

Folder kode di-*mount* ke container, sehingga `vendor/` perlu dipasang di dalamnya. Sebelum `vendor/` ada,
container `laravel` akan terus restart; itu wajar. Karena itu gunakan `run --rm` (container sementara), bukan `exec`:

```bash
docker compose run --rm laravel composer install
docker compose run --rm laravel php artisan key:generate --show
```

Salin keluaran `base64:...` ke `APP_KEY` di **`.env` root**, lalu muat ulang container Laravel:

```bash
docker compose up -d laravel
```

### 4. Database, penyimpanan berkas, dan akun awal

```bash
docker compose exec laravel php artisan migrate --seed
docker compose exec laravel php artisan storage:link
```

`--seed` membuat akun admin, peneliti, dosen, dua mahasiswa, serta satu mata kuliah berisi dua tugas contoh.

### 5. Bangun aset tampilan (di host)

```bash
cd laravel-app
npm install
npm run build
cd ..
```

Jalankan ulang `npm run build` setiap kali mengubah class Tailwind di view. Saat pengembangan bisa juga memakai `npm run dev`.

### 6. Buka aplikasi

**http://localhost:8081**

Cek layanan analisis: `curl http://localhost:8000/` mengembalikan `{"status":"running"}`.

---

## Akun bawaan

Dibuat oleh `php artisan migrate --seed`. **Ganti password sebelum dipakai di luar lingkungan lokal.**

| Peran | Email | Password |
|---|---|---|
| Admin | `admin@kampus.ac.id` | `password` |
| Peneliti (Lab Pengujian) | `peneliti@kampus.ac.id` | `password` |
| Dosen | `dosen@kampus.ac.id` | `password` |
| Mahasiswa | `andi@student.kampus.ac.id` | `password` |
| Mahasiswa | `siti@student.kampus.ac.id` | `password` |

> Pengumpulan contoh dari seeder menunjuk berkas yang tidak ada, sehingga tugas contoh itu tidak dapat dianalisis.
> Untuk mencoba analisis, buat tugas baru, kumpulkan berkas sebagai mahasiswa, lalu tutup pengumpulan sebagai dosen.
> Untuk eksperimen metode, masuk sebagai peneliti lalu klik **Buat korpus contoh**.

---

## Konfigurasi

### `.env` root (dibaca docker-compose)

| Variabel | Wajib | Keterangan |
|---|---|---|
| `APP_KEY` | ya | Kunci enkripsi Laravel (`php artisan key:generate --show`). |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | ya | Kredensial MySQL. |
| `ANALYSIS_ENGINE_KEY` | ya | Kunci Laravel → FastAPI. |
| `ANALYSIS_WEBHOOK_KEY` | ya | Kunci FastAPI → webhook Laravel. |
| `ANTHROPIC_API_KEY` | salah satu | Bila terisi, Tahap 2 & deteksi AI memakai Claude. |
| `GEMINI_API_KEY` | salah satu | Dipakai bila `ANTHROPIC_API_KEY` kosong. |
| `LLM_PROVIDER` | tidak | Paksa provider: `anthropic` atau `gemini`. |
| `CLAUDE_MODEL`, `LLM_EFFORT` | tidak | Bawaan `claude-opus-5-5` dan `low`. |

Setelah mengubah `.env` root, jalankan `docker compose up -d` agar container membaca nilai baru.

### Parameter metode (di `docker-compose.yml`, service `fastapi`)

| Variabel | Bawaan | Keterangan |
|---|---|---|
| `EMBEDDING_MODEL_NAME` | `paraphrase-multilingual-MiniLM-L12-v2` | Model SentenceTransformers. |
| `LAYER_WEIGHTS` | `0.2,0.4,0.4` | Bobot dokumen, passage, kalimat. Nilai awal heuristik; tentukan bobot akhir lewat Lab Pengujian. |
| `PASSAGE_MAX_WORDS` | `60` | Panjang passage (harus muat dalam 128 token model). |
| `SENTENCE_MATCH_THRESHOLD` | `0.85` | Cosine minimum agar dua kalimat dianggap hampir identik. |
| `EVIDENCE_PASSAGES` | `5` | Jumlah pasangan passage yang dikirim ke LLM sebagai bukti. |

Di Lab Pengujian, parameter ini dapat diatur per eksperimen tanpa mengubah konfigurasi.

---

## Menjalankan test

```bash
docker compose exec laravel php artisan test
```

Test memakai SQLite di memori (dipaksa di `phpunit.xml`), sehingga **tidak menyentuh database MySQL**.
Tidak ada panggilan ke FastAPI atau LLM sungguhan (memakai `Http::fake`).

---

## Struktur proyek

```
similarity-system/
├── docker-compose.yml
├── .env.example                    # contoh konfigurasi rahasia (salin ke .env)
├── PERUBAHAN.md                    # ringkasan seluruh peningkatan
├── fastapi-service/
│   ├── main.py                     # endpoint, alur Tahap 1 → Tahap 2 → deteksi AI, callback
│   ├── multilayer.py               # pemecahan kalimat/passage & skor per lapis
│   ├── llm.py                      # Claude / Gemini + skema structured output
│   ├── requirements.txt
│   └── requirements-llm.txt        # SDK Anthropic (layer Docker terpisah)
└── laravel-app/
    ├── app/
    │   ├── Http/Controllers/       # Dosen/, Mahasiswa/, Peneliti/, Api/ (webhook)
    │   ├── Models/                 # Assignment, Submission, AnalysisRun, Experiment*, ...
    │   ├── Policies/               # hak akses mata kuliah & tugas
    │   ├── Services/               # AnalysisService, SubmissionService, EvaluationService,
    │   │                           # ExperimentService, ExperimentEvaluator, ...
    │   └── Repositories/           # penyimpanan & normalisasi hasil dari FastAPI
    ├── database/migrations/
    ├── resources/views/            # admin/, dosen/, mahasiswa/, peneliti/, components/
    ├── routes/web.php, api.php
    └── tests/Feature/
```

---

## Pemecahan masalah

| Gejala | Penyebab & solusi |
|---|---|
| Container `laravel` terus restart setelah instalasi baru | `vendor/` belum terpasang. Jalankan `docker compose run --rm laravel composer install`, lalu `docker compose up -d laravel`. |
| Halaman error *No application encryption key* atau login gagal terus | `APP_KEY` di `.env` root kosong atau tidak valid. Isi sesuai langkah 3, lalu `docker compose up -d laravel`. |
| Analisis selalu gagal atau tertahan "Sedang diproses" | Pastikan `ANALYSIS_ENGINE_KEY` dan `ANALYSIS_WEBHOOK_KEY` terisi, lalu `docker compose up -d`. Periksa `docker compose logs fastapi`. |
| Hasil berlabel *Gagal dianalisis*, atau Lab menampilkan peringatan "panggilan LLM gagal" | Kuota/rate limit LLM habis (mis. Gemini `429 RESOURCE_EXHAUSTED`) atau API key salah. Hasil Tahap 1 tetap valid; jalankan ulang setelah kuota pulih. |
| Berkas tidak ditemukan saat analisis | `APP_URL` di `laravel-app/.env` harus `http://laravel`, dan `php artisan storage:link` sudah dijalankan. |
| Tampilan tanpa gaya (CSS tidak termuat) | Jalankan `npm install && npm run build` di folder `laravel-app`. |
| Port 8081/8000/3306 sudah dipakai | Hentikan aplikasi lain, atau ubah pemetaan port di `docker-compose.yml`. |
| Variabel di `.env` root tidak terbaca oleh web | Laravel dijalankan dengan `artisan serve --no-reload` (sudah diatur di `docker-compose.yml`); tanpa opsi ini env dari Docker diabaikan. |

---

## Dokumentasi lain

- [PERUBAHAN.md](PERUBAHAN.md): ringkasan seluruh peningkatan, keputusan metode, batasan, dan referensi.
- Panduan menyusun korpus uji berlabel tersedia di halaman **Lab Pengujian** (akun peneliti).

> **Catatan keamanan:** konfigurasi ini ditujukan untuk lingkungan pengembangan dan demonstrasi
> (`APP_DEBUG=true`, server `artisan serve`). Untuk penggunaan di server sungguhan, gunakan web server produksi
> (Nginx + PHP-FPM), matikan debug, aktifkan HTTPS, dan ganti semua password bawaan.
