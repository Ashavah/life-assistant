<?php

namespace App\Policies;

use App\Models\ExternalActionProposal;
use App\Models\User;

class ExternalActionProposalPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return $externalActionProposal->serviceConnection()
            ->whereBelongsTo($user)
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return $this->view($user, $externalActionProposal);
    }

    public function confirm(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return $this->view($user, $externalActionProposal);
    }

    public function reject(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return $this->view($user, $externalActionProposal);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ExternalActionProposal $externalActionProposal): bool
    {
        return false;
    }
}
