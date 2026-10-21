<?php

namespace App\Infrastructure\Http;

use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Gifs\GifId;
use Illuminate\Foundation\Http\FormRequest;

final class SaveFavoriteGifRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    public function rules(): array
    {
        $text = fn (string $class) => function ($attribute, $value, $fail) use ($class) {
            try {
                new $class($value);
            } catch (\InvalidArgumentException) {
                $fail($attribute.' inválido.');
            }
        };

        return [
            'GIF_ID' => ['bail', 'required', 'string', $text(GifId::class)],
            'ALIAS' => ['bail', 'required', 'string', $text(FavoriteAlias::class)],
            'USER_ID' => ['bail', 'required', function ($attribute, $value, $fail) {
                $digits = is_int($value) ? (string) $value : $value;
                $canonical = is_string($digits) ? ltrim($digits, '0') : '';
                $max = (string) PHP_INT_MAX;
                if (! is_string($digits) || ! preg_match('/^[0-9]+$/D', $digits) || $canonical === '' || strlen($canonical) > strlen($max) || (strlen($canonical) === strlen($max) && strcmp($canonical, $max) > 0)) {
                    $fail('USER_ID debe ser un entero positivo representable.');
                }
            }],
        ];
    }
}
