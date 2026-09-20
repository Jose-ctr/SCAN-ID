<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\Request;
use ScanId\Http\Response;

final class ReportController
{
    /**
     * Prepare a found-ID report.
     *
     * POST /api/report
     */
    public static function store(): never
    {
        if (Request::method() !== 'POST') {
            Response::error(
                'Method not allowed.',
                405
            );
        }

        if (!Request::isJson()) {
            Response::error(
                'Content-Type must be application/json.',
                415
            );
        }

        $data = Request::json();

        $idType = self::stringValue(
            $data['id_type'] ?? null
        );

        $idNumber = self::stringValue(
            $data['id_number'] ?? null
        );

        $locationFound = self::stringValue(
            $data['location_found'] ?? null
        );

        $reporterPhone = self::stringValue(
            $data['reporter_phone'] ?? null
        );

        if ($idType === '') {
            Response::error(
                'ID type is required.',
                422
            );
        }

        if ($idNumber === '') {
            Response::error(
                'ID number is required.',
                422
            );
        }

        if ($locationFound === '') {
            Response::error(
                'Location where the ID was found is required.',
                422
            );
        }

        if ($reporterPhone === '') {
            Response::error(
                'Reporter phone number is required.',
                422
            );
        }

        if (mb_strlen($idType) > 50) {
            Response::error(
                'ID type is too long.',
                422
            );
        }

        if (mb_strlen($idNumber) > 100) {
            Response::error(
                'ID number is too long.',
                422
            );
        }

        if (mb_strlen($locationFound) > 255) {
            Response::error(
                'Location is too long.',
                422
            );
        }

        if (mb_strlen($reporterPhone) > 30) {
            Response::error(
                'Phone number is too long.',
                422
            );
        }

        Response::success(
            [
                'id_type' => $idType,
                'id_number' => $idNumber,
                'location_found' => $locationFound,
                'reporter_phone' => $reporterPhone,
                'status' => 'validated',
            ],
            200
        );
    }

    /**
     * Convert an input value into a trimmed string.
     */
    private static function stringValue(
        mixed $value
    ): string {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
