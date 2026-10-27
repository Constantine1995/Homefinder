<?php

namespace Database\Seeders;

use App\Models\MortgageProgram;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Mortgage programs of the demo developer: made up, but on terms like those of 2026, so that the agent's
 * payments look real. Seeding again updates them in place.
 */
class DemoMortgageProgramsSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    public const array PROGRAMS = [
        [
            'name' => 'Семейная ипотека',
            'bank' => 'Банк «Северный»',
            'rate' => 6,
            'min_down_pct' => 20,
            'max_amount' => 12_000_000,
            'max_years' => 30,
            'conditions' => 'Для семей, где ребёнку нет шести лет, или с ребёнком-инвалидом. Ставка 6% на весь срок, '
                .'сумма кредита до 12 млн ₽ в Москве. Одна льготная ипотека на семью. Демо-условия.',
            'valid_to' => '2030-12-31',
        ],
        [
            'name' => 'IT-ипотека',
            'bank' => 'Банк «Северный»',
            'rate' => 6,
            'min_down_pct' => 20,
            'max_amount' => 9_000_000,
            'max_years' => 30,
            'conditions' => 'Для сотрудников аккредитованных IT-компаний не старше 50 лет с зарплатой от 150 тыс. ₽ в месяц. '
                .'Демо-условия.',
            'valid_to' => '2030-12-31',
        ],
        [
            'name' => 'Ипотека с субсидией застройщика',
            'bank' => 'Банк «Столица»',
            'rate' => 9.9,
            'min_down_pct' => 30,
            'max_amount' => 20_000_000,
            'max_years' => 25,
            'conditions' => 'Застройщик снижает ставку на квартиры в ЖК «Речная терраса» при сделке до конца года. '
                .'Демо-условия.',
            'valid_to' => '2026-12-31',
        ],
        [
            'name' => 'Стандартная ипотека',
            'bank' => 'Банк «Столица»',
            'rate' => 17.5,
            'min_down_pct' => 20,
            'max_amount' => 30_000_000,
            'max_years' => 30,
            'conditions' => 'Без ограничений по составу семьи и профессии. Демо-условия.',
            'valid_to' => null,
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= Tenant::query()->orderBy('id')->firstOrFail();

        $tenant->run(function (): void {
            foreach (self::PROGRAMS as $program) {
                MortgageProgram::query()->updateOrCreate(['name' => $program['name']], $program);
            }
        });
    }
}
