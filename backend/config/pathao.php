<?php

return [
    "sandbox"       => env("PATHAO_SANDBOX", false),
    "base_url"      => env("PATHAO_BASE_URL", "https://api-hermes.pathao.com"),
    "client_id"     => env("PATHAO_CLIENT_ID", ""),
    "client_secret" => env("PATHAO_CLIENT_SECRET", ""),
    "username"      => env("PATHAO_USERNAME", ""),
    "password"      => env("PATHAO_PASSWORD", ""),
    "disk"          => env("PATHAO_DISK", "local")
];
