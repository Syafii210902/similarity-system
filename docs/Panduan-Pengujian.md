# Panduan Pengujian di Lab Pengujian

Panduan ini mengikuti proposal revisi dosbim 2 (`Proposal_PA_Syafii_revisi-dosbim2.docx`): 3 tugas × 20 dokumen,
Layer 1 tiga lapis, Layer 2 Gemini `gemini-3.5-flash-lite`, pemilihan threshold dengan 5-fold cross-validation, dan uji
konsistensi deteksi AI. Versi cetaknya ada di `Panduan-Pengujian.pdf`.

Di aplikasi, setiap bagian hasil punya tombol **"Penjelasan: apa ini dan cara membacanya"**, dan setiap halaman punya
panel **"Istilah dan cara membaca nilai"**. Bagian 1 di bawah adalah ringkasan isi keduanya.

---

## 1. Memahami judul dan istilah dulu

### 1.1 Judul dalam bahasa sederhana

> *Analisis Efektivitas Metode Multi-Layer Semantic Vector Embedding dan LLM dalam Deteksi Kemiripan Teks serta
> Konten AI-Generated*

| Bagian judul | Artinya |
|---|---|
| **Deteksi kemiripan teks** | Mencari pasangan tugas mahasiswa yang saling menyalin, baik salin langsung (verbatim) maupun diubah kata-katanya (parafrase). |
| **Konten AI-generated** | Tugas yang seluruhnya dibuat oleh AI (ChatGPT). |
| **Semantic Vector Embedding** | Teks diubah menjadi deretan angka (vektor) yang mewakili **makna**. Dua kalimat bermakna sama punya vektor yang berdekatan walaupun kata-katanya berbeda. |
| **LLM** | Model bahasa besar (di sini Gemini) yang bisa membaca dua teks lalu menjelaskan bagian mana yang disalin. Pintar tetapi lambat dan dibatasi kuota. |
| **Multi-Layer** | Dua tahap. **Layer 1** (embedding) menyaring semua pasangan dengan cepat dan gratis. Hanya pasangan yang mencurigakan diteruskan ke **Layer 2** (LLM). |
| **Analisis efektivitas** | Diukur dari dua sisi pada threshold yang sama: **akurasi** (precision, recall, F1) dan **efisiensi** (berapa panggilan LLM yang dihemat). |

Perumpamaan: Layer 1 adalah **satpam di gerbang** yang memeriksa semua orang sekilas, sedangkan Layer 2 adalah
**penyidik** yang hanya memeriksa orang yang dicurigai satpam. Penyidik mahal, jadi makin sedikit yang dikirim makin
hemat. Tetapi kalau satpam terlalu longgar, penjahat lolos dan penyidik tidak pernah melihatnya.

### 1.2 Tiga rumusan masalah dan di mana jawabannya

| RM | Pertanyaan sederhana | Dijawab di aplikasi |
|---|---|---|
| **RM 1** | Seberapa akurat sistem menemukan pasangan yang menyalin? Apakah Layer 2 menambah akurasi? | Evaluasi gabungan → "Hasil per tugas dan gabungan", "Pemilihan ambang dan bobot lapis"; halaman run → "Deteksi per jenis plagiat" |
| **RM 2** | Berapa banyak panggilan LLM yang bisa dihemat Layer 1, dan berapa harganya (plagiat yang terlewat)? | Evaluasi gabungan → "Efisiensi pemanggilan LLM", "F1 terhadap ambang (gabungan)" |
| **RM 3** | Seberapa andal LLM membedakan tulisan manusia dan AI? Apakah penilaiannya konsisten? | Evaluasi gabungan → "Deteksi konten AI (gabungan)", "Uji konsistensi deteksi AI (gabungan)" |

### 1.3 Empat kemungkinan hasil (dasar semua metrik)

Setiap pasangan dokumen punya **label** (kunci jawaban: plagiat atau bukan) dan **prediksi** sistem.

| | Sistem: plagiat | Sistem: bukan |
|---|---|---|
| **Label: plagiat** | **TP** – benar menangkap | **FN** – plagiat terlewat |
| **Label: bukan** | **FP** – salah tuduh | **TN** – benar dibiarkan |

Di aplikasi, kotak ini disebut *confusion matrix*: hijau = TP, merah = FN, kuning = FP, abu-abu = TN.

### 1.4 Kamus metrik

| Metrik | Rumus | Artinya | Rentang | Yang baik |
|---|---|---|---|---|
| **Precision** | TP / (TP + FP) | Dari yang ditandai sistem, berapa yang benar | 0–1 | Besar (jarang salah tuduh) |
| **Recall** | TP / (TP + FN) | Dari yang memang plagiat, berapa yang tertangkap | 0–1 | Besar (jarang terlewat) |
| **F1-score** | 2·P·R / (P + R) | Gabungan precision dan recall; hanya tinggi bila keduanya tinggi. **Metrik utama.** | 0–1 | Besar |
| **Akurasi** | (TP + TN) / semua | Persentase keputusan benar | 0–1 | Besar, tetapi **bisa menipu** (lihat bawah) |
| **Reduction rate** | (1 − diteruskan / semua) × 100% | Persentase panggilan LLM yang dihemat | 0–100% | Besar, asal recall tetap tinggi |
| **Simpangan baku (sd)** | sebaran nilai dari rata-ratanya | Seberapa stabil/konsisten | ≥ 0 | Kecil |
| **ai_probability** | penilaian LLM | Seberapa mungkin dokumen ditulis AI (bukan peluang yang terkalibrasi) | 0–1 | Rendah untuk tulisan manusia, tinggi untuk Full AI |

**Kenapa akurasi menipu:** dari 190 pasangan per tugas, hanya 15 yang plagiat. Sistem yang tidak menandai apa pun
tetap benar 175/190 = **92%**, padahal tidak menangkap satu pun plagiat (recall 0, F1 0). Karena itu yang diutamakan F1.

**Contoh hitung:** sistem menandai 18 pasangan; 14 benar plagiat (TP = 14), 4 salah tuduh (FP = 4), dan 1 plagiat
terlewat (FN = 1).
Precision = 14/18 = 0,78 · Recall = 14/15 = 0,93 · F1 = 2 × 0,78 × 0,93 / (0,78 + 0,93) = 0,85.

### 1.5 Istilah khusus sistem ini

| Istilah | Penjelasan |
|---|---|
| **Pasangan** | Setiap dokumen dibandingkan dengan setiap dokumen lain di tugas yang sama: N(N−1)/2. 20 dokumen → **190 pasangan**. |
| **Label otomatis** | Dua dokumen = plagiat bila berasal dari dokumen original yang sama (O1–V1, O1–P1, V1–P1). 5 keluarga × 3 = **15 pasangan plagiat** per tugas. |
| **Skor D / P / K** | Skor Layer 1 per lapis, 0–1. **D** (dokumen) = kesamaan topik. **P** (passage ±60 kata) = kesamaan gagasan, mengenali parafrase. **K** (kalimat) = proporsi kalimat hampir identik (cosine ≥ 0,85), mengenali salinan. |
| **Skor gabungan** | 0,2·D + 0,4·P + 0,4·K (Persamaan 3.2). Dibandingkan dengan threshold. |
| **Threshold (ambang)** | Skor gabungan ≥ threshold → diteruskan ke LLM. Rendah = teliti tetapi boros; tinggi = hemat tetapi berisiko melewatkan plagiat. |
| **Tahap 1 / Tahap 2** | Nama lain Layer 1 / Layer 2 di aplikasi. "Tahap 1 + 2" = pasangan dianggap plagiat bila lolos threshold **dan** dikonfirmasi LLM. |
| **5-fold cross-validation (CV)** | Data dibagi 5 bagian. Bergantian, 4 bagian dipakai **memilih** threshold terbaik dan 1 bagian dipakai **menilai**. Hasilnya lebih jujur daripada memilih dan menilai di data yang sama. |
| **F1 CV ± sd** | Rata-rata F1 dari 5 kali penilaian ± sebarannya. sd kecil = stabil. |
| **Ambang per fold** | Threshold yang terpilih di setiap putaran CV. Kalau nilainya sama atau berdekatan, pilihan threshold bisa dipercaya. |
| **Ablation** | Membandingkan versi sistem dengan bagian yang dilepas/diubah (hanya lapis D, hanya P, hanya K, bobot berbeda) untuk melihat kontribusi tiap bagian. |
| **Verdict AI** | Kemungkinan AI (≥ 0,70), Campuran (0,35–0,69), Ditulis manusia (< 0,35). Keputusan penelitian memakai **≥ 0,50 = AI**. |
| **Uji konsistensi** | Dokumen yang sama dinilai LLM beberapa kali. Kalau skornya berubah-ubah, hasil deteksi kurang dapat dipercaya. |

---

## 2. Persiapan

### 2.1 Sistem
1. Jalankan Docker Desktop, lalu di folder `similarity-system`: `docker compose up -d`.
2. Pastikan `.env` berisi:
   ```
   GEMINI_MODELS=gemini-3.5-flash-lite
   GEMINI_DELAY_SECONDS=4
   ```
   Setelah mengubah `.env`: `docker compose up -d fastapi`.
3. Buka `http://localhost:8081`, masuk dengan akun **peneliti** (`peneliti@kampus.ac.id` / `password`).

### 2.2 Kuota Gemini (akun gratis, Flash Lite)
15 permintaan/menit, **500 permintaan/hari** (direset sekitar pukul 14.00–15.00 WIB). Perkiraan kebutuhan seluruh
pengujian ±300–400 panggilan, jadi cukup dalam 1 hari, lebih aman dibagi 2 hari (lihat Bagian 4).

### 2.3 Dokumen per tugas (20 dokumen)

| Kategori proposal | Kode | Jumlah | Kategori di aplikasi (CSV) | Sumber |
|---|---|---|---|---|
| Original | T1-O1 … T1-O5 | 5 | `asli` | – |
| Verbatim | T1-V1 … T1-V5 | 5 | `salinan langsung` | original pasangannya |
| Parafrase | T1-P1 … T1-P5 | 5 | `parafrase manual` | original pasangannya |
| Full AI | T1-AI1 … T1-AI5 | 5 | `full ai` | – |

Gunakan versi **Potongan** dari folder `Dataset/2/Tema N/Potongan/` sebagai dokumen original (DOCX).

---

## 3. Langkah pengujian

### Langkah 1 – Buat tiga dataset (satu per tugas)
1. Lab Pengujian → **Buat dataset**: `Tugas 1 – Pembelajaran daring`, `Tugas 2 – Pemasaran digital UMKM`,
   `Tugas 3 – Sampah plastik`.
2. Buka dataset → panel **Impor massal** → unduh templat CSV.
3. Isi manifest (pemisah `;`), contoh Tugas 1:
   ```
   berkas;judul;kategori;sumber;teks
   T1-O1.docx;T1-O1;asli;;
   T1-V1.docx;T1-V1;salinan langsung;T1-O1.docx;
   T1-P1.docx;T1-P1;parafrase manual;T1-O1.docx;
   T1-AI1.docx;T1-AI1;full ai;;
   ...
   ```
4. Pilih ke-20 berkas + manifest → Impor. Kalau ada kesalahan, tidak ada yang tersimpan dan setiap baris yang salah
   ditampilkan.
5. **Cek:** dataset berisi 20 dokumen; setiap Verbatim/Parafrase menunjuk original yang benar (kalau salah, label
   pasangan ikut salah).

### Langkah 2 – Run Layer 1 saja (gratis, tanpa LLM)
Untuk **setiap** dataset → **Jalankan eksperimen**:

| Isian | Nilai |
|---|---|
| Nama | `T1 – Layer 1` (T2, T3 menyesuaikan) |
| Ambang | 0,70 |
| Bobot D/P/K | 0,2 / 0,4 / 0,4 |
| Panjang passage | 60 |
| Ambang kalimat | 0,85 |
| Jalankan Tahap 2 (LLM) untuk pasangan di atas ambang | **tidak dicentang** |
| Jalankan deteksi AI per dokumen | **tidak dicentang** |

Selesai dalam hitungan detik. **Cek di halaman run:** Pasangan = 190, Plagiat (label) = 15, tidak ada peringatan
dokumen gagal diekstrak.

### Langkah 3 – Pilih threshold dengan evaluasi gabungan
1. Menu **Evaluasi gabungan** → centang ketiga run "Layer 1" → **Gabungkan**.
2. Lihat tabel **"Pemilihan ambang dan bobot lapis (5-fold CV gabungan)"**:
   - baris **"Bobot run (0,2 / 0,4 / 0,4)"** → kolom *Bobot D/P/K · ambang terbaik* = **threshold terpilih**;
   - kolom *Ambang per fold* → catat kelima nilainya (bukti stabilitas);
   - bandingkan *F1 CV* semua baris → lapis mana yang paling berkontribusi.
3. **Keputusan bobot:** tetap pakai bobot awal 0,2/0,4/0,4 kecuali "Bobot hasil optimasi" jauh lebih baik (misalnya
   selisih F1 CV > 0,05 dan sd tidak lebih besar). Kalau ganti bobot, catat alasannya untuk Bab 4.
4. Lihat **"F1 terhadap ambang (gabungan)"** → buka tabel data → ini bahan grafik trade-off threshold vs recall vs
   reduction rate (RM 2).

### Langkah 4 – Run lengkap dengan LLM
Untuk setiap dataset, jalankan run baru:

| Isian | Nilai |
|---|---|
| Nama | `T1 – Lengkap (ambang X)` |
| Ambang | **threshold terpilih** dari Langkah 3 |
| Bobot, passage, ambang kalimat | sama seperti Langkah 2 (atau bobot terpilih) |
| Jalankan Tahap 2 (LLM) untuk pasangan di atas ambang | **dicentang** |
| Jalankan deteksi AI per dokumen | **dicentang** |

Jumlah panggilan per tugas ≈ pasangan di atas ambang + 20 deteksi AI. Halaman run memperbarui diri sendiri.
**Cek:** tidak ada kotak merah "Sebagian panggilan LLM gagal". Kalau ada (biasanya kuota habis), hapus run lalu
ulangi besok.

### Langkah 5 – Run pembanding di threshold 0,70 (bila threshold terpilih ≠ 0,70)
Sama seperti Langkah 4 tetapi ambang **0,70** dan **"Jalankan deteksi AI per dokumen" tidak dicentang** (deteksi AI tidak dipengaruhi
threshold, cukup sekali di Langkah 4). Ini untuk membandingkan threshold terpilih dengan threshold awal dari Syuja'i dkk.

### Langkah 6 – Uji konsistensi deteksi AI
Di halaman setiap run **Lengkap** → bagian **Uji konsistensi deteksi AI**:
1. Jumlah penilaian per dokumen: **3× (run + 2 ulangan)**.
2. Jangan centang dokumen apa pun (= semua 20 dokumen) → **Mulai uji konsistensi**.
3. 40 panggilan per tugas, ±3–5 menit. Kalau terhenti karena kuota, klik **Lanjutkan uji konsistensi** besoknya;
   yang sudah berhasil tidak diulang.
4. **Cek:** tidak ada peringatan "Model LLM ulangan berbeda dengan model run".

### Langkah 7 – Ambil hasil akhir
**Evaluasi gabungan** → centang ketiga run **Lengkap** → Gabungkan. Ulangi untuk ketiga run pembanding 0,70.
Unduh CSV setiap run (tombol *Unduh CSV*) sebagai arsip data mentah.

---

## 4. Jadwal yang disarankan (kuota 500/hari)

| Hari | Kegiatan | Panggilan LLM (perkiraan) |
|---|---|---|
| 1 | Langkah 1–3 (impor, run Layer 1, pilih threshold) | 0 |
| 1 | Langkah 4 untuk 3 tugas | ±3 × (20–40 + 20) = 120–180 |
| 1 | Langkah 5 untuk 3 tugas | ±3 × 20–40 = 60–120 |
| 2 | Langkah 6 untuk 3 tugas | 3 × 40 = 120 |
| 2 | Langkah 7 + tulis tabel Bab 4 | 0 |

---

## 5. Cara membaca setiap bagian dan apa yang dilaporkan

### Halaman Evaluasi gabungan (hasil akhir)

| Bagian | Menjawab | Yang dicatat untuk Bab 4 | Hasil yang baik |
|---|---|---|---|
| Hasil per tugas dan gabungan | RM 1 | P, R, F1 Layer 1 dan Layer 1+2 per tugas + gabungan; dua confusion matrix | F1 tinggi; Layer 1+2 menurunkan FP dibanding Layer 1 tanpa menambah banyak FN |
| Efisiensi pemanggilan LLM | RM 2 | Pasangan diteruskan, reduction rate, total panggilan (verifikasi + deteksi AI), token, waktu | Reduction rate tinggi dengan recall tetap tinggi |
| Pemilihan ambang dan bobot lapis (CV) | RM 1, RM 2 | F1 CV ± sd setiap konfigurasi, ambang per fold, threshold terpilih | F1 CV tinggi, sd kecil, ambang per fold berdekatan |
| F1 terhadap ambang (gabungan) | RM 1, RM 2 | Tabel ambang vs P, R, F1, reduction rate → grafik trade-off | Ada rentang ambang dengan recall tinggi sekaligus reduction rate tinggi |
| Deteksi konten AI (gabungan) | RM 3 | Confusion matrix ambang 0,50; rata-rata probabilitas per kategori; tabel ambang lain | Precision tinggi (jarang menuduh manusia); Full AI berprobabilitas tinggi, kategori lain rendah |
| Uji konsistensi (gabungan) | RM 3 | Rata-rata sd, rentang terbesar, % keputusan tidak berubah, F1 tiap ulangan ± sd | sd kecil, keputusan tidak berubah mendekati 100% |

### Halaman run (detail per tugas)

| Bagian | Gunanya |
|---|---|
| Ringkasan | Cek cepat: 190 pasangan, 15 plagiat, berapa diteruskan, total panggilan LLM |
| Ablation | Sama dengan CV gabungan tetapi hanya satu tugas (3 plagiat per fold, kurang stabil) – pakai versi gabungan untuk laporan |
| Deteksi per jenis plagiat | Bahan pembahasan: verbatim vs parafrase mana yang lebih sering terlewat, berapa salah tuduh |
| Tahap 1 vs Tahap 1+2 | Kontribusi LLM pada satu tugas |
| F1 terhadap ambang | Pola per lapis pada satu tugas |
| Coba bobot & ambang lain | Alat eksplorasi; **jangan** dilaporkan sebagai hasil akhir |
| Deteksi konten AI | Detail per tugas; ketat (≥ 0,70) vs longgar (≥ 0,35) |
| Uji konsistensi | Tempat menjalankan ulangan + tabel skor tiap dokumen |
| Semua pasangan | Mencari contoh kasus salah tuduh/terlewat untuk dibahas |

### Pola hasil yang mungkin muncul dan artinya

| Pola | Arti | Kalimat pembahasan |
|---|---|---|
| F1 "lapis passage saja" ≈ gabungan | Lapis passage paling menentukan | "Lapis passage memberikan kontribusi terbesar karena mampu mengenali parafrase." |
| F1 "lapis dokumen saja" rendah, precision rendah | Kesamaan topik membuat banyak salah tuduh | "Lapis dokumen saja tidak cukup karena seluruh dokumen dalam satu tugas membahas topik yang sama." |
| Verbatim 100% tertangkap, parafrase lebih rendah | Parafrase memang lebih sulit | "Recall pada pasangan parafrase lebih rendah dibandingkan verbatim." |
| Layer 1+2: FP turun, FN sama | LLM berhasil menyaring salah tuduh | "Layer 2 meningkatkan precision dari … menjadi … tanpa menurunkan recall." |
| Parafrase manusia berprobabilitas AI tinggi | LLM keliru menganggap tulisan formal sebagai AI | Bahas sebagai keterbatasan stilometri berbasis LLM (batasan no. 12). |
| sd konsistensi besar / keputusan sering berubah | Penilaian LLM tidak stabil | "Hasil deteksi AI belum dapat diandalkan sebagai keputusan tunggal." |

---

## 6. Kesalahan yang harus dihindari
1. **Melaporkan F1 "terbaik data penuh" atau hasil "Coba bobot"** sebagai hasil akhir – terlalu optimis. Laporkan F1 CV.
2. **Menggabungkan run dengan parameter berbeda** – halaman gabungan akan menampilkan peringatan kuning; samakan dulu.
3. **Mencampur model LLM** – semua run dan ulangan harus memakai `gemini-3.5-flash-lite`.
4. **Memakai run yang ada panggilan LLM gagal** – hapus dan ulangi.
5. **Salah menunjuk sumber** Verbatim/Parafrase saat impor – label pasangan jadi salah.
6. **Memasukkan dua run dari dataset yang sama** ke evaluasi gabungan – pasangan terhitung ganda.

## 7. Untuk demo sidang
- Semua hasil tersimpan; cukup buka **Evaluasi gabungan** dan halaman run.
- Demo langsung yang cepat:
  - **Uji cepat dua teks**: tempel dua paragraf → skor D/P/K + hasil LLM (beberapa detik).
  - **Uji konsistensi** pada 2–3 dokumen yang dicentang (±10 detik).
  - **Coba bobot & ambang lain** dan **Coba ambang probabilitas** dihitung seketika tanpa LLM.
- Buka tombol **Penjelasan** di setiap bagian bila penguji menanyakan arti sebuah angka.
