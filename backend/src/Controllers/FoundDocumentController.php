<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Services\FoundDocumentService;
use Throwable;

final class FoundDocumentController
{
    public static function report(): void
    {
        try {
            $user = null;

            $authorization = Request::authorization();

            if ($authorization !== null && trim($authorization) !== '') {
                $user = AuthMiddleware::requireUser();
            }

            $data = Request::json();

            $finderUserId = $user !== null
                ? (string) $user['id']
                : null;

            $document = FoundDocumentService::report(
                $data,
                $finderUserId
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Found document reported successfully.',
                201
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function show(): void
    {
        try {
            $id = self::routeId();

            $document = FoundDocumentService::findById($id);

            if ($document === null) {
                Response::error(
                    'Found document not found.',
                    404
                );
            }

            $user = self::optionalUser();

            if ($user !== null) {
                $finderUserId = $document['finder_user_id'] ?? null;

                if (
                    $finderUserId !== null
                    && (string) $finderUserId !== (string) $user['id']
                ) {
                    Response::error(
                        'You are not allowed to access this document.',
                        403
                    );
                }
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

            $documents = FoundDocumentService::findByFinder(
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

    public static function consent(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $document = FoundDocumentService::grantConsent(
                $id,
                (string) $user['id']
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Finder contact consent granted.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function revokeConsent(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $document = FoundDocumentService::revokeConsent(
                $id,
                (string) $user['id']
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Finder contact consent revoked.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    public static function cancel(): void
    {
        try {
            $user = self::optionalUser();

            $id = self::routeId();

            $document = FoundDocumentService::cancel(
                $id,
                $user !== null ? (string) $user['id'] : null
            );

            Response::success(
                [
                    'document' => $document,
                ],
                'Found document report cancelled.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    private static function optionalUser(): ?array
    {
        $authorization = Request::authorization();

        if ($authorization === null || trim($authorization) === '') {
            return null;
        }

        return AuthMiddleware::requireUser();
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
            'Unable to process the found document request.',
            400
        );
    }

    private function __construct()
    {
    }
}
