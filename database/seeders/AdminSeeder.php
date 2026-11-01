<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Never modify an existing administrator.
        if (DB::table('user_meta')->where('key', 'role')->whereJsonContains('value', 'admin')->exists()) {
            return;
        }

        $name = env('NH_ADMIN_NAME');
        $email = env('NH_ADMIN_EMAIL');
        $password = env('NH_ADMIN_PASSWORD');

        // No hard-coded credentials and no automatic demo user.
        if ($name === null && $email === null && $password === null) {
            $this->command?->warn('Admin not created: NH_ADMIN_NAME, NH_ADMIN_EMAIL and NH_ADMIN_PASSWORD are not configured.');
            return;
        }

        if (!is_string($name) || trim($name) === '' ||
            !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($password) || mb_strlen($password) < 12) {
            throw new InvalidArgumentException('NH_ADMIN_NAME, NH_ADMIN_EMAIL and NH_ADMIN_PASSWORD (12+ characters) are required.');
        }

        DB::transaction(function () use ($name, $email, $password): void {
            if (DB::table('user_meta')->where('key', 'role')->whereJsonContains('value', 'admin')->exists()) {
                return;
            }

            if (DB::table('users')->where('email', $email)->exists()) {
                throw new RuntimeException('Admin email already belongs to a user; no existing account was elevated.');
            }

            $now = now();
            $userId = DB::table('users')->insertGetId([
                'name' => trim($name),
                'email' => trim($email),
                'password' => Hash::make($password),
                'email_verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('user_meta')->insert([
                'user_id' => $userId,
                'key' => 'role',
                'value' => json_encode('admin', JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
