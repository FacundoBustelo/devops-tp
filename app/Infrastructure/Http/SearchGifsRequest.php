<?php

namespace App\Infrastructure\Http;

use Illuminate\Foundation\Http\FormRequest;

final class SearchGifsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query->all() + ['LIMIT' => 25, 'OFFSET' => 0];
    }

    public function rules(): array
    {
        $integer = fn ($attribute, $value, $fail) => (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value))) ? null : $fail($attribute.' debe ser entero.');

        return [
            'QUERY' => ['bail', 'required', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (! mb_check_encoding($value, 'UTF-8') || preg_match('/^\s*$/u', $value)) {
                    $fail('QUERY debe contener un término.');
                }
            }],
            'LIMIT' => ['bail', 'required', $integer, 'integer', 'between:1,50'],
            'OFFSET' => ['bail', 'required', $integer, 'integer', 'between:0,4999'],
        ];
    }
}
