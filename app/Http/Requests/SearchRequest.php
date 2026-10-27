<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request; the agent service checks the filters themselves.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:2000'],
            'filters' => ['nullable', 'json'],
            'wishes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get the message the employee searches by, as a buyer would write it.
     */
    public function text(): string
    {
        return $this->string('q')->trim()->value();
    }

    /**
     * Get the filters of an eased search, if the employee chose one.
     *
     * @return array<string, mixed>|null
     */
    public function eased(): ?array
    {
        $filters = $this->filled('filters') ? json_decode($this->string('filters')->value(), true) : null;

        return is_array($filters) ? $filters : null;
    }

    public function wishes(): ?string
    {
        return $this->filled('wishes') ? $this->string('wishes')->trim()->value() : null;
    }
}
