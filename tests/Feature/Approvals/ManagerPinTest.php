<?php

use App\Enums\ApprovalAction;
use App\Models\ManagerApproval;
use App\Services\Approvals\ManagerPinService;
use App\Settings\SecuritySettings;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->pins = app(ManagerPinService::class);
});

it('stores the PIN hashed and only for admins', function () {
    $manager = admin();
    $this->pins->setPin($manager, '2468');

    expect($manager->fresh()->pin_hash)->not->toBe('2468')
        ->and(password_verify('2468', $manager->fresh()->pin_hash))->toBeTrue();

    expect(fn () => $this->pins->setPin(cashier(), '2468'))->toThrow(ValidationException::class);
});

it('only accepts 4 to 6 digits', function (string $pin) {
    expect(fn () => $this->pins->setPin(admin(), $pin))->toThrow(ValidationException::class);
})->with(['123', '1234567', 'abcd', '12 34']);

it('keeps PINs unique among managers', function () {
    $this->pins->setPin(admin(), '1357');

    expect(fn () => $this->pins->setPin(superAdmin(), '1357'))->toThrow(ValidationException::class);
});

it('approves a cashier action with a valid manager PIN and logs who approved what', function () {
    $manager = admin();
    $this->pins->setPin($manager, '9753');
    $cashier = cashier();

    $approval = $this->pins->authorize($cashier, ApprovalAction::Discount, '9753', $manager, ['percent' => 15]);

    expect($approval)
        ->approver_id->toBe($manager->id)
        ->requested_by->toBe($cashier->id)
        ->action->toBe(ApprovalAction::Discount)
        ->details->toBe(['percent' => 15]);

    expect(Activity::where('event', 'approved')->where('causer_id', $manager->id)->exists())->toBeTrue();
});

it('rejects a wrong PIN and never uses a deactivated manager', function () {
    $manager = admin();
    $this->pins->setPin($manager, '8642');

    expect(fn () => $this->pins->authorize(cashier(), ApprovalAction::VoidOrder, '0000'))
        ->toThrow(ValidationException::class, 'Incorrect manager PIN');

    $manager->update(['is_active' => false]);

    expect(fn () => $this->pins->authorize(cashier(), ApprovalAction::VoidOrder, '8642'))
        ->toThrow(ValidationException::class);

    expect(ManagerApproval::count())->toBe(0);
});

it('rate limits failed PIN attempts', function () {
    $settings = app(SecuritySettings::class);
    $settings->max_pin_attempts = 3;
    $settings->save();

    $manager = admin();
    $this->pins->setPin($manager, '1111');
    $cashier = cashier();

    foreach (range(1, 3) as $attempt) {
        try {
            $this->pins->verify($cashier, '9999');
        } catch (ValidationException) {
        }
    }

    // Even the correct PIN is refused while locked out.
    expect(fn () => $this->pins->verify($cashier, '1111'))->toThrow(ValidationException::class, 'Too many');
    expect(Activity::where('event', 'pin_failed')->count())->toBe(3);
});

it('lets admins approve their own restricted actions without a PIN', function () {
    $manager = admin();

    $approval = $this->pins->authorize($manager, ApprovalAction::PriceOverride, null);

    expect($approval->approver_id)->toBe($manager->id);
});
