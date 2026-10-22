<?php

namespace App\Providers;

use App\Application\Audit\RecordInteraction;
use App\Application\Audit\Redactor;
use App\Domain\Audit\InteractionRepository;
use App\Domain\Auth\Authenticator;
use App\Domain\Favorites\FavoriteGifRepository;
use App\Domain\Gifs\GifCatalog;
use App\Infrastructure\Audit\AuditFallback;
use App\Infrastructure\Audit\EloquentInteractionRepository;
use App\Infrastructure\Audit\HttpInteractionCapture;
use App\Infrastructure\Auth\PassportAuthenticator;
use App\Infrastructure\Favorites\EloquentFavoriteGifRepository;
use App\Infrastructure\Giphy\GiphyHttpGifCatalog;
use App\Infrastructure\Observability\MetricsStore;
use App\Infrastructure\Observability\RecordHttpRequest;
use App\Infrastructure\Observability\SafeEvents;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Authenticator::class, PassportAuthenticator::class);
        $this->app->bind(GifCatalog::class, GiphyHttpGifCatalog::class);
        $this->app->bind(FavoriteGifRepository::class, EloquentFavoriteGifRepository::class);
        $this->app->scoped(SafeEvents::class);
        $this->app->scoped(MetricsStore::class, fn () => new MetricsStore(config('observability.database')));
        Passport::ignoreRoutes();
        $this->app->bind(InteractionRepository::class, EloquentInteractionRepository::class);
        $this->app->bind(Redactor::class);
        $this->app->bind(RecordInteraction::class);
        $this->app->bind(HttpInteractionCapture::class);
        $this->app->bind(AuditFallback::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['events']->listen(RequestHandled::class, [RecordHttpRequest::class, 'handle']);
        Passport::loadKeysFrom(storage_path('passport'));
        Passport::personalAccessTokensExpireIn(new \DateInterval('PT30M'));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip())
        );
    }
}
