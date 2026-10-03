<?php

namespace App\Http\Controllers;

use App\Models\User;

class TestController
{
    public function index()
    {
        return response()->json([
            User::get(),
        ], 200);
    }
}
