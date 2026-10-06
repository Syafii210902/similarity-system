<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * Entry point /dashboard: arahkan user ke dashboard sesuai role.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->dashboardRoute());
    }

    public function admin(): View
    {
        return view('admin.dashboard', $this->dashboardService->getAdminSummary());
    }

    public function dosen(Request $request): View
    {
        return view('dosen.dashboard', $this->dashboardService->getDosenSummary($request->user()));
    }

    public function mahasiswa(Request $request): View
    {
        return view('mahasiswa.dashboard', $this->dashboardService->getMahasiswaSummary($request->user()));
    }
}
