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
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.diff_member IS NULL THEN 0
                        ELSE h.diff_member
                    END AS hourly_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.percent_increase IS NULL THEN 0
                        ELSE h.percent_increase
                    END AS hourly_percent,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.diff_member IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.diff_member
                    END AS daily_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.percent_increase IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.percent_increase
                    END AS daily_percent,
                    CASE
                        WHEN w.diff_member IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.diff_member
                    END AS weekly_diff,
                    CASE
                        WHEN w.percent_increase IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.percent_increase
                    END AS weekly_percent,
                    (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                     FROM ocgraph_ranking.member AS m
                     WHERE m.open_chat_id = oc.id) AS is_in_ranking
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

        $sortColumn = $sort[$args->sort] ?? $sort['increase'];

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
            // ランキングテーブルの総件数を取得
            $countSql = "
                SELECT count(*) as count
                FROM open_chat AS oc
                JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                WHERE {$categoryWhere}
            ";
            $rankingCount = DB::fetchColumn($countSql, $params);

            // 最後のページかどうかを判定
            $isLastPageOrBeyond = ($offset + $limit >= $rankingCount);

            if (!$isLastPageOrBeyond) {
                // 最後のページでない場合は、ランキングデータのみを返す
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
                        w.percent_increase AS weekly_percent,
                        (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                         FROM ocgraph_ranking.member AS m
                         WHERE m.open_chat_id = oc.id) AS is_in_ranking
                    FROM
                        open_chat AS oc
                        JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                        LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                        LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                        LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                    WHERE
                        {$categoryWhere}
                    ORDER BY
                        {$sortColumn} {$args->order},
                        oc.member DESC
                    LIMIT {$limit} OFFSET {$offset}
                ";

                $result = DB::fetchAll($sql, $params);

                if (!$result || $args->page !== 0) {
                    return $result;
                }

                // 1ページ目の場合は件数を含める（全体の件数）
                $allCountSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere}";
                $allCountParams = $args->category ? ['category' => $args->category] : [];
                $result[0]['totalCount'] = DB::fetchColumn($allCountSql, $allCountParams);

                return $result;
            }

            // 最後のページまたはそれ以降の場合は、補完データも含める
            // 1・24時間ソートの場合のみ is_in_ranking を優先（ランキング非掲載を最下位に）
            $isWeeklySort = ($tableName === 'statistics_ranking_week');
            $orderByClause = $isWeeklySort
                ? "priority ASC, sort_value {$args->order}, member DESC"
                : "is_in_ranking DESC, priority ASC, sort_value {$args->order}, member DESC";

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
                        (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                         FROM ocgraph_ranking.member AS m
                         WHERE m.open_chat_id = oc.id) AS is_in_ranking,
                        {$sortColumn} AS sort_value,
                        1 AS priority
                    FROM
                        open_chat AS oc
                        JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                        LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                        LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                        LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                    WHERE
                        {$categoryWhere}

                    UNION ALL

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
                        (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                         FROM ocgraph_ranking.member AS m
                         WHERE m.open_chat_id = oc.id) AS is_in_ranking,
                        oc.member AS sort_value,
                        2 AS priority
                    FROM
                        open_chat AS oc
                        LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                        LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                        LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                    WHERE
                        {$categoryWhere}
                        AND oc.id NOT IN (
                            SELECT open_chat_id FROM {$tableName} WHERE 1
                        )
                ) AS combined
                ORDER BY
                    {$orderByClause}
                LIMIT {$limit} OFFSET {$offset}
            ";

            $result = DB::fetchAll($sql, $params);

            if (!$result || $args->page !== 0) {
                return $result;
            }

            // 1ページ目の場合は件数を含める（全体の件数）
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
     * キーワード検索 - member/created_at用
     */
    private function findByKeywordWithPriority(OpenChatApiArgs $args, string $sortColumn, string $categoryWhere): array
    {
        // キーワードを分割
        $normalizedKeyword = str_replace('　', ' ', $args->keyword);
        $keywords = array_filter(explode(' ', $normalizedKeyword), fn($k) => !empty(trim($k)));
        if (empty($keywords)) {
            return [];
        }

        $allConditions = [];
        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $searchParams = [];

        if ($args->category) {
            $searchParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $allConditions[] = "(oc.name LIKE :keyword{$i} OR oc.description LIKE :keyword{$i})";
            $searchParams["keyword{$i}"] = "%{$kw}%";
        }

        $allCondition = implode(' AND ', $allConditions);

        // タイトルまたは説明文に一致するものを取得
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
                CASE
                    WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                    WHEN h.diff_member IS NULL THEN 0
                    ELSE h.diff_member
                END AS hourly_diff,
                CASE
                    WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                    WHEN h.percent_increase IS NULL THEN 0
                    ELSE h.percent_increase
                END AS hourly_percent,
                CASE
                    WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                    WHEN d.diff_member IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                    ELSE d.diff_member
                END AS daily_diff,
                CASE
                    WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                    WHEN d.percent_increase IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                    ELSE d.percent_increase
                END AS daily_percent,
                CASE
                    WHEN w.diff_member IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                    ELSE w.diff_member
                END AS weekly_diff,
                CASE
                    WHEN w.percent_increase IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                    ELSE w.percent_increase
                END AS weekly_percent,
                (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                 FROM ocgraph_ranking.member AS m
                 WHERE m.open_chat_id = oc.id) AS is_in_ranking
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
            WHERE
                {$categoryWhere}
                AND {$allCondition}
            ORDER BY
                {$sortColumn} {$args->order}
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
        $countParams = [];
        if ($args->category) {
            $countParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $countParams["keyword{$i}"] = "%{$kw}%";
        }

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
     * キーワード検索 - stats ranking用
     */
    private function findByStatsRankingWithKeyword(OpenChatApiArgs $args, string $tableName, string $sortColumn, string $categoryWhere): array
    {
        // キーワードを分割
        $normalizedKeyword = str_replace('　', ' ', $args->keyword);
        $keywords = array_filter(explode(' ', $normalizedKeyword), fn($k) => !empty(trim($k)));
        if (empty($keywords)) {
            return [];
        }

        $allConditions = [];
        $offset = $args->page * $args->limit;
        $limit = $args->limit;
        $searchParams = [];

        if ($args->category) {
            $searchParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $allConditions[] = "(oc.name LIKE :keyword{$i} OR oc.description LIKE :keyword{$i})";
            $searchParams["keyword{$i}"] = "%{$kw}%";
        }

        $allCondition = implode(' AND ', $allConditions);

        // ランキングテーブルの総件数を取得
        $rankingCountSql = "
            SELECT count(*) as count
            FROM open_chat AS oc
            JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
            WHERE {$categoryWhere} AND {$allCondition}
        ";
        DB::connect();
        $rankingCountStmt = DB::$pdo->prepare($rankingCountSql);
        $rankingCountStmt->execute($searchParams);
        $rankingCount = $rankingCountStmt->fetchColumn();

        // 最後のページかどうかを判定
        $isLastPageOrBeyond = ($offset + $limit >= $rankingCount);

        // 1・24時間ソートの場合のみ is_in_ranking を優先（ランキング非掲載を最下位に）
        $isWeeklySort = ($tableName === 'statistics_ranking_week');
        $simpleOrderBy = $isWeeklySort
            ? "{$sortColumn} {$args->order}, oc.member DESC"
            : "is_in_ranking DESC, {$sortColumn} {$args->order}, oc.member DESC";

        if (!$isLastPageOrBeyond) {
            // 最後のページでない場合は、ランキングデータのみを返す
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
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.diff_member IS NULL THEN 0
                        ELSE h.diff_member
                    END AS hourly_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.percent_increase IS NULL THEN 0
                        ELSE h.percent_increase
                    END AS hourly_percent,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.diff_member IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.diff_member
                    END AS daily_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.percent_increase IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.percent_increase
                    END AS daily_percent,
                    CASE
                        WHEN w.diff_member IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.diff_member
                    END AS weekly_diff,
                    CASE
                        WHEN w.percent_increase IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.percent_increase
                    END AS weekly_percent,
                    (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                     FROM ocgraph_ranking.member AS m
                     WHERE m.open_chat_id = oc.id) AS is_in_ranking
                FROM
                    open_chat AS oc
                    JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND {$allCondition}
                ORDER BY
                    {$simpleOrderBy}
                LIMIT {$limit} OFFSET {$offset}
            ";

            $stmt = DB::$pdo->prepare($sql);
            $stmt->execute($searchParams);
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!$result || $args->page !== 0) {
                return $result;
            }

            // 1ページ目の場合は件数を含める（全体の件数）
            $allCountSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere} AND {$allCondition}";
            $allCountStmt = DB::$pdo->prepare($allCountSql);
            $allCountStmt->execute($searchParams);
            $result[0]['totalCount'] = $allCountStmt->fetchColumn();

            return $result;
        }

        // 最後のページまたはそれ以降の場合は、補完データも含める
        // 1・24時間ソートの場合のみ is_in_ranking を優先（ランキング非掲載を最下位に）
        $isWeeklySort = ($tableName === 'statistics_ranking_week');
        $orderByClause = $isWeeklySort
            ? "priority ASC, sort_value {$args->order}, member DESC"
            : "is_in_ranking DESC, priority ASC, sort_value {$args->order}, member DESC";

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
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.diff_member IS NULL THEN 0
                        ELSE h.diff_member
                    END AS hourly_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.percent_increase IS NULL THEN 0
                        ELSE h.percent_increase
                    END AS hourly_percent,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.diff_member IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.diff_member
                    END AS daily_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.percent_increase IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.percent_increase
                    END AS daily_percent,
                    CASE
                        WHEN w.diff_member IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.diff_member
                    END AS weekly_diff,
                    CASE
                        WHEN w.percent_increase IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.percent_increase
                    END AS weekly_percent,
                    (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                     FROM ocgraph_ranking.member AS m
                     WHERE m.open_chat_id = oc.id) AS is_in_ranking,
                    {$sortColumn} AS sort_value,
                    1 as priority
                FROM
                    open_chat AS oc
                    JOIN {$tableName} AS sr ON oc.id = sr.open_chat_id
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND {$allCondition}

                UNION ALL

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
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.diff_member IS NULL THEN 0
                        ELSE h.diff_member
                    END AS hourly_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN h.percent_increase IS NULL THEN 0
                        ELSE h.percent_increase
                    END AS hourly_percent,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.diff_member IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.diff_member
                    END AS daily_diff,
                    CASE
                        WHEN (SELECT COUNT(*) FROM ocgraph_ranking.member WHERE open_chat_id = oc.id) = 0 THEN NULL
                        WHEN d.percent_increase IS NULL AND TIMESTAMPDIFF(HOUR, oc.created_at, NOW()) >= 24 THEN 0
                        ELSE d.percent_increase
                    END AS daily_percent,
                    CASE
                        WHEN w.diff_member IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.diff_member
                    END AS weekly_diff,
                    CASE
                        WHEN w.percent_increase IS NULL AND TIMESTAMPDIFF(DAY, oc.created_at, NOW()) >= 7 THEN 0
                        ELSE w.percent_increase
                    END AS weekly_percent,
                    (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                     FROM ocgraph_ranking.member AS m
                     WHERE m.open_chat_id = oc.id) AS is_in_ranking,
                    oc.member AS sort_value,
                    2 as priority
                FROM
                    open_chat AS oc
                    LEFT JOIN statistics_ranking_hour AS h ON oc.id = h.open_chat_id
                    LEFT JOIN statistics_ranking_hour24 AS d ON oc.id = d.open_chat_id
                    LEFT JOIN statistics_ranking_week AS w ON oc.id = w.open_chat_id
                WHERE
                    {$categoryWhere}
                    AND {$allCondition}
                    AND oc.id NOT IN (
                        SELECT open_chat_id FROM {$tableName} WHERE 1
                    )
            ) AS combined
            ORDER BY
                {$orderByClause}
            LIMIT {$limit} OFFSET {$offset}
        ";

        DB::connect();
        $stmt = DB::$pdo->prepare($sql);
        $stmt->execute($searchParams);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$result || $args->page !== 0) {
            return $result;
        }

        // 1ページ目の場合は件数を含める（全体の件数）
        $countParams = [];
        if ($args->category) {
            $countParams['category'] = $args->category;
        }

        foreach ($keywords as $i => $kw) {
            $countParams["keyword{$i}"] = "%{$kw}%";
        }

        $allCountSql = "SELECT count(*) as count FROM open_chat AS oc WHERE {$categoryWhere} AND {$allCondition}";
        $allCountStmt = DB::$pdo->prepare($allCountSql);
        $allCountStmt->execute($countParams);
        $result[0]['totalCount'] = $allCountStmt->fetchColumn();

        return $result;
    }
}
