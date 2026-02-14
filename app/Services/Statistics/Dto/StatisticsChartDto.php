<?php

declare(strict_types=1);

namespace App\Services\Statistics\Dto;

class StatisticsChartDto
{
    /** @var string[] Y-m-d */
    public array $date = [];

    /** @var (int|null)[] */
    public array $member = [];

    /** @var (int|null)[] */
    public array $open = [];

    /** @var (int|null)[] */
    public array $high = [];

    /** @var (int|null)[] */
    public array $low = [];

    /** @var (int|null)[] */
    public array $close = [];

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

    function addOhlcValue(?int $open, ?int $high, ?int $low, ?int $close)
    {
        $this->open[] = $open;
        $this->high[] = $high;
        $this->low[] = $low;
        $this->close[] = $close;
    }
}
