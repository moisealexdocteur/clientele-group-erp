<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Clientèle Group ERP API',
    'status' => 'available',
]));
