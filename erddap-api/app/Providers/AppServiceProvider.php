<?php

namespace App\Providers;
use Illuminate\Support\Facades\Schema;

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
        // AJOUTER CE BLOC POUR CORRIGER L'ERREUR DE CLÉ TROP LONGUE
        // Ceci définit une longueur de chaîne par défaut pour les index créés par Laravel.
        Schema::defaultStringLength(191);
    }
}
