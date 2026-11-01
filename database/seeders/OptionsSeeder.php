<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OptionsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'site.locale' => 'fa',
            'site.timezone' => 'Asia/Tehran',
            'notification.default_priority' => 4,
            'notification.max_attempts' => 3,
        ];

        foreach ($defaults as $key => $value) {
            DB::table('options')->insertOrIgnore([
                'scope_type' => 'global',
                'scope_id' => 0,
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
