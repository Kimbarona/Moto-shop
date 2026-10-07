<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('renders the login screen', function () {
    $this->get(route('login'))->assertOk();
});

it('has no public registration or password-reset routes', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('password.request'))->toBeFalse();
});

it('sends each role to its own home after login', function (Role $role, string $home) {
    $user = User::factory()->role($role)->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($home);

    $this->assertAuthenticatedAs($user);
})->with([
    'super admin' => [Role::SuperAdmin, '/admin'],
    'admin' => [Role::Admin, '/admin'],
    'cashier' => [Role::Cashier, '/pos'],
    'mechanic' => [Role::Mechanic, '/service-board'],
    'clerk' => [Role::InventoryClerk, '/admin'],
]);

it('does not log in with a wrong password', function () {
    $user = cashier();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();
});

it('does not let inactive users log in', function () {
    $user = cashier(['is_active' => false]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out a user who was deactivated mid-session', function () {
    $user = cashier();
    $this->actingAs($user);
    $user->update(['is_active' => false]);

    $this->get('/pos')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('records the last login time', function () {
    $user = cashier();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('logs users out', function () {
    $this->actingAs(cashier())->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
});

it('rate limits login attempts', function () {
    $user = cashier();
    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

describe('forced password change', function () {
    it('redirects to the password page until the password is changed', function () {
        $user = cashier(['must_change_password' => true]);

        $this->actingAs($user)->get('/pos')->assertRedirect(route('security.edit'));
        $this->actingAs($user)->get(route('security.edit'))->assertOk();

        $manager = admin(['must_change_password' => true]);
        $this->actingAs($manager)->get('/admin')->assertRedirect(route('security.edit'));
    });

    it('clears the flag and sends the user home once changed', function () {
        $user = cashier(['must_change_password' => true]);

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/pos');

        $user->refresh();
        expect($user->must_change_password)->toBeFalse()
            ->and(Hash::check('new-password-123', $user->password))->toBeTrue();
    });

    it('requires the current password', function () {
        $user = cashier(['must_change_password' => true]);

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->must_change_password)->toBeTrue();
    });
});

it('lets users update their profile name and email', function () {
    $user = cashier();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Carla Cruz', 'email' => 'carla@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->name->toBe('Carla Cruz')->email->toBe('carla@example.com');
});
