<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\Repositories\Statistics\StatisticsOhlcRepositoryInterface;
use App\Models\Repositories\Statistics\StatisticsPageRepositoryInterface;
use App\Services\Statistics\Dto\StatisticsChartDto;

class StatisticsChartArrayService
{
    function __construct(
        private StatisticsPageRepositoryInterface $statisticsPageRepository,
        private StatisticsOhlcRepositoryInterface $statisticsOhlcRepository,
    ) {}

    /**
     * 日毎のメンバー数の統計を取得する
     *
     * @return array{ date: string, member: int }[] date: Y-m-d
     */
    function buildStatisticsChartArray(int $open_chat_id): StatisticsChartDto|false
    {
        $memberStats = $this->statisticsPageRepository->getDailyMemberStatsDateAsc($open_chat_id);

        if (!$memberStats) {
            return false;
        }

        $ohlcStats = $this->statisticsOhlcRepository->getOhlcDateAsc($open_chat_id);

        $dto = new StatisticsChartDto($memberStats[0]['date'], $memberStats[count($memberStats) - 1]['date']);

        return $this->generateChartArray(
            $dto,
            $this->generateDateArray($dto->startDate, $dto->endDate),
            $memberStats,
            $ohlcStats
        );
    }

    /**  
     *  @param string $startDate `Y-m-d`
     *  @return string[]
     */
    private function generateDateArray(string $startDate, string $endDate): array
    {
        $first = new \DateTime($startDate);
        $interval = $first->diff(new \DateTime($endDate))->days;

        $dateArray = [];
        $i = 0;

        while ($i <= $interval) {
            $dateArray[] = $first->format('Y-m-d');
            $first->modify('+1 day');
            $i++;
        }

        return $dateArray;
    }

    /**
     * @param string[] $dateArray
     * @param array{ date:string, member:int }[] $memberStats
     * @param array{ date:string, open_member:int, high_member:int, low_member:int, close_member:int }[] $ohlcStats
     */
    private function generateChartArray(StatisticsChartDto $dto, array $dateArray, array $memberStats, array $ohlcStats = []): StatisticsChartDto
    {
        $getMemberStatsCurDate = fn(int $key): string => $memberStats[$key]['date'] ?? '';
        $getOhlcStatsCurDate = fn(int $key): string => $ohlcStats[$key]['date'] ?? '';

        $curKeyMemberStats = 0;
        $memberStatsCurDate = $getMemberStatsCurDate(0);

        $curKeyOhlcStats = 0;
        $ohlcStatsCurDate = $getOhlcStatsCurDate(0);

        foreach ($dateArray as $date) {
            $matchMemberStats = $memberStatsCurDate === $date;

            $member = null;
            if ($matchMemberStats) {
                $member = $memberStats[$curKeyMemberStats]['member'];
                $curKeyMemberStats++;
                $memberStatsCurDate = $getMemberStatsCurDate($curKeyMemberStats);
            }

            $dto->addValue(
                $date,
                $member,
            );

            $matchOhlcStats = $ohlcStatsCurDate === $date;

            if ($matchOhlcStats) {
                $dto->addOhlcValue(
                    $ohlcStats[$curKeyOhlcStats]['open_member'],
                    $ohlcStats[$curKeyOhlcStats]['high_member'],
                    $ohlcStats[$curKeyOhlcStats]['low_member'],
                    $ohlcStats[$curKeyOhlcStats]['close_member'],
                );
                $curKeyOhlcStats++;
                $ohlcStatsCurDate = $getOhlcStatsCurDate($curKeyOhlcStats);
            } else {
                $dto->addOhlcValue(null, null, null, null);
            }
        }

        return $dto;
    }
}
