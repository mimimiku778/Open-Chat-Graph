<?php

declare(strict_types=1);

namespace App\Models\RecommendRepositories;

interface BulkRankingDataRepositoryInterface
{
    /**
     * 全オープンチャットデータを1クエリで取得する
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     img_url: string,
     *     api_img_url: string,
     *     member: string,
     *     description: string,
     *     emblem: string,
     *     category: string,
     *     emid: string,
     *     url: string,
     *     api_created_at: string,
     *     created_at: string,
     *     updated_at: string,
     *     join_method_type: string,
     *     recommend_tag: ?string,
     *     oc_tag: ?string,
     *     oc_tag2: ?string,
     *     hour_diff: ?string,
     *     hour24_diff: ?string,
     *     week_diff: ?string,
     * }> IDをキーとした連想配列
     */
    function fetchAll(): array;

    /**
     * 指定したrecommend_tagを持つオープンチャットデータを取得する
     *
     * @param string[] $tags recommend_tagのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByRecommendTags(array $tags): array;

    /**
     * 指定したカテゴリのオープンチャットデータを取得する
     *
     * @param int[] $categories カテゴリのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByCategories(array $categories): array;

    /**
     * 指定したエンブレムのオープンチャットデータを取得する
     *
     * @param int[] $emblems エンブレムのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByEmblems(array $emblems): array;
}
