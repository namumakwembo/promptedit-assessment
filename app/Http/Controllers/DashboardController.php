<?php

namespace App\Http\Controllers;

use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        return Inertia::render('Dashboard', [
            'title' => 'Your generations',
            'canGenerate' => $client->configured(),
            'generations' => Generation::query()
                ->where('user_id', $request->user()?->getAuthIdentifier())
                ->latest()->orderByDesc('id')
                ->paginate(10)
                ->through(fn (Generation $generation) => (new GenerationResource($generation))->resolve($request)),
        ]);
    }
}
