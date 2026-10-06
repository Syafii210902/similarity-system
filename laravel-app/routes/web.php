<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dosen\AnalysisController as DosenAnalysisController;
use App\Http\Controllers\Dosen\AnalysisResultController as DosenAnalysisResultController;
use App\Http\Controllers\Dosen\AssignmentController as DosenAssignmentController;
use App\Http\Controllers\Dosen\CourseController as DosenCourseController;
use App\Http\Controllers\Dosen\CourseStudentController as DosenCourseStudentController;
use App\Http\Controllers\Dosen\EvaluationController as DosenEvaluationController;
use App\Http\Controllers\Dosen\GroundTruthController as DosenGroundTruthController;
use App\Http\Controllers\Mahasiswa\CourseController as MahasiswaCourseController;
use App\Http\Controllers\Peneliti\CompareController as PenelitiCompareController;
use App\Http\Controllers\Peneliti\DatasetController as PenelitiDatasetController;
use App\Http\Controllers\Peneliti\DocumentController as PenelitiDocumentController;
use App\Http\Controllers\Peneliti\LabController as PenelitiLabController;
use App\Http\Controllers\Peneliti\RunController as PenelitiRunController;
use App\Http\Controllers\Mahasiswa\SubmissionController as MahasiswaSubmissionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
    });

    Route::middleware('role:dosen')->prefix('dosen')->name('dosen.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'dosen'])->name('dashboard');

        Route::resource('courses', DosenCourseController::class);
        Route::post('/courses/{course}/students', [DosenCourseStudentController::class, 'store'])->name('courses.students.store');
        Route::delete('/courses/{course}/students/{student}', [DosenCourseStudentController::class, 'destroy'])->name('courses.students.destroy');

        Route::resource('courses.assignments', DosenAssignmentController::class)->shallow()->except('index');
        Route::post('/assignments/{assignment}/close', [DosenAssignmentController::class, 'close'])->name('assignments.close');
        Route::post('/assignments/{assignment}/reopen', [DosenAssignmentController::class, 'reopen'])->name('assignments.reopen');
        Route::get('/submissions/{submission}/download', [DosenAssignmentController::class, 'download'])->name('submissions.download');

        Route::post('/assignments/{assignment}/analysis', [DosenAnalysisController::class, 'store'])->name('assignments.analysis.store');
        Route::get('/assignments/{assignment}/analysis/status', [DosenAnalysisController::class, 'status'])->name('assignments.analysis.status');
        Route::get('/assignments/{assignment}/results', [DosenAnalysisResultController::class, 'index'])->name('assignments.results');
        Route::get('/assignments/{assignment}/results/pairs/{result}', [DosenAnalysisResultController::class, 'pair'])->name('assignments.results.pair');
        Route::post('/assignments/{assignment}/labels/pairs', [DosenGroundTruthController::class, 'storePair'])->name('assignments.labels.pair');
        Route::post('/assignments/{assignment}/labels/ai', [DosenGroundTruthController::class, 'storeAi'])->name('assignments.labels.ai');

        Route::get('/evaluation', [DosenEvaluationController::class, 'index'])->name('evaluation');
        Route::get('/evaluation/export/{type}', [DosenEvaluationController::class, 'export'])->name('evaluation.export');
    });

    Route::middleware('role:mahasiswa')->prefix('mahasiswa')->name('mahasiswa.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'mahasiswa'])->name('dashboard');
        Route::get('/courses/{course}', [MahasiswaCourseController::class, 'show'])->name('courses.show');

        Route::get('/assignments/{assignment}', [MahasiswaSubmissionController::class, 'show'])->name('assignments.show');
        Route::controller(MahasiswaSubmissionController::class)
            ->prefix('/assignments/{assignment}/submission')
            ->name('submissions.')
            ->group(function () {
                Route::post('/', 'store')->name('store');
                Route::put('/', 'update')->name('update');
                Route::delete('/', 'destroy')->name('destroy');
                Route::get('/download', 'download')->name('download');
            });
    });

    // Lab Pengujian: korpus uji berlabel & eksperimen metode (khusus peneliti).
    Route::middleware('role:peneliti')->prefix('peneliti')->name('peneliti.')->group(function () {
        Route::get('/', [PenelitiLabController::class, 'index'])->name('dashboard');

        Route::post('/datasets', [PenelitiDatasetController::class, 'store'])->name('datasets.store');
        Route::post('/datasets/sample', [PenelitiDatasetController::class, 'storeSample'])->name('datasets.sample');
        Route::get('/datasets/{dataset}', [PenelitiDatasetController::class, 'show'])->name('datasets.show');
        Route::delete('/datasets/{dataset}', [PenelitiDatasetController::class, 'destroy'])->name('datasets.destroy');

        Route::post('/datasets/{dataset}/documents', [PenelitiDocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [PenelitiDocumentController::class, 'show'])->name('documents.show');
        Route::delete('/documents/{document}', [PenelitiDocumentController::class, 'destroy'])->name('documents.destroy');

        Route::post('/datasets/{dataset}/runs', [PenelitiRunController::class, 'store'])->name('runs.store');
        Route::get('/runs/{run}', [PenelitiRunController::class, 'show'])->name('runs.show');
        Route::get('/runs/{run}/status', [PenelitiRunController::class, 'status'])->name('runs.status');
        Route::get('/runs/{run}/export', [PenelitiRunController::class, 'export'])->name('runs.export');
        Route::delete('/runs/{run}', [PenelitiRunController::class, 'destroy'])->name('runs.destroy');

        Route::get('/compare', [PenelitiCompareController::class, 'show'])->name('compare');
        Route::post('/compare', [PenelitiCompareController::class, 'run'])->name('compare.run');
    });
});
