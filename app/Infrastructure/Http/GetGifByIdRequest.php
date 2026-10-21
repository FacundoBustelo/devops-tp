<?php

namespace App\Infrastructure\Http;

use App\Domain\Gifs\GifId;
use Illuminate\Foundation\Http\FormRequest;

final class GetGifByIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return ['id' => $this->route('id')];
    }

    public function rules(): array
    {
        return ['id' => ['bail', 'required', 'string', function ($attribute, $value, $fail) {
            try {
                new GifId($value);
            } catch (\InvalidArgumentException) {
                $fail('id debe contener entre 1 y 128 caracteres UTF-8 y algún carácter distinto de blanco.');
            }
        }]];
    }
}
