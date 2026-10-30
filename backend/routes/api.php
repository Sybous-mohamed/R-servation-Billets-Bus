
<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/status', function () {

    DB::select('SELECT 1');

    return response()->json([
        'backend' => 'Laravel OK',
        'database' => 'MySQL Connected'
    ]);

});
