<?php

declare(strict_types=1);

namespace App\Models\Repositories\RankingPosition;

interface RankingPositionOhlcRepositoryInterface
{
    /**
     * @param array{ open_chat_id: int, category: int, type: string, open_position: int, high_position: int, low_position: int, close_position: int, date: string }[] $data
     */
    public function insertOhlc(array $data): int;

    /**
     * @return array{ date: string, open_position: int, high_position: int, low_position: int, close_position: int }[]
     */
    public function getOhlcDateAsc(int $open_chat_id, int $category, string $type): array;
}
