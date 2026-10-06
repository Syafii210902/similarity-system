<?php

namespace App\Providers;

use App\Repositories\Contracts\SimilarityRepositoryInterface;
use App\Repositories\Eloquent\SimilarityRepository;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SimilarityRepositoryInterface::class, SimilarityRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nama hari/bulan & diffForHumans dalam Bahasa Indonesia.
        Carbon::setLocale('id');
    }
}
