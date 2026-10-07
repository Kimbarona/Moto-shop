<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dev logins (password: "password"). Admin users get manager PIN 1234.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['Super Admin', 'superadmin@example.com', Role::SuperAdmin],
            ['Shop Manager', 'admin@example.com', Role::Admin],
            ['Cashier Carla', 'cashier@example.com', Role::Cashier],
            ['Mang Boy Santos', 'mechanic@example.com', Role::Mechanic],
            ['Clerk Cris', 'clerk@example.com', Role::InventoryClerk],
        ];

        foreach ($users as [$name, $email, $role]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ]);

            if ($role->isAdmin()) {
                // Dev convenience only: real PINs are set via the PIN screen, which keeps them unique.
                $user->forceFill(['pin_hash' => Hash::make('1234')])->save();
            }
        }

        Mechanic::updateOrCreate(['name' => 'Mang Boy Santos'], [
            'mobile' => '0917 555 0101',
            'user_id' => User::where('email', 'mechanic@example.com')->value('id'),
            'is_active' => true,
        ]);

        Mechanic::updateOrCreate(['name' => 'Jun Ramirez'], ['mobile' => '0918 555 0202', 'is_active' => true]);
    }
}
