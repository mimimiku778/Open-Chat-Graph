<?php

declare(strict_types=1);

namespace App\Models\RecommendRepositories;

use App\Models\Repositories\DB;

class BulkRankingDataRepository implements BulkRankingDataRepositoryInterface
{
    private const BASE_SELECT = "SELECT
                oc.id,
                oc.name,
                oc.img_url,
                oc.img_url AS api_img_url,
                oc.member,
                oc.description,
                oc.emblem,
                oc.category,
                oc.emid,
                oc.url,
                oc.api_created_at,
                oc.created_at,
                oc.updated_at,
                oc.join_method_type,
                r.tag AS recommend_tag,
                t1.tag AS oc_tag,
                t2.tag AS oc_tag2,
                sh.diff_member AS hour_diff,
                sh24.diff_member AS hour24_diff,
                sw.diff_member AS week_diff
            FROM
                open_chat AS oc
                LEFT JOIN recommend AS r ON r.id = oc.id
                LEFT JOIN oc_tag AS t1 ON t1.id = oc.id
                LEFT JOIN oc_tag2 AS t2 ON t2.id = oc.id
                LEFT JOIN statistics_ranking_hour AS sh ON sh.open_chat_id = oc.id
                LEFT JOIN statistics_ranking_hour24 AS sh24 ON sh24.open_chat_id = oc.id
                LEFT JOIN statistics_ranking_week AS sw ON sw.open_chat_id = oc.id";

    private const STATS_WHERE = "(sh.open_chat_id IS NOT NULL
                OR sh24.open_chat_id IS NOT NULL
                OR sw.open_chat_id IS NOT NULL
                OR oc.member >= 15)";

    /**
     * 全オープンチャットデータを1クエリで取得する
     *
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchAll(): array
    {
        return $this->indexById(DB::fetchAll(
            self::BASE_SELECT . " WHERE " . self::STATS_WHERE
        ));
    }

    /**
     * 指定したrecommend_tagを持つオープンチャットデータを取得する
     *
     * @param string[] $tags recommend_tagのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByRecommendTags(array $tags): array
    {
        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        return $this->indexById(DB::fetchAll(
            self::BASE_SELECT . " WHERE r.tag IN ({$placeholders}) AND " . self::STATS_WHERE,
            $tags
        ));
    }

    /**
     * 指定したカテゴリのオープンチャットデータを取得する
     *
     * @param int[] $categories カテゴリのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByCategories(array $categories): array
    {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        return $this->indexById(DB::fetchAll(
            self::BASE_SELECT . " WHERE oc.category IN ({$placeholders}) AND " . self::STATS_WHERE,
            $categories
        ));
    }

    /**
     * 指定したエンブレムのオープンチャットデータを取得する
     *
     * @param int[] $emblems エンブレムのリスト
     * @return array<int, array> IDをキーとした連想配列
     */
    function fetchByEmblems(array $emblems): array
    {
        $placeholders = implode(',', array_fill(0, count($emblems), '?'));
        return $this->indexById(DB::fetchAll(
            self::BASE_SELECT . " WHERE oc.emblem IN ({$placeholders}) AND " . self::STATS_WHERE,
            $emblems
        ));
    }

    /**
     * @param array<array> $rows
     * @return array<int, array> IDをキーとした連想配列
     */
    private function indexById(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['id']] = $row;
        }
        return $indexed;
    }
}
