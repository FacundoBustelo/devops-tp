<?php

namespace App\Infrastructure\Http;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']];
    }
}
