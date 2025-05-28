<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Group;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Truncate tables to avoid duplicate entries
        DB::table('groups')->truncate();
        DB::table('users')->truncate();

        // Create the first user
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'), // Always hash the password!
            'status' => 'active',
            'role' => 'user',
        ]);

        // Create a group owned by this user
        Group::factory()->create([
            'owner_id' => $user->id,
            'code' => 'ABC123',
            'group_number' => 1,
        ]);
    }
}
