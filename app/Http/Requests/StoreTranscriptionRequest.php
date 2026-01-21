<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTranscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'in:video/mp4'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'filename.required' => 'Please provide the original file name.',
            'content_type.in' => 'Only MP4 uploads are supported.',
            'size_bytes.min' => 'The file size must be greater than zero.',
        ];
    }
}
