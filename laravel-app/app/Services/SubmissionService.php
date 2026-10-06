<?php

namespace App\Services;

use App\Models\AiDetectionResult;
use App\Models\AiLabel;
use App\Models\Assignment;
use App\Models\PairLabel;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmissionService
{
    /**
     * Disk "public" agar FastAPI bisa mengunduh file via http://laravel/storage/...
     * Nama file disimpan sebagai UUID (tidak bisa ditebak), ekstensi dipertahankan
     * karena FastAPI menentukan parser (PDF/DOCX) dari ekstensi URL.
     */
    private const DISK = 'public';

    public function findFor(Assignment $assignment, User $student): ?Submission
    {
        return $assignment->submissions()->where('user_id', $student->id)->first();
    }

    public function store(Assignment $assignment, User $student, UploadedFile $file): Submission
    {
        $path = $this->putFile($assignment, $file);

        try {
            return $assignment->submissions()->create([
                'user_id' => $student->id,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    public function replace(Submission $submission, UploadedFile $file): Submission
    {
        $oldPath = $submission->file_path;
        $newPath = $this->putFile($submission->assignment, $file);

        try {
            DB::transaction(function () use ($submission, $file, $newPath) {
                $submission->update([
                    'file_path' => $newPath,
                    'file_name' => $file->getClientOriginalName(),
                ]);

                // Hasil analisis lama tidak lagi merepresentasikan dokumen baru.
                $this->clearAnalysisResults($submission);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($newPath);
            throw $e;
        }

        Storage::disk(self::DISK)->delete($oldPath);

        return $submission->refresh();
    }

    public function delete(Submission $submission): void
    {
        $path = $submission->file_path;

        // similarity_results & ai_detection_results ikut terhapus via FK cascade.
        $submission->delete();

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Hapus berkas fisik milik sekumpulan pengumpulan. Dipakai saat tugas/mata kuliah
     * dihapus, karena FK cascade hanya menghapus baris database, bukan berkasnya.
     *
     * @param  iterable<Submission>  $submissions
     */
    public function deleteFilesOf(iterable $submissions): void
    {
        $paths = collect($submissions)->pluck('file_path')->filter()->all();

        if ($paths) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    public function fileExists(Submission $submission): bool
    {
        return Storage::disk(self::DISK)->exists($submission->file_path);
    }

    public function fileSize(Submission $submission): ?int
    {
        return $this->fileExists($submission) ? Storage::disk(self::DISK)->size($submission->file_path) : null;
    }

    public function download(Submission $submission)
    {
        return Storage::disk(self::DISK)->download($submission->file_path, $submission->file_name);
    }

    private function putFile(Assignment $assignment, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $file->storeAs(
            "submissions/{$assignment->id}",
            Str::uuid() . '.' . $extension,
            self::DISK
        );
    }

    private function clearAnalysisResults(Submission $submission): void
    {
        SimilarityResult::query()
            ->where('submission_a_id', $submission->id)
            ->orWhere('submission_b_id', $submission->id)
            ->delete();

        AiDetectionResult::where('submission_id', $submission->id)->delete();

        // Label ground truth mengacu pada isi berkas lama, sehingga tidak berlaku lagi.
        PairLabel::where('submission_low_id', $submission->id)
            ->orWhere('submission_high_id', $submission->id)
            ->delete();
        AiLabel::where('submission_id', $submission->id)->delete();
    }
}
