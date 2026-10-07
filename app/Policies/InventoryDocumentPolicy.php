<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock In, Adjustments, Physical Counts: admins and inventory clerks.
 * Only drafts are editable; documents are cancelled, never deleted.
 */
class InventoryDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-inventory');
    }

    public function view(User $user, Model $document): bool
    {
        return $user->can('manage-inventory');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-inventory');
    }

    public function update(User $user, Model $document): bool
    {
        return $user->can('manage-inventory') && $document->isDraft();
    }

    public function post(User $user, Model $document): bool
    {
        return $this->update($user, $document);
    }

    public function cancel(User $user, Model $document): bool
    {
        return $this->update($user, $document);
    }

    public function delete(User $user, Model $document): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
