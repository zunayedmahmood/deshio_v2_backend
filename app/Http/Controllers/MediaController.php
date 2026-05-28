<?php

namespace App\Http\Controllers;

use App\Support\MediaUrl;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function show(string $path)
    {
        $storedPath = MediaUrl::storedPath($path);

        if (!$storedPath || !Storage::disk('public')->exists($storedPath)) {
            abort(404);
        }

        return response(Storage::disk('public')->get($storedPath), 200, [
            'Content-Type' => Storage::disk('public')->mimeType($storedPath) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
