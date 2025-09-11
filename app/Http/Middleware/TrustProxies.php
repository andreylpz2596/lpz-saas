<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustProxies
{
// Confía en ngrok (todos los proxies). Si prefieres, pon IPs específicas.
    protected $proxies = '*';

    // Respeta esquema/host/puerto reales para que Laravel detecte HTTPS.
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;
}
