<?php

namespace App\Support;

/**
 * Korpus contoh kecil (2 topik × 7 dokumen) untuk mencoba alur Lab Pengujian.
 *
 * PERINGATAN: seluruh teks ditulis oleh AI, termasuk yang berkategori "asli", "independen",
 * dan "parafrase manual". Korpus ini hanya untuk memeriksa bahwa sistem berjalan,
 * BUKAN untuk data penelitian.
 *
 * 'source' merujuk kunci dokumen sumber dalam topik yang sama.
 */
final class SampleCorpus
{
    public static function topics(): array
    {
        return [self::mediaSosial(), self::energiSurya()];
    }

    private static function mediaSosial(): array
    {
        $original = 'Media sosial sekarang hampir tidak bisa dipisahkan dari kehidupan mahasiswa. Dari pengamatan saya di kelas, banyak teman yang membuka Instagram atau TikTok bahkan saat dosen sedang menjelaskan. Kebiasaan ini membuat konsentrasi gampang buyar dan materi yang diterima jadi setengah-setengah. Di sisi lain, media sosial juga punya manfaat. Grup WhatsApp kelas sering dipakai untuk berbagi catatan dan informasi tugas, dan beberapa akun di YouTube menjelaskan materi kalkulus dengan cara yang lebih mudah dipahami daripada buku. Jadi masalahnya bukan di media sosialnya, tetapi di cara kita memakainya. Mahasiswa yang bisa membatasi waktu, misalnya mematikan notifikasi saat belajar, biasanya tetap bisa menjaga nilai. Menurut saya kampus perlu mengajak mahasiswa membuat jadwal penggunaan gawai yang lebih sehat daripada sekadar melarang.';

        return [
            'orig' => [
                'title' => 'Media sosial – esai asli',
                'category' => 'ORIGINAL',
                'text' => $original,
            ],
            'copy' => [
                'title' => 'Media sosial – salinan langsung',
                'category' => 'VERBATIM_COPY',
                'source' => 'orig',
                'text' => 'Tugas ini membahas pengaruh media sosial bagi mahasiswa. ' . $original . ' Demikian pendapat saya mengenai topik ini.',
            ],
            'para' => [
                'title' => 'Media sosial – parafrase manual',
                'category' => 'PARAPHRASE_MANUAL',
                'source' => 'orig',
                'text' => 'Hampir semua mahasiswa sekarang tidak lepas dari media sosial. Saya sering melihat teman sekelas memainkan Instagram dan TikTok padahal dosen masih menerangkan di depan. Akibatnya fokus mereka mudah hilang dan pelajaran hanya masuk sebagian. Meski begitu, media sosial tidak selalu buruk. Kelas kami memakai grup WhatsApp untuk bertukar catatan dan kabar tugas, dan ada kanal YouTube yang menerangkan kalkulus lebih jelas daripada buku teks. Artinya yang menjadi persoalan adalah cara pemakaiannya. Teman yang disiplin membatasi waktu, contohnya dengan mematikan notifikasi ketika belajar, umumnya nilainya tetap terjaga. Saya rasa kampus lebih baik membantu mahasiswa menyusun jadwal pemakaian gawai daripada hanya membuat larangan.',
            ],
            'para_ai' => [
                'title' => 'Media sosial – parafrase oleh AI',
                'category' => 'PARAPHRASE_AI',
                'source' => 'orig',
                'text' => 'Media sosial telah menjadi bagian integral dari kehidupan mahasiswa. Berdasarkan observasi di lingkungan perkuliahan, tidak sedikit mahasiswa yang mengakses platform seperti Instagram dan TikTok selama proses pembelajaran berlangsung. Perilaku tersebut berpotensi menurunkan tingkat konsentrasi sehingga pemahaman terhadap materi menjadi kurang optimal. Namun demikian, media sosial juga menawarkan berbagai manfaat. Grup percakapan kelas kerap dimanfaatkan sebagai sarana berbagi catatan dan informasi akademik, sementara sejumlah kanal edukatif menyajikan materi secara lebih mudah dipahami. Dengan demikian, permasalahan utama terletak pada pola penggunaan, bukan pada media sosial itu sendiri. Mahasiswa yang mampu mengelola waktu penggunaan cenderung dapat mempertahankan prestasi akademiknya. Oleh karena itu, institusi pendidikan perlu mendorong pembentukan kebiasaan penggunaan gawai yang lebih sehat.',
            ],
            'indep1' => [
                'title' => 'Media sosial – esai independen 1',
                'category' => 'INDEPENDENT',
                'text' => 'Saya ingin melihat media sosial dari sisi kesehatan mental. Waktu semester tiga saya pernah merasa minder karena melihat unggahan teman yang seolah selalu sukses, ikut lomba, magang di perusahaan besar, dan liburan ke luar negeri. Perasaan itu justru membuat saya malas mengerjakan tugas karena merasa tertinggal. Setelah saya berhenti membuka aplikasi selama dua minggu, tidur saya lebih teratur dan saya bisa menyelesaikan laporan praktikum tepat waktu. Pengalaman ini membuat saya percaya bahwa dampak media sosial pada prestasi tidak selalu langsung, tetapi lewat suasana hati dan rasa percaya diri. Konselor kampus sebaiknya lebih sering membahas hal ini dalam kegiatan orientasi mahasiswa baru.',
            ],
            'indep2' => [
                'title' => 'Media sosial – esai independen 2',
                'category' => 'INDEPENDENT',
                'text' => 'Data kecil yang saya kumpulkan dari dua puluh teman angkatan menunjukkan hasil yang cukup menarik. Mereka yang mengaku memakai media sosial lebih dari lima jam sehari rata-rata memiliki IPK sedikit lebih rendah, tetapi perbedaannya tidak besar. Beberapa teman dengan pemakaian tinggi justru memakai media sosial untuk berjualan atau mengelola akun organisasi, sehingga waktunya tidak sepenuhnya terbuang. Karena sampelnya sedikit dan tidak acak, saya tidak berani menyimpulkan ada hubungan sebab akibat. Penelitian yang lebih serius perlu membedakan jenis aktivitas di media sosial, bukan hanya lamanya waktu pemakaian.',
            ],
            'ai_gen' => [
                'title' => 'Media sosial – ditulis AI',
                'category' => 'AI_GENERATED',
                'text' => 'Di era digital saat ini, media sosial memainkan peran yang sangat signifikan dalam kehidupan akademik mahasiswa. Di satu sisi, platform ini menyediakan akses yang luas terhadap informasi dan memfasilitasi kolaborasi antarmahasiswa. Di sisi lain, penggunaan yang berlebihan dapat menimbulkan distraksi yang berdampak negatif terhadap prestasi belajar. Penting untuk dicatat bahwa dampak tersebut sangat bergantung pada kemampuan individu dalam mengelola waktu. Selain itu, faktor lingkungan dan dukungan institusi juga turut berperan. Secara keseluruhan, dapat disimpulkan bahwa media sosial merupakan alat yang bermanfaat apabila digunakan secara bijak dan bertanggung jawab. Oleh karena itu, diperlukan upaya bersama dari mahasiswa, dosen, dan pihak kampus untuk membangun budaya digital yang sehat.',
            ],
        ];
    }

    private static function energiSurya(): array
    {
        $original = 'Indonesia sebenarnya punya modal besar untuk energi surya karena hampir sepanjang tahun mendapat sinar matahari. Sayangnya pemanfaatannya masih kecil. Di kampung saya di Nusa Tenggara Timur, beberapa rumah sudah memasang panel surya bantuan pemerintah, tetapi banyak yang rusak setelah dua atau tiga tahun karena baterainya tidak pernah diganti dan tidak ada teknisi di dekat desa. Masalah lain adalah harga awal yang mahal untuk rumah tangga biasa. Menurut saya program energi surya tidak cukup hanya membagikan panel. Pemerintah juga perlu melatih warga setempat untuk merawat sistemnya dan menyediakan suku cadang yang mudah dibeli. Skema cicilan lewat koperasi desa juga bisa membantu keluarga yang ingin memasang sendiri.';

        return [
            'orig' => [
                'title' => 'Energi surya – esai asli',
                'category' => 'ORIGINAL',
                'text' => $original,
            ],
            'copy' => [
                'title' => 'Energi surya – salinan langsung',
                'category' => 'VERBATIM_COPY',
                'source' => 'orig',
                'text' => $original . ' Dengan langkah-langkah tersebut, energi surya bisa lebih berkembang di daerah terpencil.',
            ],
            'para' => [
                'title' => 'Energi surya – parafrase manual',
                'category' => 'PARAPHRASE_MANUAL',
                'source' => 'orig',
                'text' => 'Sinar matahari di Indonesia melimpah hampir sepanjang tahun, jadi potensi listrik tenaga surya sangat besar. Tetapi sampai sekarang pemakaiannya masih sedikit. Di daerah asal saya di NTT, ada beberapa rumah yang mendapat panel surya dari pemerintah, namun banyak yang tidak berfungsi lagi setelah dua sampai tiga tahun. Penyebabnya baterai tidak pernah diganti dan tidak ada teknisi yang tinggal dekat desa. Biaya pemasangan awal juga terlalu mahal untuk keluarga kebanyakan. Saya berpendapat program tenaga surya tidak boleh berhenti pada pembagian panel saja. Warga desa harus dilatih merawat perangkatnya, dan suku cadang harus mudah didapat. Pembayaran cicilan melalui koperasi desa juga dapat menolong keluarga yang mau memasang panel sendiri.',
            ],
            'para_ai' => [
                'title' => 'Energi surya – parafrase oleh AI',
                'category' => 'PARAPHRASE_AI',
                'source' => 'orig',
                'text' => 'Indonesia memiliki potensi energi surya yang sangat besar mengingat intensitas penyinaran matahari yang tinggi sepanjang tahun. Namun demikian, tingkat pemanfaatannya masih relatif rendah. Di wilayah Nusa Tenggara Timur, sejumlah rumah tangga telah menerima bantuan panel surya dari pemerintah, akan tetapi banyak di antaranya mengalami kerusakan dalam kurun waktu dua hingga tiga tahun akibat tidak adanya penggantian baterai serta keterbatasan tenaga teknis di sekitar desa. Selain itu, tingginya biaya investasi awal menjadi kendala bagi masyarakat. Oleh karena itu, program energi surya perlu dilengkapi dengan pelatihan perawatan bagi masyarakat setempat dan ketersediaan suku cadang yang terjangkau. Skema pembiayaan melalui koperasi desa juga dapat menjadi solusi yang efektif.',
            ],
            'indep1' => [
                'title' => 'Energi surya – esai independen 1',
                'category' => 'INDEPENDENT',
                'text' => 'Kampus kami memasang panel surya di atap gedung perpustakaan sejak tahun lalu. Saya sempat mewawancarai bagian sarana prasarana, dan ternyata panel itu hanya menutup sekitar delapan persen kebutuhan listrik gedung. Angka ini terlihat kecil, tetapi tagihan listrik bulanan turun cukup terasa. Kendala terbesar justru debu dan kotoran burung yang menempel sehingga panel harus dibersihkan rutin. Saya kira kampus lain bisa meniru langkah ini sambil menjadikannya bahan praktikum bagi mahasiswa teknik elektro, supaya investasi tersebut juga memberi manfaat pendidikan.',
            ],
            'indep2' => [
                'title' => 'Energi surya – esai independen 2',
                'category' => 'INDEPENDENT',
                'text' => 'Banyak orang mengira energi surya pasti ramah lingkungan, padahal ada persoalan limbah yang jarang dibicarakan. Panel dan baterai yang sudah tidak terpakai mengandung bahan yang berbahaya jika dibuang sembarangan. Di Indonesia belum banyak fasilitas daur ulang khusus untuk perangkat ini. Kalau pemasangan panel surya terus bertambah tanpa aturan pengelolaan limbah, dua puluh tahun lagi kita bisa menghadapi masalah baru. Jadi selain mendorong pemasangan, pemerintah perlu menyiapkan aturan tanggung jawab produsen dan tempat pengumpulan perangkat bekas.',
            ],
            'ai_gen' => [
                'title' => 'Energi surya – ditulis AI',
                'category' => 'AI_GENERATED',
                'text' => 'Energi surya merupakan salah satu sumber energi terbarukan yang memiliki prospek cerah di Indonesia. Sebagai negara tropis, Indonesia menerima radiasi matahari yang melimpah sepanjang tahun. Pemanfaatan energi surya tidak hanya berkontribusi terhadap pengurangan emisi gas rumah kaca, tetapi juga dapat meningkatkan akses listrik di daerah terpencil. Meskipun demikian, terdapat sejumlah tantangan yang perlu diatasi, antara lain biaya investasi awal yang tinggi, keterbatasan infrastruktur, serta kurangnya sumber daya manusia yang kompeten. Penting untuk dicatat bahwa keberhasilan transisi energi memerlukan sinergi antara pemerintah, sektor swasta, dan masyarakat. Dengan strategi yang tepat, energi surya dapat menjadi pilar utama ketahanan energi nasional.',
            ],
        ];
    }
}
