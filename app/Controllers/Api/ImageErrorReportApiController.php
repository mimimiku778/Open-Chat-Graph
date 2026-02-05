<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Repositories\Log\LogRepositoryInterface;

class ImageErrorReportApiController
{
    public function index(
        LogRepositoryInterface $logRepository,
        string $url
    ) {
        $logRepository->logOpenChatImageStoreError($url, 'client-reported-404');

        return response(['success' => true]);
    }
}
