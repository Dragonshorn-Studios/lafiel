<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Reverse proxies in front of the application (Coolify's Traefik, nginx,
    | a load balancer) terminate TLS and add X-Forwarded-* headers. Headers
    | from a trusted proxy are honored; headers from anyone else are ignored,
    | so a directly exposed port cannot spoof them.
    |
    | Value: "*" to trust every caller, or a comma-separated list of IPs and
    | CIDR ranges (e.g. "10.0.0.0/8,172.16.0.0/12"). Empty trusts nothing.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
