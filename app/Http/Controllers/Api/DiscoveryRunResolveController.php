<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResolveDiscoveryRunRequest;
use App\Services\Discovery\DiscoveryRunConflictException;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class DiscoveryRunResolveController extends Controller
{
    public function __invoke(ResolveDiscoveryRunRequest $request, DiscoveryRunService $runs): JsonResponse
    {
        try {
            $run = $runs->resolveForExtension($request->user(), (string) $request->validated('url'));
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Execução não encontrada.'], 404);
        } catch (DiscoveryRunConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'execution_id' => $run->execution_id,
            'source' => $run->source->source,
            'surface' => $run->surface,
            'initial_url' => $run->initial_url,
            'expires_at' => $run->expires_at?->toIso8601String(),
        ]);
    }
}
