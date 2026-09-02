<?php

namespace App\Policies;

use App\Models\KnowledgeIngestion;
use App\Models\User;

class KnowledgeIngestionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return $knowledgeIngestion->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return $this->view($user, $knowledgeIngestion);
    }

    public function confirm(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return $this->view($user, $knowledgeIngestion);
    }

    public function reject(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return $this->view($user, $knowledgeIngestion);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, KnowledgeIngestion $knowledgeIngestion): bool
    {
        return false;
    }
}
