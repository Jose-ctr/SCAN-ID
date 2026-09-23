<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Services\LostDocumentService;
use Throwable;

final class LostDocumentController
{
    public static function report(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $data = Request::json();

            $document = LostDocumentService::report(
                $data,
                (string) $user['id']
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Lost document reported successfully.',
                201
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function show(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $document = LostDocumentService::findById($id);

            if ($document === null) {
                Response::error(
                    'Lost document not found.',
                    404
                );
            }

            if (
                isset($document['owner_user_id'])
                && (string) $document['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to access this document.',
                    403
                );
            }

            Response::success(
                [
                    'document' => $document,
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function mine(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $documents = LostDocumentService::findByOwner(
                (string) $user['id']
            );

            Response::success(
                [
                    'documents' => $documents,
                    'count' => count($documents),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function cancel(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $document = LostDocumentService::cancel(
                $id,
                (string) $user['id']
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Lost document report cancelled.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    private static function routeId(): string
    {
        $id = Request::input('id');

        if (!is_string($id) || trim($id) === '') {
            Response::error(
                'Document ID is required.',
                400
            );
        }

        $id = trim($id);

        if (
            !preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
                $id
            )
        ) {
            Response::error(
                'Invalid document ID.',
                400
            );
        }

        return $id;
    }

    private static function handleException(Throwable $exception): void
    {
        if (
            filter_var(
                $_ENV['APP_DEBUG'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            )
        ) {
            Response::error(
                $exception->getMessage(),
                400
            );
        }

        Response::error(
            'Unable to process the lost document request.',
            400
        );
    }

    private function __construct()
    {
    }
}
