<?php

namespace App\Services;

use App\Models\ExperimentDataset;
use App\Models\ExperimentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Impor massal dokumen korpus uji: banyak berkas + satu CSV manifest.
 *
 * Kolom manifest (header wajib, urutan bebas, huruf besar/kecil bebas):
 *   berkas   : nama berkas yang ikut diunggah (kosongkan bila memakai kolom teks)
 *   judul    : judul dokumen (opsional; bawaan = nama berkas)
 *   kategori : asli | salinan langsung | parafrase manual | parafrase ai | independen | ditulis ai
 *              (atau kode: ORIGINAL, VERBATIM_COPY, ...)
 *   sumber   : untuk dokumen turunan, nama berkas/judul sumber di manifest ini,
 *              atau judul dokumen yang sudah ada di dataset
 *   teks     : (opsional) isi dokumen langsung, pengganti berkas
 *
 * Seluruh baris divalidasi dulu; bila ada satu kesalahan, tidak ada yang disimpan.
 */
class ExperimentImportService
{
    public const MAX_ROWS = 200;

    private const CATEGORY_ALIASES = [
        'asli' => 'ORIGINAL', 'original' => 'ORIGINAL',
        'salinan langsung' => 'VERBATIM_COPY', 'salinan' => 'VERBATIM_COPY', 'verbatim' => 'VERBATIM_COPY', 'verbatim_copy' => 'VERBATIM_COPY',
        'parafrase manual' => 'PARAPHRASE_MANUAL', 'parafrase' => 'PARAPHRASE_MANUAL', 'paraphrase_manual' => 'PARAPHRASE_MANUAL',
        'parafrase ai' => 'PARAPHRASE_AI', 'parafrase oleh ai' => 'PARAPHRASE_AI', 'paraphrase_ai' => 'PARAPHRASE_AI',
        'independen' => 'INDEPENDENT', 'independen (topik sama)' => 'INDEPENDENT', 'independent' => 'INDEPENDENT',
        'ditulis ai' => 'AI_GENERATED', 'ai' => 'AI_GENERATED', 'ai_generated' => 'AI_GENERATED',
    ];

    public function __construct(
        protected ExperimentService $experiments
    ) {}

    /**
     * @param  UploadedFile[]  $files
     * @return array{imported: int, errors: string[]}
     */
    public function import(ExperimentDataset $dataset, UploadedFile $manifest, array $files): array
    {
        [$rows, $errors] = $this->parseManifest($manifest);
        if ($errors) {
            return ['imported' => 0, 'errors' => $errors];
        }

        $filesByName = [];
        foreach ($files as $file) {
            $filesByName[mb_strtolower($file->getClientOriginalName())] = $file;
        }

        $existingTitles = $dataset->documents()->pluck('id', 'title')->mapWithKeys(fn ($id, $title) => [mb_strtolower($title) => $id]);
        [$plan, $errors] = $this->validateRows($rows, $filesByName, $existingTitles);
        if ($errors) {
            return ['imported' => 0, 'errors' => $errors];
        }

        $created = [];
        try {
            DB::transaction(function () use ($dataset, $plan, $existingTitles, &$created) {
                $idsByKey = [];
                // Buat dokumen sumber lebih dulu: ulangi sampai semua sumbernya sudah ada (mendukung rantai turunan).
                $pending = $plan;
                while ($pending) {
                    $progress = false;
                    foreach ($pending as $i => $row) {
                        $sourceId = null;
                        if ($row['source_key'] !== null) {
                            $sourceId = $idsByKey[$row['source_key']] ?? null;
                            if ($sourceId === null) {
                                continue; // sumber belum dibuat
                            }
                        } elseif ($row['source_existing'] !== null) {
                            $sourceId = $existingTitles[$row['source_existing']];
                        }

                        $document = $this->experiments->addDocument($dataset, [
                            'title' => $row['title'],
                            'category' => $row['category'],
                            'source_document_id' => $sourceId,
                        ], $row['file'], $row['text']);

                        $created[] = $document->file_path;
                        foreach ($row['keys'] as $key) {
                            $idsByKey[$key] = $document->id;
                        }
                        unset($pending[$i]);
                        $progress = true;
                    }
                    if (!$progress) {
                        throw new \RuntimeException('Rujukan sumber membentuk lingkaran.');
                    }
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($created);

            return ['imported' => 0, 'errors' => ['Impor dibatalkan: ' . $e->getMessage()]];
        }

        return ['imported' => count($plan), 'errors' => []];
    }

    /** @return array{0: array<int, array>, 1: string[]} */
    private function parseManifest(UploadedFile $manifest): array
    {
        $content = (string) file_get_contents($manifest->getRealPath());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // BOM dari Excel
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (!$lines || trim($lines[0]) === '') {
            return [[], ['Manifest CSV kosong.']];
        }

        // Excel versi Indonesia memakai titik koma sebagai pemisah.
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $delimiter, '"', ''));

        if (!in_array('kategori', $header, true) || (!in_array('berkas', $header, true) && !in_array('teks', $header, true))) {
            return [[], ['Header CSV harus memuat kolom "kategori" dan "berkas" (atau "teks"). Unduh templat untuk contoh.']];
        }

        $rows = [];
        // Baca ulang dengan fgetcsv agar teks bermultibaris dalam tanda kutip tetap utuh.
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        fgetcsv($handle, null, $delimiter, '"', '');
        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = ['line' => $line] + $row;
        }
        fclose($handle);

        if (!$rows) {
            return [[], ['Manifest CSV tidak berisi baris data.']];
        }
        if (count($rows) > self::MAX_ROWS) {
            return [[], ['Maksimal ' . self::MAX_ROWS . ' dokumen per impor.']];
        }

        return [$rows, []];
    }

    /** @return array{0: array<int, array>, 1: string[]} */
    private function validateRows(array $rows, array $filesByName, $existingTitles): array
    {
        $errors = [];
        $plan = [];
        $keys = []; // nama berkas & judul (huruf kecil) yang dibuat oleh impor ini

        foreach ($rows as $row) {
            $berkas = $row['berkas'] ?? '';
            $title = ($row['judul'] ?? '') !== '' ? $row['judul'] : pathinfo($berkas, PATHINFO_FILENAME);
            foreach (array_filter([mb_strtolower($berkas), mb_strtolower($title)]) as $key) {
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }
        }

        $usedFiles = [];
        foreach ($rows as $row) {
            $at = "Baris {$row['line']}";
            $berkas = $row['berkas'] ?? '';
            $text = $row['teks'] ?? '';
            $title = ($row['judul'] ?? '') !== '' ? $row['judul'] : pathinfo($berkas, PATHINFO_FILENAME);
            $category = self::CATEGORY_ALIASES[mb_strtolower($row['kategori'] ?? '')]
                ?? (array_key_exists(strtoupper($row['kategori'] ?? ''), ExperimentDocument::CATEGORIES) ? strtoupper($row['kategori']) : null);

            $file = null;
            if ($berkas !== '') {
                $file = $filesByName[mb_strtolower($berkas)] ?? null;
                if (!$file) {
                    $errors[] = "{$at}: berkas \"{$berkas}\" tidak ikut diunggah.";
                } elseif (!in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'docx', 'txt'], true)) {
                    $errors[] = "{$at}: format \"{$berkas}\" harus PDF, DOCX, atau TXT.";
                } elseif ($file->getSize() > 10 * 1024 * 1024) {
                    $errors[] = "{$at}: \"{$berkas}\" lebih dari 10 MB.";
                }
                $usedFiles[mb_strtolower($berkas)] = true;
            } elseif (mb_strlen($text) < 50) {
                $errors[] = "{$at}: isi kolom \"berkas\", atau kolom \"teks\" minimal 50 karakter.";
            }

            if ($title === '') {
                $errors[] = "{$at}: judul kosong.";
            }
            if ($category === null) {
                $errors[] = "{$at}: kategori \"" . ($row['kategori'] ?? '') . '" tidak dikenal. Pilihan: asli, salinan langsung, parafrase manual, parafrase ai, independen, ditulis ai.';
            }

            $sourceKey = null;
            $sourceExisting = null;
            $source = mb_strtolower($row['sumber'] ?? '');
            if ($category && in_array($category, ExperimentDocument::DERIVED, true)) {
                if ($source === '') {
                    $errors[] = "{$at}: dokumen turunan (" . ExperimentDocument::CATEGORIES[$category] . ') wajib mengisi kolom "sumber".';
                } elseif (isset($keys[$source])) {
                    if ($keys[$source] > 1) {
                        $errors[] = "{$at}: sumber \"{$row['sumber']}\" ambigu (ada lebih dari satu dokumen dengan nama/judul itu).";
                    }
                    if (in_array($source, [mb_strtolower($berkas), mb_strtolower($title)], true)) {
                        $errors[] = "{$at}: dokumen tidak boleh menjadi sumber dirinya sendiri.";
                    }
                    $sourceKey = $source;
                } elseif ($existingTitles->has($source)) {
                    $sourceExisting = $source;
                } else {
                    $errors[] = "{$at}: sumber \"{$row['sumber']}\" tidak ditemukan di manifest maupun di dataset.";
                }
            }

            $plan[] = [
                'title' => mb_substr($title, 0, 200),
                'category' => $category,
                'file' => $file,
                'text' => $file ? null : $text,
                'source_key' => $sourceKey,
                'source_existing' => $sourceExisting,
                'keys' => array_values(array_unique(array_filter([mb_strtolower($berkas), mb_strtolower($title)]))),
            ];
        }

        $unused = array_diff(array_keys($filesByName), array_keys($usedFiles));
        if ($unused) {
            $errors[] = 'Berkas berikut diunggah tetapi tidak ada di manifest: ' . implode(', ', array_map(fn ($n) => "\"{$n}\"", $unused)) . '.';
        }

        return [$plan, $errors];
    }

    public static function templateCsv(): string
    {
        $rows = [
            ['berkas', 'judul', 'kategori', 'sumber', 'teks'],
            ['esai01.pdf', 'Esai 01 - asli', 'asli', '', ''],
            ['esai01_salin.docx', 'Esai 01 - salinan', 'salinan langsung', 'esai01.pdf', ''],
            ['esai01_parafrase.docx', 'Esai 01 - parafrase relawan', 'parafrase manual', 'esai01.pdf', ''],
            ['esai01_parafrase_ai.txt', 'Esai 01 - parafrase ChatGPT', 'parafrase ai', 'esai01.pdf', ''],
            ['esai02.pdf', 'Esai 02 - independen', 'independen', '', ''],
            ['', 'Esai AI 01', 'ditulis ai', '', 'Tempel isi dokumen di sini bila tidak memakai berkas...'],
        ];
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }
}
