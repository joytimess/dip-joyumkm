<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function search(Request $request, GeocodingService $geocoder): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:120']]);

        return response()->json(['data' => $geocoder->search($data['q'])]);
    }

    public function reverse(Request $request, GeocodingService $geocoder): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['data' => $geocoder->reverse((float) $data['lat'], (float) $data['lon'])]);
    }
}
