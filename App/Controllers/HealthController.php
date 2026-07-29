<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

class HealthController extends Controller
{
    public function test(Request $request): void
    {
        $this->success('test OK!');
    }
}