<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! $email || ! $password) {
            $this->command?->error('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding.');

            return;
        }

        User::query()->firstOrCreate(
            [
                'email' => $email
            ],
            [
                'name' => config('admin.name'),
                'password' => $password,
            ]
        );
    }
}
