<?php

declare(strict_types=1);

namespace Nvl\Data\Http\Controllers;

use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nvl\Data\Services\GeneratedTypeFileCatalog;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves generated TypeScript declarations and synchronization metadata.
 */
final class GeneratedTypesController extends Controller
{
    /**
     * Create the generated declaration controller.
     */
    public function __construct(
        private readonly GeneratedTypeFileCatalog $catalog,
    ) {}

    /**
     * List generated declaration files and synchronization metadata.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $manifest = $this->catalog->manifest();
        } catch (RuntimeException|LockTimeoutException) {
            return $this->unavailableResponse();
        }

        $response = response()
            ->json([
                'data' => array_map(
                    static fn (array $file): array => [
                        ...$file,
                        'url' => route('nvl-data.types.show', ['scope' => $file['scope']], false),
                    ],
                    $manifest['files'],
                ),
                'meta' => [
                    'hash' => $manifest['hash'],
                    'revision' => $manifest['revision'],
                    'version' => $manifest['generatedAt'],
                    'generatedAt' => $manifest['generatedAt'],
                    'packages' => $manifest['packages'],
                    'transformers' => $manifest['transformers'],
                    'sources' => $manifest['sources'],
                    'symbols' => $manifest['symbols'],
                    'entrypoint' => [
                        ...$manifest['entrypoint'],
                        'url' => route('nvl-data.types.entrypoint', absolute: false),
                    ],
                    'archive' => [
                        ...$manifest['archive'],
                        'path' => 'archive',
                        'url' => $manifest['archive']['enabled']
                            ? route('nvl-data.types.archive', absolute: false)
                            : null,
                    ],
                ],
            ])
            ->withHeaders($this->cacheHeaders(
                etag: $manifest['revision'],
                typesHash: $manifest['hash'],
            ));

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Serve the configured declaration entrypoint.
     */
    public function entrypoint(Request $request): Response|JsonResponse
    {
        try {
            $entrypoint = $this->catalog->entrypoint();
        } catch (RuntimeException|LockTimeoutException) {
            return $this->unavailableResponse();
        }

        $response = response(
            $entrypoint['contents'],
            Response::HTTP_OK,
            $this->declarationHeaders(
                filename: $entrypoint['filename'],
                hash: $entrypoint['hash'],
                path: $entrypoint['path'],
            ),
        );

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Serve one supplemental declaration by stable scope.
     */
    public function show(Request $request, string $scope): Response|JsonResponse
    {
        try {
            $file = $this->catalog->findScope($scope);
        } catch (RuntimeException|LockTimeoutException) {
            return $this->unavailableResponse();
        }

        if ($file === null) {
            return response()->json(['message' => 'Type scope not found.'], Response::HTTP_NOT_FOUND);
        }

        $response = response(
            $file['contents'],
            Response::HTTP_OK,
            [
                ...$this->declarationHeaders($file['filename'], $file['hash'], $file['path']),
                $this->headerName('Type-Scope') => $file['scope'],
            ],
        );

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Download every generated declaration as a temporary ZIP archive.
     */
    public function archive(Request $request): BinaryFileResponse|JsonResponse|Response
    {
        if (! $this->catalog->archiveEnabled()) {
            return response()->json(
                ['message' => 'Generated type archives are unavailable.'],
                Response::HTTP_NOT_IMPLEMENTED,
            );
        }

        try {
            $manifest = $this->catalog->manifest();
            $notModified = $this->notModifiedResponse($request, $manifest['hash']);

            if ($notModified !== null) {
                return $notModified;
            }

            $archive = $this->catalog->createArchive();
        } catch (RuntimeException|LockTimeoutException) {
            return $this->unavailableResponse();
        }

        return response()
            ->download(
                $archive['path'],
                $archive['filename'],
                [
                    ...$this->cacheHeaders($archive['hash']),
                    'Content-Type' => 'application/zip',
                    $this->headerName('Types-Archive') => 'true',
                ],
            )
            ->deleteFileAfterSend(true);
    }

    /**
     * Build shared public cache headers.
     *
     * @return array<string, string>
     */
    private function cacheHeaders(string $etag, ?string $typesHash = null): array
    {
        $cacheControl = config(
            'nvl-data.typescript.routes.cache_control',
            'private, no-store',
        );

        $headers = [
            'Cache-Control' => is_string($cacheControl) && $cacheControl !== ''
                ? $cacheControl
                : 'private, no-store',
            'ETag' => '"'.$etag.'"',
            $this->headerName('Types-Hash') => $typesHash ?? $etag,
        ];

        if ($typesHash !== null && $typesHash !== $etag) {
            $headers[$this->headerName('Manifest-Revision')] = $etag;
        }

        return $headers;
    }

    /**
     * Build declaration response headers.
     *
     * @return array<string, string>
     */
    private function declarationHeaders(string $filename, string $hash, string $path): array
    {
        return [
            ...$this->cacheHeaders($hash),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Type' => 'text/plain; charset=UTF-8',
            $this->headerName('Type-Path') => $path,
        ];
    }

    /**
     * Build one validated application-specific generated-type response header.
     */
    private function headerName(string $suffix): string
    {
        $prefix = config('nvl-data.typescript.routes.headers_prefix', 'NVL');

        if (! is_string($prefix) || preg_match('/^[A-Za-z0-9-]+$/', $prefix) !== 1) {
            $prefix = 'NVL';
        }

        return 'X-'.strtoupper($prefix).'-'.$suffix;
    }

    /**
     * Build a conditional 304 response before doing archive compression work.
     */
    private function notModifiedResponse(Request $request, string $hash): ?Response
    {
        $response = response('', Response::HTTP_OK, $this->cacheHeaders($hash));
        $response->isNotModified($request);

        return $response->getStatusCode() === Response::HTTP_NOT_MODIFIED
            ? $response
            : null;
    }

    /**
     * Return a non-sensitive retryable response for missing or invalid publications.
     */
    private function unavailableResponse(): JsonResponse
    {
        return response()
            ->json(
                ['message' => 'Generated types are temporarily unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            )
            ->header('Retry-After', '5');
    }
}
