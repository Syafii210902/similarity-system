<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Models\ExperimentDataset;
use App\Models\ExperimentDocument;
use App\Services\ExperimentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        protected ExperimentService $experiments
    ) {}

    public function store(Request $request, ExperimentDataset $dataset): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(ExperimentDocument::CATEGORIES))],
            'source_document_id' => [
                Rule::requiredIf(in_array($request->input('category'), ExperimentDocument::DERIVED, true)),
                'nullable', 'integer',
                Rule::exists('experiment_documents', 'id')->where('dataset_id', $dataset->id),
            ],
            'file' => ['nullable', 'required_without:text', 'file', 'extensions:pdf,docx,txt', 'max:10240'],
            'text' => ['nullable', 'required_without:file', 'string', 'min:50', 'max:200000'],
        ], [
            'title.required' => 'Judul dokumen wajib diisi.',
            'source_document_id.required' => 'Dokumen turunan (salinan/parafrase) wajib memilih dokumen sumber.',
            'file.required_without' => 'Unggah berkas atau tempel teks.',
            'text.required_without' => 'Unggah berkas atau tempel teks.',
            'text.min' => 'Teks minimal 50 karakter.',
            'file.extensions' => 'Format berkas harus PDF, DOCX, atau TXT.',
        ]);

        $this->experiments->addDocument($dataset, $data, $request->file('file'), $request->hasFile('file') ? null : ($data['text'] ?? null));

        return redirect()->to(route('peneliti.datasets.show', $dataset) . '#dokumen')->with('success', "Dokumen “{$data['title']}” ditambahkan.");
    }

    public function show(ExperimentDocument $document): View|StreamedResponse
    {
        $text = $this->experiments->documentText($document);

        if ($text === null) {
            abort_unless(Storage::disk('public')->exists($document->file_path), 404);

            return Storage::disk('public')->download($document->file_path, $document->file_name);
        }

        $document->load('dataset:id,name', 'source:id,title');

        return view('peneliti.documents.show', compact('document', 'text'));
    }

    public function destroy(ExperimentDocument $document): RedirectResponse
    {
        $dataset = $document->dataset;
        $this->experiments->deleteDocument($document);

        return redirect()->to(route('peneliti.datasets.show', $dataset) . '#dokumen')->with('success', 'Dokumen dihapus.');
    }
}
