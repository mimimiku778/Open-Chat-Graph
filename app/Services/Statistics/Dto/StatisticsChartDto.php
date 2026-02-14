<?php

declare(strict_types=1);

namespace App\Services\Statistics\Dto;

class StatisticsChartDto
{
    /** @var string[] Y-m-d */
    public array $date = [];

    /** @var (int|null)[] */
    public array $member = [];

    public string $startDate = '';

    public string $endDate = '';

    function __construct(string $startDate, string $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    function addValue(string $date, int|null $member)
    {
        $this->date[] = $date;
        $this->member[] = $member;
    }
}
