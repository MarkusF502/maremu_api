<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{


        public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password,
        ]);
 
        $token = $user->createToken('auth_token')->plainTextToken;
        
        Auth::login($user);
        
        return response()->json([
            'message'      => 'Conta criada com sucesso.',
            'user'         => $user,
            'token'        => $token,
            'tem_loja'     => false, 
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'E-mail ou senha incorretos.'
            ], 401);
        }

        $request->session()->regenerate();

        return response()->json([
            'user' => Auth::user()
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout realizado.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
 
        return response()->json([
            'user'     => $user,
            'tem_loja' => $user->loja()->exists(),
        ]);
    }

}