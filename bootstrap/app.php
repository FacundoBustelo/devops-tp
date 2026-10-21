<?php

use App\Domain\Auth\InvalidCredentials;
use App\Domain\Favorites\FavoriteAlreadyExists;
use App\Domain\Favorites\IncorrectFavoriteOwner;
use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\GifNotFound;
use App\Domain\Gifs\InvalidCatalogResponse;
use App\Infrastructure\Audit\HttpInteractionCapture;
use App\Infrastructure\Console\InitializeApplication;
use App\Infrastructure\Http\ApiErrors;
use App\Infrastructure\Http\AuditHttp;
use App\Infrastructure\Http\ExplicitTrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([InitializeApplication::class])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AuditHttp::class);
        $middleware->redirectGuestsTo(fn ($request) => HttpInteractionCapture::includes($request) ? null : '/');
        $middleware->trimStrings(except: [fn ($request) => $request->is('api/v1/login', 'api/v1/favorites', 'api/v1/gifs', 'api/v1/gifs/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => $request->is('api/v1/login', 'api/v1/favorites', 'api/v1/gifs', 'api/v1/gifs/*')]);
        $middleware->replace(TrustProxies::class, ExplicitTrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $exception) => HttpInteractionCapture::includes($request) || $request->expectsJson());
        $exceptions->render(fn (InvalidCredentials $exception) => response()->json(['message' => 'Credenciales inválidas.'], 401));
        $exceptions->dontReport([FavoriteAlreadyExists::class, IncorrectFavoriteOwner::class, CatalogUnavailable::class, CatalogTimeout::class, InvalidCatalogResponse::class, GifNotFound::class]);
        $exceptions->render(fn (GifNotFound $e) => response()->json(['message' => 'Recurso no encontrado.'], 404));
        $exceptions->render(fn (InvalidCatalogResponse $e) => response()->json(['message' => 'Error del servidor.'], 502));
        $exceptions->render(fn (CatalogUnavailable $e) => response()->json(['message' => 'Error del servidor.'], 503));
        $exceptions->render(fn (CatalogTimeout $e) => response()->json(['message' => 'Error del servidor.'], 504));
        $exceptions->render(fn (FavoriteAlreadyExists $e) => response()->json(['message' => 'El favorito ya existe.'], 409));
        $exceptions->render(fn (IncorrectFavoriteOwner $e) => response()->json(['message' => 'Acceso denegado.'], 403));
        $exceptions->respond(ApiErrors::render(...));
    })->create();
