<?php

namespace App\Infrastructure\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Prex API v1',
    description: 'Autenticación, catálogo GIPHY y favoritos. Enviar Accept: application/json y Content-Type: application/json para los cuerpos JSON. Los textos conservan espacios y mayúsculas. Los ejemplos son sintéticos.',
    license: new OA\License(name: 'MIT', url: 'https://opensource.org/license/mit'),
)]
#[OA\Server(url: '/', description: 'Mismo origen que Swagger UI')]
#[OA\Tag(name: 'Autenticación', description: 'Login público y emisión de tokens personales.')]
#[OA\Tag(name: 'GIFs', description: 'Búsqueda y consulta mediante GIPHY.')]
#[OA\Tag(name: 'Favoritos', description: 'Guardado de GIFs del usuario autenticado.')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'Ejecutar login y copiar access_token en Authorize, sin el prefijo Bearer. Vence en 1800 segundos. No se conserva al recargar la página.',
    scheme: 'bearer',
)]
#[OA\Header(header: 'RequestId', description: 'UUID generado por el servidor para correlacionar la auditoría; no reutiliza el valor enviado por el cliente.', schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Header(header: 'RateLimit', description: 'Máximo de intentos de login por minuto y por IP.', schema: new OA\Schema(type: 'integer', enum: [5]))]
#[OA\Header(header: 'RateRemaining', description: 'Intentos de login restantes en la ventana actual.', schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 5))]
final class Specification {}
