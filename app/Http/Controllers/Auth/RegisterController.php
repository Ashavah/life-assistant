<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\CharacterProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(
        RegisterRequest $request,
        CharacterProvisioner $provisioner,
    ): RedirectResponse {
        $user = DB::transaction(function () use ($request, $provisioner): User {
            $user = User::query()->create(
                $request->safe()->only(['name', 'email', 'password']),
            );
            $provisioner->provision($user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }
}
