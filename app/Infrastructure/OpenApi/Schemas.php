<?php

namespace App\Infrastructure\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginInput', type: 'object', required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'persona@example.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 1, writeOnly: true, description: 'Contraseña del usuario; se conservan los espacios.'),
    ],
)]
#[OA\Schema(
    schema: 'LoginResult', type: 'object', required: ['access_token', 'token_type', 'expires_in', 'user'],
    properties: [
        new OA\Property(property: 'access_token', type: 'string', description: 'Token personal para Authorization: Bearer.'),
        new OA\Property(property: 'token_type', type: 'string', enum: ['Bearer']),
        new OA\Property(property: 'expires_in', type: 'integer', enum: [1800], description: 'Vigencia en segundos.'),
        new OA\Property(property: 'user', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1, example: 1)]),
    ],
)]
#[OA\Schema(
    schema: 'GifId', type: 'string', minLength: 1, maxLength: 128, pattern: '\\S',
    description: 'Texto UTF-8 con al menos un carácter no blanco. Se conserva exactamente, incluidos espacios y ceros iniciales.', example: 'demoGif01',
)]
#[OA\Schema(
    schema: 'Gif', type: 'object', required: ['id', 'title', 'url', 'image_url'],
    properties: [
        new OA\Property(property: 'id', type: 'string', minLength: 1, example: 'demoGif01'),
        new OA\Property(property: 'title', type: 'string', description: 'Puede ser vacío.', example: 'GIF de demostración'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://example.test/gifs/demoGif01'),
        new OA\Property(property: 'image_url', type: 'string', format: 'uri', example: 'https://example.test/images/demo.gif'),
    ],
)]
#[OA\Schema(
    schema: 'SearchResult', type: 'object', required: ['data', 'pagination'],
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Gif'), description: 'Puede ser una lista vacía.'),
        new OA\Property(property: 'pagination', type: 'object', required: ['limit', 'offset', 'count'], properties: [
            new OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 50, example: 25),
            new OA\Property(property: 'offset', type: 'integer', minimum: 0, maximum: 4999, example: 0),
            new OA\Property(property: 'count', type: 'integer', minimum: 0, description: 'Cantidad de GIFs devueltos.', example: 1),
            new OA\Property(property: 'total_count', type: 'integer', minimum: 0, description: 'Solo se incluye si GIPHY informa el total.', example: 10),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'GifResult', type: 'object', required: ['data'],
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Gif')],
)]
#[OA\Schema(
    schema: 'FavoriteInput', type: 'object', required: ['GIF_ID', 'ALIAS', 'USER_ID'],
    properties: [
        new OA\Property(property: 'GIF_ID', ref: '#/components/schemas/GifId'),
        new OA\Property(property: 'ALIAS', type: 'string', minLength: 1, maxLength: 100, pattern: '\\S', description: 'Texto UTF-8 no blanco; se conservan los espacios.', example: 'Mi GIF de demostración'),
        new OA\Property(property: 'USER_ID', description: 'Debe coincidir con user.id del login. Entero positivo hasta PHP_INT_MAX (9223372036854775807), o cadena de dígitos con ese valor; admite ceros iniciales. No admite números JSON decimales ni notación exponencial.', example: 1, oneOf: [
            new OA\Schema(type: 'integer', format: 'int64', minimum: 1, maximum: 9223372036854775807),
            new OA\Schema(type: 'string', pattern: '^0*[1-9][0-9]*$'),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'FavoriteResult', type: 'object', required: ['data'],
    properties: [new OA\Property(property: 'data', type: 'object', required: ['id', 'user_id', 'gif_id', 'alias', 'created_at', 'updated_at'], properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', minimum: 1, example: 1),
        new OA\Property(property: 'user_id', type: 'integer', format: 'int64', minimum: 1, example: 1),
        new OA\Property(property: 'gif_id', ref: '#/components/schemas/GifId'),
        new OA\Property(property: 'alias', type: 'string', example: 'Mi GIF de demostración'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', description: 'UTC, sin fracciones de segundo.', example: '2026-01-01T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', description: 'UTC, sin fracciones de segundo.', example: '2026-01-01T12:00:00Z'),
    ])],
)]
#[OA\Schema(
    schema: 'Error', type: 'object', required: ['message'],
    properties: [new OA\Property(property: 'message', type: 'string', example: 'Error del servidor.')],
)]
#[OA\Schema(
    schema: 'ValidationError', type: 'object', required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', enum: ['Datos inválidos.']),
        new OA\Property(property: 'errors', type: 'object', description: 'Mensajes sanitizados agrupados por campo; el texto de cada validación puede variar.', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
    ],
)]
final class Schemas {}
