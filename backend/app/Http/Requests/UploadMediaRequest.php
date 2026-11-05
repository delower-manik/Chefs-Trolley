<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // MIME type AND extension are both checked -- never trust an
            // extension alone (02_CLAUDE_DEVELOPMENT_RULES.md §18).
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'], // 5MB cap, images only for now
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
