<?php

declare(strict_types=1);

namespace App\Models\ApiRepositories;

use App\Models\Repositories\DB;

/**
 * Alpha検索API専用リポジトリ
 * 1回のクエリで全データ（hourly, daily, weekly）を取得してパフォーマンスを最適化
 */
class AlphaSearchApiRepository
{
    /**
     * メンバー数または作成日でソート
     */
    function findByMemberOrCreatedAt(OpenChatApiArgs $args): array
    {
        $sort = [
            'member' => 'oc.member',
            'created_at' => 'oc.api_created_at',
        ];

        $sortColumn = $sort[$args->sort] ?? $sort['member'];

        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $params = [];

        // カテゴリ条件
        $categoryWhere = $args->category ? "oc.category = :category" : "1";
        if ($args->category) {
            $params['category'] = $args->category;
        }

        // キーワード検索がない場合
        if (!$args->keyword) {
            $sql = "
                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent
                FROM
                    open_chat AS oc
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                ORDER BY
                    {$sortColumn} {$args->order}
                LIMIT {$limit} OFFSET {$offset}
            ";

            $result = DB::fetchAll($sql, $params);

            if (!$result || $args->page !== 0) {
                return $result;
            }

            // 1ページ目の場合は件数を含める
            $countSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere}";
            $countParams = $args->category ? ['category' => $args->category] : [];
            $result[0]['totalCount'] = DB::fetchColumn($countSql, $countParams);

            return $result;
        }

        // キーワード検索時：名前優先のUNIONクエリ
        return $this->findByKeywordWithPriority($args, $sortColumn, $categoryWhere);
    }

    /**
     * 1時間・24時間・1週間の増減でソート
     */
    function findByStatsRanking(OpenChatApiArgs $args, string $tableName): array
    {
        $sort = [
            'rank' => 'sr.id',
            'increase' => 'sr.diff_member',
            'rate' => 'sr.percent_increase',
        ];

        $sortColumn = $sort[$args->sort] ?? $sort['rate'];

        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $params = [];

        // カテゴリ条件
        $categoryWhere = $args->category ? "oc.category = :category" : "1";
        if ($args->category) {
            $params['category'] = $args->category;
        }

        // キーワード検索がない場合
        if (!$args->keyword) {
            $sql = "
                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent
                FROM
                    open_chat AS oc
                    JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                ORDER BY
                    {$sortColumn} {$args->order}
                LIMIT {$limit} OFFSET {$offset}
            ";

            $result = DB::fetchAll($sql, $params);

            // 結果が不足している場合、人数順で補完
            if ($result && count($result) < $limit) {
                $supplement = $this->findSupplementByMember($args, $categoryWhere, array_column($result, 'id'), $limit - count($result));
                $result = array_merge($result, $supplement);
            }

            if (!$result || $args->page !== 0) {
                return $result;
            }

            // 1ページ目の場合は件数を含める
            $countSql = "
                SELECT count(*) as count
                FROM open_chat AS oc
                JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                WHERE {$categoryWhere}
            ";
            $countParams = $args->category ? ['category' => $args->category] : [];
            $rankingCount = DB::fetchColumn($countSql, $countParams);

            // 全体の件数（ランキング + 補完可能な件数）
            $allCountSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere}";
            $allCountParams = $args->category ? ['category' => $args->category] : [];
            $result[0]['totalCount'] = DB::fetchColumn($allCountSql, $allCountParams);

            return $result;
        }

        // キーワード検索時：名前優先のUNIONクエリ
        return $this->findByStatsRankingWithKeyword($args, $tableName, $sortColumn, $categoryWhere);
    }

    /**
     * 人数順で補完データを取得（ランキングテーブルにないレコード）
     */
    private function findSupplementByMember(OpenChatApiArgs $args, string $categoryWhere, array $excludeIds, int $supplementLimit): array
    {
        if ($supplementLimit <= 0) {
            return [];
        }

        $params = [];
        if ($args->category) {
            $params['category'] = $args->category;
        }

        // 除外ID条件
        $excludeWhere = '1';
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeWhere = "oc.id NOT IN ({$placeholders})";
            $params = array_merge($params, $excludeIds);
        }

        $sql = "
            SELECT
                oc.id,
                oc.name,
                oc.description,
                oc.member,
                oc.img_url,
                oc.emblem,
                oc.join_method_type,
                oc.category,
                oc.created_at,
                oc.api_created_at,
                h.diff_member AS hourly_diff,
                h.percent_increase AS hourly_percent,
                d.diff_member AS daily_diff,
                d.percent_increase AS daily_percent,
                w.diff_member AS weekly_diff,
                w.percent_increase AS weekly_percent
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
            WHERE
                {$categoryWhere}
                AND {$excludeWhere}
            ORDER BY
                oc.member {$args->order}
            LIMIT {$supplementLimit}
        ";

        DB::connect();
        $stmt = DB::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * キーワード検索（名前優先）- member/created_at用
     */
    private function findByKeywordWithPriority(OpenChatApiArgs $args, string $sortColumn, string $categoryWhere): array
    {
        // キーワードを分割
        $normalizedKeyword = str_replace('　', ' ', $args->keyword);
        $keywords = array_filter(explode(' ', $normalizedKeyword), fn($k) => !empty(trim($k)));
        if (empty($keywords)) {
            return [];
        }

        $nameConditions = [];
        $descConditions = [];
        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $searchParams = [];

        if ($args->category) {
            $searchParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $nameConditions[] = "oc.name LIKE :keyword{$i}";
            $descConditions[] = "oc.description LIKE :keyword{$i}";
            $searchParams["keyword{$i}"] = "%{$kw}%";
        }

        $nameCondition = implode(' AND ', $nameConditions);
        $descCondition = implode(' AND ', $descConditions);

        $sortColumnAlias = str_replace('oc.', '', $sortColumn);

        // 名前一致を優先するUNIONクエリ
        $sql = "
            SELECT * FROM (
                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent,
                    1 as priority
                FROM
                    open_chat AS oc
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND ({$nameCondition})

                UNION

                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent,
                    2 as priority
                FROM
                    open_chat AS oc
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND NOT ({$nameCondition})
                    AND ({$descCondition})
            ) AS combined
            ORDER BY
                priority ASC, {$sortColumnAlias} {$args->order}
            LIMIT {$limit} OFFSET {$offset}
        ";

        DB::connect();
        $stmt = DB::$pdo->prepare($sql);
        $stmt->execute($searchParams);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$result || $args->page !== 0) {
            return $result;
        }

        // 1ページ目の場合は件数を含める
        $allConditions = [];
        $countParams = [];
        if ($args->category) {
            $countParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $allConditions[] = "(oc.name LIKE :keyword{$i} OR oc.description LIKE :keyword{$i})";
            $countParams["keyword{$i}"] = "%{$kw}%";
        }

        $allCondition = implode(' AND ', $allConditions);
        $countSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere} AND {$allCondition}";

        $countStmt = DB::$pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $result[0]['totalCount'] = $countStmt->fetchColumn();

        return $result;
    }

    /**
     * 人数順で補完データを取得（キーワード検索、ランキングテーブルにないレコード）
     */
    private function findSupplementByMemberWithKeyword(OpenChatApiArgs $args, string $categoryWhere, array $keywords, array $excludeIds, int $supplementLimit): array
    {
        if ($supplementLimit <= 0 || empty($keywords)) {
            return [];
        }

        $params = [];
        if ($args->category) {
            $params['category'] = $args->category;
        }

        // キーワード条件
        $keywordConditions = [];
        foreach ($keywords as $i => $kw) {
            $keywordConditions[] = "(oc.name LIKE :keyword{$i} OR oc.description LIKE :keyword{$i})";
            $params["keyword{$i}"] = "%{$kw}%";
        }
        $keywordCondition = implode(' AND ', $keywordConditions);

        // 除外ID条件
        $excludeWhere = '1';
        if (!empty($excludeIds)) {
            $excludeWhere = "oc.id NOT IN (" . implode(',', array_map('intval', $excludeIds)) . ")";
        }

        $sql = "
            SELECT
                oc.id,
                oc.name,
                oc.description,
                oc.member,
                oc.img_url,
                oc.emblem,
                oc.join_method_type,
                oc.category,
                oc.created_at,
                oc.api_created_at,
                h.diff_member AS hourly_diff,
                h.percent_increase AS hourly_percent,
                d.diff_member AS daily_diff,
                d.percent_increase AS daily_percent,
                w.diff_member AS weekly_diff,
                w.percent_increase AS weekly_percent
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
            WHERE
                {$categoryWhere}
                AND {$excludeWhere}
                AND ({$keywordCondition})
            ORDER BY
                oc.member {$args->order}
            LIMIT {$supplementLimit}
        ";

        DB::connect();
        $stmt = DB::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * キーワード検索（名前優先）- stats ranking用
     */
    private function findByStatsRankingWithKeyword(OpenChatApiArgs $args, string $tableName, string $sortColumn, string $categoryWhere): array
    {
        // キーワードを分割
        $normalizedKeyword = str_replace('　', ' ', $args->keyword);
        $keywords = array_filter(explode(' ', $normalizedKeyword), fn($k) => !empty(trim($k)));
        if (empty($keywords)) {
            return [];
        }

        $nameConditions = [];
        $descConditions = [];
        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $searchParams = [];

        if ($args->category) {
            $searchParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $nameConditions[] = "oc.name LIKE :keyword{$i}";
            $descConditions[] = "oc.description LIKE :keyword{$i}";
            $searchParams["keyword{$i}"] = "%{$kw}%";
        }

        $nameCondition = implode(' AND ', $nameConditions);
        $descCondition = implode(' AND ', $descConditions);

        $sortColumnAlias = str_replace('sr.', '', $sortColumn);

        // 名前一致を優先するUNIONクエリ
        $sql = "
            SELECT * FROM (
                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent,
                    sr.diff_member,
                    sr.percent_increase,
                    1 as priority
                FROM
                    open_chat AS oc
                    JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND ({$nameCondition})

                UNION

                SELECT
                    oc.id,
                    oc.name,
                    oc.description,
                    oc.member,
                    oc.img_url,
                    oc.emblem,
                    oc.join_method_type,
                    oc.category,
                    oc.created_at,
                    oc.api_created_at,
                    h.diff_member AS hourly_diff,
                    h.percent_increase AS hourly_percent,
                    d.diff_member AS daily_diff,
                    d.percent_increase AS daily_percent,
                    w.diff_member AS weekly_diff,
                    w.percent_increase AS weekly_percent,
                    sr.diff_member,
                    sr.percent_increase,
                    2 as priority
                FROM
                    open_chat AS oc
                    JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND NOT ({$nameCondition})
                    AND ({$descCondition})
            ) AS combined
            ORDER BY
                priority ASC, {$sortColumnAlias} {$args->order}
            LIMIT {$limit} OFFSET {$offset}
        ";

        DB::connect();
        $stmt = DB::$pdo->prepare($sql);
        $stmt->execute($searchParams);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 結果が不足している場合、人数順で補完
        if ($result && count($result) < $limit) {
            $supplement = $this->findSupplementByMemberWithKeyword($args, $categoryWhere, $keywords, array_column($result, 'id'), $limit - count($result));
            $result = array_merge($result, $supplement);
        }

        if (!$result || $args->page !== 0) {
            return $result;
        }

        // 1ページ目の場合は件数を含める
        $allConditions = [];
        $countParams = [];
        if ($args->category) {
            $countParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $allConditions[] = "(oc.name LIKE :keyword{$i} OR oc.description LIKE :keyword{$i})";
            $countParams["keyword{$i}"] = "%{$kw}%";
        }

        $allCondition = implode(' AND ', $allConditions);

        // ランキングテーブルとJOINした件数
        $rankingCountSql = "
            SELECT count(*) as count
            FROM open_chat AS oc
            JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
            WHERE {$categoryWhere} AND {$allCondition}
        ";
        $rankingCountStmt = DB::$pdo->prepare($rankingCountSql);
        $rankingCountStmt->execute($countParams);

        // 全体の件数（ランキング + 補完可能な件数）
        $allCountSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere} AND {$allCondition}";
        $allCountStmt = DB::$pdo->prepare($allCountSql);
        $allCountStmt->execute($countParams);
        $result[0]['totalCount'] = $allCountStmt->fetchColumn();

        return $result;
    }
}
