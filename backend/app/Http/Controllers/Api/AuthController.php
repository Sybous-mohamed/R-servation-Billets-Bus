<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // 1. REGISTER
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8),
            ],
        ]);

        $user = User::create($data);

        Auth::login($user);

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Compte créé avec succès',
            'user' => $user->only([
                'id', 'name', 'email', 'role'
            ]),
        ], 201);
    }

    // 2. LOGIN
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $valid = Auth::attempt([
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 'active',
        ]);

        if (!$valid) {
            throw ValidationException::withMessages([
                'email' => ['Email ou mot de passe incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Connexion réussie',
            'user' => $request->user()->only([
                'id', 'name', 'email', 'role'
            ]),
        ]);
    }

    // 3. GET CURRENT USER
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user()->only([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'status',
            ]),
        ]);
    }

    // 4. LOGOUT
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }
}
