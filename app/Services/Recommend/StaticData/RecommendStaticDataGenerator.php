<?php

declare(strict_types=1);

namespace App\Services\Recommend\StaticData;

use App\Config\AppConfig;
use App\Models\RecommendRepositories\BulkRankingDataRepositoryInterface;
use App\Services\Recommend\BulkRecommendRankingBuilderInterface;
use App\Services\Recommend\Dto\RecommendListDto;
use App\Services\Recommend\RecommendUpdater;
use App\Services\Storage\FileStorageInterface;
use Shared\MimimalCmsConfig;

class RecommendStaticDataGenerator
{
    /** タグランキングを分割処理するチャンク数 */
    private const TAG_CHUNK_COUNT = 10;

    function __construct(
        private RecommendUpdater $recommendUpdater,
        private FileStorageInterface $fileStorage,
        private BulkRankingDataRepositoryInterface $bulkRankingDataRepository,
        private BulkRecommendRankingBuilderInterface $bulkRecommendRankingBuilder,
    ) {}

    function getRecomendRanking(string $tag): RecommendListDto
    {
        return $this->bulkRecommendRankingBuilder->buildTagRanking($tag, $tag);
    }

    function getCategoryRanking(int $category): RecommendListDto
    {
        return $this->bulkRecommendRankingBuilder->buildCategoryRanking($category, getCategoryName($category));
    }

    function getOfficialRanking(int $emblem): RecommendListDto|false
    {
        $listName = match ($emblem) {
            1 => AppConfig::OFFICIAL_EMBLEMS[MimimalCmsConfig::$urlRoot][1],
            2 => AppConfig::OFFICIAL_EMBLEMS[MimimalCmsConfig::$urlRoot][2],
            default => ''
        };

        return $listName ? $this->bulkRecommendRankingBuilder->buildOfficialRanking($emblem, $listName) : false;
    }

    /**
     * @return string[]
     */
    function getAllTagNames(): array
    {
        return $this->recommendUpdater->getAllTagNames();
    }

    function updateStaticData()
    {
        // タグランキング: recommend_tagでチャンク分割して処理（メモリスパイク抑制）
        $allTags = $this->getAllTagNames();
        // タグ数がチャンク数より少ない場合は実際のタグ数分のチャンクになる
        $chunkSize = max(1, (int)ceil(count($allTags) / self::TAG_CHUNK_COUNT));
        foreach (array_chunk($allTags, $chunkSize) as $tagChunk) {
            $chunkData = $this->bulkRankingDataRepository->fetchByRecommendTags($tagChunk);
            $this->bulkRecommendRankingBuilder->init($chunkData);
            $this->updateRecommendStaticDataBulk($tagChunk);
            unset($chunkData);
        }

        $this->updateCategoryStaticDataBulk();
        $this->updateOfficialStaticDataBulk();
    }

    private function updateRecommendStaticDataBulk(array $tags): void
    {
        foreach ($tags as $tag) {
            $fileName = hash('crc32', $tag);
            $this->fileStorage->saveSerializedFile(
                $this->fileStorage->getStorageFilePath('recommendStaticDataDir') . "/{$fileName}.dat",
                $this->bulkRecommendRankingBuilder->buildTagRanking($tag, $tag)
            );
        }
    }

    private function updateCategoryStaticDataBulk(): void
    {
        foreach (AppConfig::OPEN_CHAT_CATEGORY[MimimalCmsConfig::$urlRoot] as $category) {
            $categoryData = $this->bulkRankingDataRepository->fetchByCategories([$category]);
            $this->bulkRecommendRankingBuilder->init($categoryData);
            $this->fileStorage->saveSerializedFile(
                $this->fileStorage->getStorageFilePath('categoryStaticDataDir') . "/{$category}.dat",
                $this->bulkRecommendRankingBuilder->buildCategoryRanking($category, getCategoryName($category))
            );
            unset($categoryData);
        }
    }

    private function updateOfficialStaticDataBulk(): void
    {
        foreach ([1, 2] as $emblem) {
            $listName = match ($emblem) {
                1 => AppConfig::OFFICIAL_EMBLEMS[MimimalCmsConfig::$urlRoot][1],
                2 => AppConfig::OFFICIAL_EMBLEMS[MimimalCmsConfig::$urlRoot][2],
                default => ''
            };

            if ($listName) {
                $emblemData = $this->bulkRankingDataRepository->fetchByEmblems([$emblem]);
                $this->bulkRecommendRankingBuilder->init($emblemData);
                $this->fileStorage->saveSerializedFile(
                    $this->fileStorage->getStorageFilePath('officialStaticDataDir') . "/{$emblem}.dat",
                    $this->bulkRecommendRankingBuilder->buildOfficialRanking($emblem, $listName)
                );
                unset($emblemData);
            }
        }
    }
}
