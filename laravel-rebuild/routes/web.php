<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'LaVita Urenregistratie API',
        'docs'    => '/api/health',
    ]);
});
