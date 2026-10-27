<?php

namespace Tests\Feature;

use App\Models\ExperimentDataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExperimentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $peneliti;

    private ExperimentDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->peneliti = User::factory()->peneliti()->create();
        $this->dataset = ExperimentDataset::create(['name' => 'Korpus impor', 'created_by' => $this->peneliti->id]);
    }

    private function manifest(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('manifest.csv', $csv);
    }

    private function import(string $csv, array $files = [])
    {
        return $this->actingAs($this->peneliti)->post(route('peneliti.documents.import', $this->dataset), [
            'manifest' => $this->manifest($csv),
            'files' => $files,
        ]);
    }

    public function test_imports_files_and_text_rows_with_sources_in_any_order(): void
    {
        $long = str_repeat('Kalimat uji yang cukup panjang. ', 5);
        // Excel Indonesia: titik koma + BOM; turunan ditulis sebelum sumbernya, dan berantai.
        $csv = "\xEF\xBB\xBFBerkas;Judul;Kategori;Sumber;Teks\n"
            . "b.txt;Esai 1 - parafrase;Parafrase manual;a.pdf;\n"
            . ";Esai 1 - salinan dari parafrase;salinan langsung;Esai 1 - parafrase;\"{$long}\"\n"
            . "a.pdf;Esai 1 - asli;asli;;\n"
            . ";Esai AI;ditulis ai;;\"{$long}\"\n";

        $this->import($csv, [
            UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->createWithContent('b.txt', $long),
        ])->assertRedirect()->assertSessionHas('success', '4 dokumen berhasil diimpor.');

        $docs = $this->dataset->documents()->get()->keyBy('title');
        $this->assertCount(4, $docs);
        $this->assertSame('ORIGINAL', $docs['Esai 1 - asli']->category);
        $this->assertSame($docs['Esai 1 - asli']->id, $docs['Esai 1 - parafrase']->source_document_id);
        $this->assertSame($docs['Esai 1 - parafrase']->id, $docs['Esai 1 - salinan dari parafrase']->source_document_id);
        $this->assertSame('AI_GENERATED', $docs['Esai AI']->category);
        $this->assertNull($docs['Esai AI']->source_document_id);
        foreach ($docs as $doc) {
            Storage::disk('public')->assertExists($doc->file_path);
        }
    }

    public function test_source_can_refer_to_existing_document_in_dataset(): void
    {
        $this->dataset->documents()->create([
            'title' => 'Esai Lama', 'category' => 'ORIGINAL', 'file_path' => 'x.txt', 'file_name' => 'x.txt',
        ]);

        $this->import("berkas,judul,kategori,sumber\nc.txt,,parafrase ai,esai lama\n", [
            UploadedFile::fake()->createWithContent('c.txt', str_repeat('isi ', 30)),
        ])->assertSessionHas('success');

        $doc = $this->dataset->documents()->where('title', 'c')->firstOrFail();
        $this->assertSame('PARAPHRASE_AI', $doc->category);
        $this->assertSame($this->dataset->documents()->where('title', 'Esai Lama')->value('id'), $doc->source_document_id);
    }

    public function test_any_error_rejects_the_whole_import_with_row_messages(): void
    {
        $csv = "berkas,judul,kategori,sumber\n"
            . "a.pdf,A,asli,\n"
            . "hilang.pdf,B,asli,\n"
            . "c.txt,C,parafrase manual,\n"
            . "d.txt,D,entah,\n";

        $this->import($csv, [
            UploadedFile::fake()->create('a.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->createWithContent('c.txt', 'isi'),
            UploadedFile::fake()->createWithContent('d.txt', 'isi'),
            UploadedFile::fake()->createWithContent('lebih.txt', 'isi'),
        ])->assertSessionHas('import_errors', function (array $errors) {
            $all = implode("\n", $errors);

            return str_contains($all, 'Baris 3: berkas "hilang.pdf" tidak ikut diunggah')
                && str_contains($all, 'Baris 4: dokumen turunan')
                && str_contains($all, 'Baris 5: kategori "entah" tidak dikenal')
                && str_contains($all, '"lebih.txt"');
        });

        $this->assertSame(0, $this->dataset->documents()->count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_rejects_cyclic_sources(): void
    {
        $csv = "berkas,judul,kategori,sumber\na.txt,A,salinan langsung,B\nb.txt,B,salinan langsung,A\n";

        $this->import($csv, [
            UploadedFile::fake()->createWithContent('a.txt', 'isi'),
            UploadedFile::fake()->createWithContent('b.txt', 'isi'),
        ])->assertSessionHas('import_errors', fn ($e) => str_contains(implode(' ', $e), 'lingkaran'));

        $this->assertSame(0, $this->dataset->documents()->count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_bad_header_and_template_download(): void
    {
        $this->import("nama,jenis\nx,y\n")
            ->assertSessionHas('import_errors', fn ($e) => str_contains($e[0], 'Header CSV'));

        $response = $this->actingAs($this->peneliti)->get(route('peneliti.documents.template'));
        $response->assertOk();
        $this->assertStringContainsString('berkas;judul;kategori;sumber;teks', $response->streamedContent());
    }

    public function test_only_peneliti_can_import(): void
    {
        $dosen = User::factory()->create(['role' => 'dosen']);

        $this->actingAs($dosen)->post(route('peneliti.documents.import', $this->dataset), [
            'manifest' => $this->manifest("berkas,kategori\n"),
        ])->assertForbidden();
    }

    public function test_dataset_page_shows_import_panel(): void
    {
        $this->actingAs($this->peneliti)->get(route('peneliti.datasets.show', $this->dataset))
            ->assertOk()
            ->assertSee('Impor massal')
            ->assertSee(route('peneliti.documents.template'), false);
    }
}
