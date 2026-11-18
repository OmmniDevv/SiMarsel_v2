<?php

namespace App\Http\Requests\Task;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkNoteRequest extends FormRequest
{
    public const MAX_LENGTH = 5000;

    /**
     * Authorization is handled by TaskPolicy on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * work_note is the PIC's whole note after the change; empty or null removes it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_note' => ['present', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_note.present' => 'Catatan kerja wajib dikirim (kosongkan untuk menghapus).',
            'work_note.string' => 'Catatan kerja harus berupa teks.',
            'work_note.max' => 'Catatan kerja maksimal :max karakter.',
        ];
    }
}
