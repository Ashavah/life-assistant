<?php

namespace App\Contracts;

use App\Models\ServiceConnection;

interface AccessTokenManager
{
    public function accessToken(ServiceConnection $connection): string;
}
