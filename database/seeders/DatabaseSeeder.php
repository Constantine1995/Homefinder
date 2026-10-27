<?php

namespace Database\Seeders;

use App\Enums\FeedType;
use App\Enums\UserRole;
use App\Models\FeedSource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Демо-застройщик']);

        User::factory()->for($tenant)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => UserRole::Admin,
        ]);

        // The importer runs in the horizon container, which reaches the demo developer's site through nginx.
        FeedSource::factory()->for($tenant)->create([
            'name' => 'Фид Яндекс Недвижимости',
            'type' => FeedType::Yandex,
            'url' => 'http://nginx/demo/feeds/yandex.xml',
            'schedule' => '0 * * * *',
        ]);

        $this->callWith(DemoMortgageProgramsSeeder::class, ['tenant' => $tenant]);
    }
}
