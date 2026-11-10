<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Discovery\CaptureConflictException;
use App\Services\Discovery\CaptureIngestionService;
use App\Services\Discovery\CaptureStorageException;
use App\Services\Discovery\CaptureTooLargeException;
use App\Services\Discovery\CaptureValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageCaptureIngestController extends Controller
{
    public function __invoke(Request $request, CaptureIngestionService $ingestion): JsonResponse
    {
        try {
            [$capture, $created] = $ingestion->ingest($request->user(), $request->all());
        } catch (CaptureTooLargeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 413);
        } catch (CaptureValidationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (CaptureConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (CaptureStorageException $exception) {
            report($exception);

            return response()->json(['message' => 'Falha ao persistir a captura.'], 503);
        }

        return response()->json([
            'id' => $capture->id,
            'execution_id' => $capture->execution_id,
            'coverage' => $capture->coverage_status,
            'ingest_status' => $capture->ingest_status,
            'replay' => ! $created,
        ], $created ? 201 : 200);
    }
}
