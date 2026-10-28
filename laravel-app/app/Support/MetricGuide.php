<?php

namespace App\Support;

/**
 * Penjelasan setiap bagian evaluasi dan istilah metrik di Lab Pengujian.
 * Ditulis dalam bahasa sederhana agar hasil dapat dibaca tanpa latar belakang statistik.
 * RM = rumusan masalah pada proposal.
 */
class MetricGuide
{
    /**
     * Penjelasan per bagian halaman.
     * Kunci: what (apa ini), tests (menguji apa), read (cara membaca), range (rentang nilai), source (dari mana).
     */
    public const SECTIONS = [
        'summary' => [
            'what' => 'Ringkasan satu run: berapa pasangan dokumen yang dibandingkan, berapa yang memang plagiat menurut label, berapa yang diteruskan ke LLM, dan berapa panggilan LLM yang tidak diperlukan berkat Layer 1.',
            'tests' => 'RM 2 (efektivitas Layer 1 sebagai filter pemanggilan LLM).',
            'read' => '“Pasangan” = N(N−1)/2; 20 dokumen → 190 pasangan. “Di atas ambang” = pasangan yang diteruskan ke LLM. “Panggilan LLM dihemat” makin besar makin hemat, tetapi harus dibaca bersama recall: hemat tidak berguna kalau banyak plagiat terlewat. “Total panggilan LLM” = verifikasi pasangan + deteksi AI (satu per dokumen).',
            'range' => 'Persentase dihemat 0–100%.',
            'source' => 'Skor gabungan setiap pasangan dari FastAPI dibandingkan dengan ambang run; jumlah panggilan dan token dicatat FastAPI saat run berjalan.',
        ],
        'ablation' => [
            'what' => 'Membandingkan beberapa cara menghitung skor Layer 1: hanya lapis dokumen, hanya passage, hanya kalimat, bobot sama rata, bobot run, dan bobot hasil pencarian otomatis. Untuk setiap cara, ambang terbaik dipilih dengan 5-fold cross-validation.',
            'tests' => 'RM 1 (akurasi Layer 1) dan pemilihan threshold serta bobot lapis.',
            'read' => 'Bandingkan kolom “F1 CV”. Makin besar makin baik. Baris yang disorot = F1 CV tertinggi. “± sd” kecil berarti hasil stabil di semua fold. “Ambang per fold” yang berdekatan (misalnya 0,65; 0,70; 0,70; 0,65; 0,70) berarti pemilihan ambang stabil. “F1 terbaik data penuh” selalu lebih tinggi karena ambangnya dipilih dan dinilai pada data yang sama (terlalu optimis), jadi yang dilaporkan adalah F1 CV.',
            'range' => 'F1 0–1 (1 = sempurna). sd 0–0,5; makin kecil makin stabil.',
            'source' => 'Dihitung ulang dari skor per lapis yang tersimpan, tanpa memanggil FastAPI atau LLM. Pembagian fold memakai pengacakan dengan seed 42 sehingga hasilnya selalu sama.',
        ],
        'per_category' => [
            'what' => 'Berapa persen pasangan dari setiap jenis yang ditandai sistem. Baris plagiat (verbatim, parafrase) seharusnya ditandai; baris “bukan plagiat” seharusnya tidak ditandai.',
            'tests' => 'RM 1 (jenis plagiat mana yang mudah dan sulit dideteksi).',
            'read' => 'Baris plagiat: makin tinggi persentasenya makin baik (verbatim biasanya hampir 100%, parafrase lebih rendah). Baris bukan plagiat: makin rendah makin baik; angka merah = salah tuduh. Kolom rata-rata D/P/K menunjukkan lapis mana yang membedakan jenis tersebut.',
            'range' => '0–100%. Rata-rata skor 0–1.',
            'source' => 'Label pasangan dibentuk otomatis dari kategori dokumen (keluarga dokumen yang sama = plagiat); keputusan memakai ambang run.',
        ],
        'tiers' => [
            'what' => 'Confusion matrix keputusan run untuk Layer 1 saja dan untuk Layer 1 + Layer 2 (pasangan dianggap plagiat bila lolos ambang dan juga dikonfirmasi LLM).',
            'tests' => 'RM 1 (kontribusi Layer 2 terhadap akurasi).',
            'read' => 'Hijau (TP) dan abu-abu (TN) = benar. Kuning (FP) = salah tuduh. Merah (FN) = plagiat yang terlewat. Layer 2 diharapkan menurunkan FP (precision naik) tanpa banyak menambah FN. Layer 2 tidak dapat memperbaiki FN dari Layer 1, karena pasangan di bawah ambang tidak pernah dikirim ke LLM.',
            'range' => 'Precision, recall, F1, akurasi 0–1.',
            'source' => 'Skor gabungan tersimpan + hasil LLM yang tersimpan per pasangan.',
        ],
        'sweep' => [
            'what' => 'Grafik F1 Layer 1 untuk setiap nilai ambang 0,30–0,95 (interval 0,05), untuk skor gabungan dan masing-masing lapis.',
            'tests' => 'RM 1 dan RM 2 (pengaruh threshold).',
            'read' => 'Puncak garis = ambang dengan F1 tertinggi. Ambang terlalu rendah → banyak salah tuduh (precision turun); terlalu tinggi → banyak plagiat terlewat (recall turun). Garis datar di sekitar puncak berarti hasil tidak terlalu sensitif terhadap pilihan ambang.',
            'range' => 'F1 0–1.',
            'source' => 'Dihitung dari skor tersimpan pada data penuh (bukan cross-validation), jadi gunakan untuk melihat pola, bukan sebagai nilai akhir.',
        ],
        'try' => [
            'what' => 'Menghitung ulang keputusan Layer 1 dengan bobot lapis dan ambang lain secara instan.',
            'tests' => 'Alat bantu eksplorasi, bukan hasil utama.',
            'read' => 'Ubah bobot atau ambang, lalu lihat perubahan confusion matrix. Hasil di sini dipilih sendiri pada data yang sama, jadi jangan dilaporkan sebagai hasil akhir; gunakan F1 CV dari tabel ablation.',
            'range' => '—',
            'source' => 'Skor per lapis tersimpan.',
        ],
        'ai' => [
            'what' => 'Evaluasi deteksi konten buatan AI per dokumen. “Ketat” = hanya verdict “Kemungkinan AI” (≥ 0,70) yang dianggap AI; “Longgar” = “Kemungkinan AI” atau “Campuran” (≥ 0,35).',
            'tests' => 'RM 3 (keandalan analisis stilometri LLM).',
            'read' => 'Precision tinggi = jarang menuduh tulisan manusia sebagai AI (paling penting untuk tugas mahasiswa). Recall tinggi = sebagian besar dokumen AI tertangkap. Tabel per kategori: Original, Verbatim, dan Parafrase seharusnya berprobabilitas rendah; Full AI seharusnya tinggi. Bila Parafrase tinggi, LLM salah mengira tulisan manusia yang kaku sebagai AI.',
            'range' => 'Probabilitas AI 0–1 (0–100%). Precision, recall, F1 0–1.',
            'source' => 'Skor ai_probability dari LLM untuk setiap dokumen; label AI dari kategori dokumen (Ditulis AI / Parafrase oleh AI = AI).',
        ],
        'ai_threshold' => [
            'what' => 'Pengaruh ambang probabilitas (0,10–0,90) terhadap precision, recall, dan F1 deteksi AI. Penelitian memakai ambang keputusan 0,50; tabel ini menunjukkan apakah ambang lain lebih baik.',
            'tests' => 'RM 3 (karena skor LLM tidak terkalibrasi, ambang perlu dianalisis).',
            'read' => 'Ambang naik → precision cenderung naik, recall turun. Baris yang disorot = F1 tertinggi (bila seri dipilih ambang lebih tinggi agar lebih jarang menuduh).',
            'range' => '0–1.',
            'source' => 'Probabilitas tersimpan, tanpa memanggil LLM lagi.',
        ],
        'consistency' => [
            'what' => 'Dokumen yang sama dinilai ulang oleh LLM dengan prompt, cuplikan, dan model yang sama. Ulangan ke-1 adalah hasil run.',
            'tests' => 'RM 3 (apakah penilaian LLM konsisten atau berubah-ubah).',
            'read' => '“Rata-rata simpangan baku” kecil (misalnya < 0,05) = skor hampir sama setiap ulangan. “Rentang terbesar” = selisih skor paling jauh pada satu dokumen. “Keputusan AI tidak berubah” mendekati 100% = keputusan dapat diandalkan. F1 tiap ulangan yang mirip (± sd kecil) berarti akurasi tidak bergantung pada keberuntungan satu kali run.',
            'range' => 'Simpangan baku 0–0,5. Persentase 0–100%. F1 0–1.',
            'source' => 'Ulangan deteksi AI yang dijalankan dari halaman run (satu panggilan LLM per dokumen per ulangan).',
        ],
        'pairs' => [
            'what' => 'Daftar semua pasangan dengan skor per lapis, skor gabungan, label, dan hasil LLM.',
            'tests' => 'Analisis kasus: melihat pasangan mana yang salah tuduh atau terlewat.',
            'read' => 'Diurutkan dari skor tertinggi. Pasangan “Bukan” dengan skor tinggi = calon salah tuduh; pasangan “Plagiat” dengan skor rendah = calon terlewat. Contoh kasus seperti ini bagus untuk pembahasan Bab 4.',
            'range' => 'Skor 0–1.',
            'source' => 'Skor tersimpan.',
        ],
        'combined_tasks' => [
            'what' => 'Hasil setiap tugas dan hasil gabungan ketiga tugas. Hasil gabungan dihitung dengan menjumlahkan TP, FP, TN, dan FN semua tugas, baru kemudian dihitung precision, recall, dan F1.',
            'tests' => 'RM 1 (akurasi akhir) dan konsistensi hasil antar-tugas.',
            'read' => 'Baris “Gabungan” adalah angka yang dilaporkan. Baris per tugas menunjukkan apakah satu tugas jauh lebih sulit dari yang lain (misalnya karena topiknya lebih seragam).',
            'range' => '0–1.',
            'source' => 'Hasil tersimpan dari run yang dipilih; tiap run memakai ambangnya sendiri.',
        ],
        'combined_efficiency' => [
            'what' => 'Penghematan pemanggilan LLM per tugas dan total.',
            'tests' => 'RM 2.',
            'read' => 'Reduction rate = persentase pasangan yang tidak perlu dikirim ke LLM; makin besar makin hemat. Total panggilan = verifikasi pasangan + deteksi AI. Token dan waktu menunjukkan biaya nyata.',
            'range' => 'Reduction rate 0–100%.',
            'source' => 'Metrik yang dicatat FastAPI pada setiap run.',
        ],
        'combined_cv' => [
            'what' => 'Pemilihan ambang dan bobot lapis dengan 5-fold cross-validation pada seluruh pasangan gabungan (misalnya 570 pasangan dari 3 tugas). Setiap fold berisi pasangan dari semua tugas dengan proporsi plagiat yang seimbang.',
            'tests' => 'RM 1 dan RM 2: inilah ambang akhir yang berlaku untuk semua tugas.',
            'read' => 'Sama seperti tabel ablation di halaman run, tetapi datanya lebih banyak (45 pasangan plagiat, 9 per fold), sehingga lebih stabil. Ambang per fold yang sama atau berdekatan = ambang terpilih dapat dipercaya. Bandingkan juga dengan ambang awal 0,70.',
            'range' => 'F1 0–1, sd makin kecil makin stabil.',
            'source' => 'Skor tersimpan, seed 42.',
        ],
        'combined_sweep' => [
            'what' => 'F1, precision, recall, dan reduction rate gabungan untuk setiap ambang.',
            'tests' => 'RM 1 dan RM 2 (trade-off akurasi vs penghematan).',
            'read' => 'Cari ambang yang reduction rate-nya tinggi tetapi recall masih tinggi. Ini dasar grafik trade-off di Bab 4.',
            'range' => '0–1 dan 0–100%.',
            'source' => 'Skor tersimpan, data penuh.',
        ],
    ];

    /** Istilah metrik yang sering muncul. */
    public const TERMS = [
        'TP / FP / FN / TN' => 'TP = plagiat dan ditandai (benar). FP = bukan plagiat tetapi ditandai (salah tuduh). FN = plagiat tetapi tidak ditandai (terlewat). TN = bukan plagiat dan tidak ditandai (benar).',
        'Precision' => 'TP / (TP + FP). Dari semua yang ditandai sistem, berapa yang memang benar. Rentang 0–1, makin besar makin baik. Rendah = banyak salah tuduh.',
        'Recall' => 'TP / (TP + FN). Dari semua yang memang plagiat (atau AI), berapa yang tertangkap. Rentang 0–1, makin besar makin baik. Rendah = banyak yang terlewat.',
        'F1-score' => '2 × P × R / (P + R), rata-rata harmonik precision dan recall. Rentang 0–1, makin besar makin baik. F1 hanya tinggi bila precision dan recall sama-sama tinggi. Metrik utama penelitian ini.',
        'Akurasi' => '(TP + TN) / semua. Bisa menipu karena pasangan bukan plagiat jauh lebih banyak (175 dari 190): sistem yang tidak menandai apa pun tetap mendapat akurasi 92%. Karena itu yang diutamakan F1.',
        'Ambang (threshold)' => 'Batas skor Layer 1. Skor ≥ ambang → diteruskan ke LLM; di bawahnya → dianggap aman. Rendah = lebih teliti tetapi boros LLM; tinggi = hemat tetapi berisiko melewatkan plagiat.',
        'Skor D / P / K' => 'Skor kemiripan per lapis (0–1): D = dokumen (kesamaan topik), P = passage ±60 kata (kesamaan gagasan, mengenali parafrase), K = kalimat (proporsi kalimat hampir identik, mengenali salinan).',
        'Skor gabungan' => 'w_dok × D + w_pas × P + w_kal × K (Persamaan 3.2 proposal). Bobot awal 0,2 / 0,4 / 0,4.',
        'Reduction rate' => '(1 − pasangan diteruskan / semua pasangan) × 100%. Persentase panggilan LLM yang dihemat. Makin besar makin hemat.',
        'Cross-validation 5-fold' => 'Data dibagi 5 bagian. Bergantian, 4 bagian dipakai memilih ambang terbaik dan 1 bagian dipakai menilai. Hasilnya lebih jujur karena ambang tidak dinilai pada data yang dipakai untuk memilihnya.',
        '± sd (simpangan baku)' => 'Seberapa jauh nilai menyebar dari rata-ratanya. Kecil = stabil/konsisten. Pada F1 CV: sebaran F1 antar-fold. Pada uji konsistensi: sebaran skor AI antar-ulangan.',
        'ai_probability' => 'Skor 0–1 dari LLM tentang seberapa mungkin dokumen ditulis AI. Bukan peluang yang terkalibrasi, melainkan penilaian LLM. Penelitian memakai ≥ 0,50 = AI.',
        'Verdict AI' => 'Kemungkinan AI (≥ 0,70), Campuran (0,35–0,69), Ditulis manusia (< 0,35). Diturunkan sistem dari ai_probability.',
    ];
}
