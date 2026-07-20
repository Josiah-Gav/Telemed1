<?php

namespace App\Providers;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Policies\ConsultationPolicy;
use App\Policies\ConsultationSessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationSession::class, ConsultationSessionPolicy::class);
    }
}
