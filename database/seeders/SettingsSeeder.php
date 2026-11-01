<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('settings')->insertOrIgnore([
            'id' => 1,
            'site_name' => 'Notification Hub',
            'contact_email' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
