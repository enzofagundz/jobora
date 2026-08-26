<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JobCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobCaptureController extends Controller
{
    public function store(Request $request, JobCaptureService $service): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_id' => ['nullable', 'string', 'max:255', 'required_without_all:url,description'],
            'url' => ['nullable', 'string', 'max:2000'],
            'search_url' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:100000'],
            'company' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'author' => ['nullable', 'array'],
            'author.name' => ['nullable', 'string', 'max:255'],
            'author.profile_url' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $data = [
            'source' => strtolower(trim($validated['source'])),
            'external_id' => $validated['external_id'] ?? null,
            'url' => $validated['url'] ?? null,
            'search_url' => $validated['search_url'] ?? null,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'company' => $validated['company'] ?? null,
            'location' => $validated['location'] ?? null,
            'author_name' => $validated['author']['name'] ?? null,
            'author_profile_url' => $validated['author']['profile_url'] ?? null,
            'captured_at' => $validated['captured_at'] ?? now(),
        ];

        $owner = $request->user();

        if (! $owner instanceof User) {
            $owner = $request->attributes->get('capture_user');
        }

        if (! $owner instanceof User) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        [$capture, $duplicate] = $service->store($data, $owner);

        return response()->json([
            'capture' => $capture->toArray(),
            'duplicate' => $duplicate,
        ], $duplicate ? 200 : 201);
    }
}
