<?php

namespace App\Http\Controllers;

use App\Models\DocumentArtifact;
use App\Services\DocumentArtifactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentArtifactController extends Controller
{
    public function __invoke(Request $request, DocumentArtifact $artifact, DocumentArtifactService $artifacts): StreamedResponse
    {
        $artifacts->assertDownload($request->user(), $artifact, $request->routeIs('documents.session.download'));

        return Storage::disk('local')->download($artifact->path, $artifact->type.'.pdf', [
            'Content-Type' => $artifact->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
