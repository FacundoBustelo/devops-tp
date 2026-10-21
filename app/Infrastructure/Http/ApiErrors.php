<?php

namespace App\Infrastructure\Http;

use App\Application\Audit\Redactor;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Favorites\FavoriteAlreadyExists;
use App\Infrastructure\Audit\HttpInteractionCapture;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiErrors
{
    public static function render(Response $response, Throwable $exception, Request $request): Response
    {
        if (! HttpInteractionCapture::includes($request)) {
            return $response;
        }
        $status = $response->getStatusCode();
        $message = match ($status) {
            401 => $exception instanceof InvalidCredentials ? 'Credenciales inválidas.' : 'No autenticado.', 403 => 'Acceso denegado.', 404 => 'Recurso no encontrado.',
            409 => $exception instanceof FavoriteAlreadyExists ? 'El favorito ya existe.' : 'Error HTTP.',
            405 => 'Método no permitido.', 422 => 'Datos inválidos.', 429 => 'Demasiadas solicitudes.',
            default => $status >= 500 ? 'Error del servidor.' : 'Error HTTP.',
        };
        $data = ['message' => $message];
        if ($exception instanceof ValidationException) {
            // Field names identify validation errors; only their messages may contain secrets.
            $redactor = app(Redactor::class);
            $data['errors'] = array_map(fn (array $messages) => $redactor->sanitize($messages), $exception->errors());
        }
        $headers = $response->headers->all();
        unset($headers['content-type'], $headers['content-length']);

        return response()->json($data, $status, $headers);
    }
}
