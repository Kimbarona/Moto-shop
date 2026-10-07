<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalAction;
use App\Models\ManagerApproval;
use App\Models\User;
use App\Settings\SecuritySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Manager PIN approvals. A cashier hitting a restricted action (big discount, price
 * override, void, refund) needs an active admin to type their 4–6 digit PIN. The PIN
 * alone identifies the approver, so PINs are unique among active admins.
 *
 * Call verify()/authorize() BEFORE opening the business DB transaction: the rate
 * limiter uses the database cache, and a rolled-back transaction would also roll back
 * the failed-attempt counter.
 */
class ManagerPinService
{
    private const DECAY_SECONDS = 300;

    public function __construct(private readonly SecuritySettings $security) {}

    /**
     * @throws ValidationException
     */
    public function setPin(User $user, string $pin): void
    {
        if (! $user->isAdmin()) {
            throw ValidationException::withMessages(['pin' => 'Only admins and super admins can have a manager PIN.']);
        }

        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw ValidationException::withMessages(['pin' => 'The PIN must be 4 to 6 digits.']);
        }

        $taken = $this->approvers()
            ->where('id', '!=', $user->id)
            ->contains(fn (User $other) => Hash::check($pin, $other->pin_hash));

        if ($taken) {
            throw ValidationException::withMessages(['pin' => 'Please choose a different PIN.']);
        }

        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();

        activity('ManagerPin')->causedBy($user)->performedOn($user)->event('pin_changed')->log('Manager PIN set');
    }

    /**
     * Does this user need a manager PIN for restricted actions? Admins approve themselves.
     */
    public function requiresPin(User $actor): bool
    {
        return ! $actor->isAdmin();
    }

    /**
     * Approve a restricted action. Admins pass without a PIN (their own action is logged);
     * everyone else must supply a valid manager PIN.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function authorize(User $actor, ApprovalAction $action, ?string $pin, ?Model $subject = null, array $details = []): ManagerApproval
    {
        $approver = $this->requiresPin($actor) ? $this->verify($actor, $pin) : $actor;

        $approval = ManagerApproval::create([
            'action' => $action,
            'approver_id' => $approver->id,
            'requested_by' => $actor->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip_address' => request()?->ip(),
        ]);

        activity('ManagerApproval')
            ->causedBy($approver)
            ->performedOn($subject ?? $approval)
            ->event('approved')
            ->withProperties(['action' => $action->value, 'requested_by' => $actor->id, 'details' => $details])
            ->log($action->getLabel().' approved by '.$approver->name);

        return $approval;
    }

    /**
     * Check a PIN typed for $requester. Failed attempts are rate-limited per requester.
     *
     * @throws ValidationException
     */
    public function verify(User $requester, ?string $pin): User
    {
        $key = 'manager-pin:'.$requester->id;
        $maxAttempts = max(1, $this->security->max_pin_attempts);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'pin' => 'Too many wrong PIN attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        $approver = filled($pin)
            ? $this->approvers()->first(fn (User $user) => Hash::check((string) $pin, $user->pin_hash))
            : null;

        if (! $approver) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $left = max(0, $maxAttempts - RateLimiter::attempts($key));

            activity('ManagerPin')->causedBy($requester)->event('pin_failed')->log('Wrong manager PIN entered');

            throw ValidationException::withMessages([
                'pin' => $left > 0 ? "Incorrect manager PIN. {$left} attempt(s) left." : 'Too many wrong PIN attempts. Please wait a few minutes.',
            ]);
        }

        RateLimiter::clear($key);

        return $approver;
    }

    /**
     * @return Collection<int, User>
     */
    private function approvers(): Collection
    {
        return User::query()
            ->active()
            ->whereIn('role', ['super_admin', 'admin'])
            ->whereNotNull('pin_hash')
            ->get();
    }
}
