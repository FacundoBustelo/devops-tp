<?php

namespace App\Infrastructure\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Response(
    response: 'InvalidCredentials', description: 'Email o contraseña incorrectos.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Credenciales inválidas.']),
)]
#[OA\Response(
    response: 'Unauthenticated', description: 'Token ausente, inválido, vencido o revocado.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'No autenticado.']),
)]
#[OA\Response(
    response: 'Forbidden', description: 'USER_ID no coincide con el usuario autenticado.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Acceso denegado.']),
)]
#[OA\Response(
    response: 'NotFound', description: 'El GIF solicitado no existe.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Recurso no encontrado.']),
)]
#[OA\Response(
    response: 'Conflict', description: 'Ya existe un favorito del usuario para ese GIF; el alias no se modifica.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'El favorito ya existe.']),
)]
#[OA\Response(
    response: 'ServerError', description: 'Fallo interno de infraestructura.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Error del servidor.']),
)]
#[OA\Response(
    response: 'BadGateway', description: 'GIPHY devolvió una respuesta incompatible.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Error del servidor.']),
)]
#[OA\Response(
    response: 'Unavailable', description: 'GIPHY no está disponible, rechazó la clave, agotó la cuota o falta configuración.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Error del servidor.']),
)]
#[OA\Response(
    response: 'Timeout', description: 'Timeout de GIPHY (conexión 2 s, total 5 s por defecto), sin reintentos.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Error del servidor.']),
)]
#[OA\Response(
    response: 'Validation', description: 'Datos inválidos. Las entradas no se recortan ni se convierten a null.',
    headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')],
    content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
)]
#[OA\Response(
    response: 'Throttled', description: 'Sexto intento de login en un minuto por IP. Los intentos inválidos también cuentan.',
    headers: [
        new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId'),
        new OA\Header(header: 'X-RateLimit-Limit', ref: '#/components/headers/RateLimit'),
        new OA\Header(header: 'X-RateLimit-Remaining', ref: '#/components/headers/RateRemaining'),
        new OA\Header(header: 'Retry-After', description: 'Segundos hasta poder reintentar.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Header(header: 'X-RateLimit-Reset', description: 'Instante Unix de reinicio de la ventana.', schema: new OA\Schema(type: 'integer')),
    ],
    content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['message' => 'Demasiadas solicitudes.']),
)]
final class Responses {}
