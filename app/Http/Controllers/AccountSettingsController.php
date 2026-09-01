<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateAccountProfileRequest;
use Illuminate\Http\JsonResponse;

class AccountSettingsController extends Controller
{
    public function updateProfile(UpdateAccountProfileRequest $request): JsonResponse
    {
        $request->user()->update(
            $request->safe()->only(['name', 'email', 'timezone']),
        );

        return response()->json([
            'message' => 'Profilo aggiornato.',
            'user' => $request->user()->only(['name', 'email', 'timezone']),
        ]);
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json([
            'message' => 'Password aggiornata.',
        ]);
    }
}
