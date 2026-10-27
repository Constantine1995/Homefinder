<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StatisticsRequest extends FormRequest
{
    /**
     * Periods the statistics can be shown for, in days.
     */
    public const array PERIODS = [7, 30, 90];

    public const int DEFAULT_PERIOD = 30;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('viewStatistics');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODS)],
        ];
    }

    /**
     * Get the length of the period, in days.
     */
    public function days(): int
    {
        return $this->integer('days', self::DEFAULT_PERIOD);
    }

    /**
     * Get the start of the period.
     */
    public function since(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($this->days());
    }
}
