<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\AuthController;

Route::get('/status', function () {
    DB::select('SELECT 1');

    return response()->json([
        'backend' => 'Laravel OK',
        'database' => 'MySQL Connected'
    ]);
});

Route::get('/ping', function () {
    return response()->json([
        'message' => 'Laravel API OK'
    ]);
});

//Authentification

// Routes publiques
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
