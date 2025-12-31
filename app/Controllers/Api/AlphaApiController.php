<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Repositories\DB;
use App\Models\SQLite\SQLiteStatistics;
use App\Models\SQLite\SQLiteRankingPosition;
use Shadow\Kernel\Reception;
use Shadow\Kernel\Validator;
use Shared\Exceptions\BadRequestException;

class AlphaApiController
{
    /**
     * 検索API
     * GET /alpha-api/search?keyword=xxx&category=0&page=0&limit=20
     */
    function search(
        string $keyword = '',
        int $category = 0,
        int $page = 0,
        int $limit = 20
    ) {
        DB::connect();

        $offset = $page * $limit;
        $keywordLike = '%' . $keyword . '%';

        $sql = "
            SELECT
                oc.id,
                oc.name,
                oc.description AS `desc`,
                oc.member,
                oc.local_img_url AS img,
                oc.emblem,
                oc.category,
                COALESCE(sr.diff_member, 0) AS increasedMember,
                COALESCE(sr.percent_increase, 0) AS percentageIncrease
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour24 AS sr ON oc.id = sr.open_chat_id
            WHERE
                1=1
        ";

        $params = [];

        // キーワード検索
        if ($keyword !== '') {
            $sql .= " AND (oc.name LIKE :keyword OR oc.description LIKE :keyword)";
            $params['keyword'] = $keywordLike;
        }

        // カテゴリフィルター
        if ($category > 0) {
            $sql .= " AND oc.category = :category";
            $params['category'] = $category;
        }

        $sql .= "
            ORDER BY oc.member DESC
            LIMIT :offset, :limit
        ";

        $stmt = DB::$pdo->prepare($sql);

        // 文字列パラメータをバインド
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }

        // LIMIT パラメータを整数としてバインド
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

        $stmt->execute();
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 総件数取得（簡易版：LIMITなしで同じ条件でCOUNT）
        $countSql = "
            SELECT COUNT(*) AS total
            FROM open_chat AS oc
            WHERE 1=1
        ";

        $countParams = [];
        if ($keyword !== '') {
            $countSql .= " AND (oc.name LIKE :keyword OR oc.description LIKE :keyword)";
            $countParams['keyword'] = $keywordLike;
        }
        if ($category > 0) {
            $countSql .= " AND oc.category = :category";
            $countParams['category'] = $category;
        }

        $countStmt = DB::$pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalCount = (int)$countStmt->fetchColumn();

        return response([
            'data' => $data,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * 統計データ取得API（グラフ用）
     * GET /alpha-api/stats/{open_chat_id}?bar=ranking&rankingCategory=all
     */
    function stats(
        int $open_chat_id,
        string $bar = '',
        string $rankingCategory = 'all'
    ) {
        // MySQLからオープンチャット情報取得
        DB::connect();
        $ocSql = "
            SELECT
                oc.id,
                oc.name,
                oc.member,
                oc.category,
                oc.description,
                oc.local_img_url,
                oc.emblem,
                oc.api_created_at,
                oc.created_at,
                oc.join_method_type,
                oc.url,
                COALESCE(sr.diff_member, 0) AS hourly_diff_member,
                COALESCE(sr.percent_increase, 0) AS hourly_percent_increase
            FROM
                open_chat AS oc
            LEFT JOIN
                statistics_ranking_hour24 AS sr ON oc.id = sr.open_chat_id
            WHERE
                oc.id = :id
        ";
        $ocStmt = DB::$pdo->prepare($ocSql);
        $ocStmt->execute(['id' => $open_chat_id]);
        $ocData = $ocStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$ocData) {
            return response(['error' => 'OpenChat not found'], 404);
        }

        // SQLiteから統計データ取得
        $pdo = SQLiteStatistics::connect();

        $sql = "
            SELECT
                date,
                member
            FROM
                statistics
            WHERE
                open_chat_id = :open_chat_id
            ORDER BY
                date ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['open_chat_id' => $open_chat_id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // dates と members の配列に分割
        $dates = [];
        $members = [];
        foreach ($rows as $row) {
            $dates[] = $row['date'];
            $members[] = (int)$row['member'];
        }

        // ランキングデータ取得（barパラメータがrankingまたはrisingの場合）
        $rankings = [];
        if ($bar === 'ranking' || $bar === 'rising') {
            $rankingPdo = SQLiteRankingPosition::connect();
            $table = $bar === 'ranking' ? 'ranking' : 'rising';

            // カテゴリー判定（all=0, category=オープンチャットのカテゴリー）
            $category = $rankingCategory === 'all' ? 0 : (int)$ocData['category'];

            $rankingSql = "
                SELECT
                    date,
                    position
                FROM
                    {$table}
                WHERE
                    open_chat_id = :open_chat_id
                    AND category = :category
                ORDER BY
                    date ASC
            ";

            $rankingStmt = $rankingPdo->prepare($rankingSql);
            $rankingStmt->execute([
                'open_chat_id' => $open_chat_id,
                'category' => $category
            ]);
            $rankingRows = $rankingStmt->fetchAll(\PDO::FETCH_ASSOC);

            // datesに合わせてランキングデータをマッピング
            $rankingMap = [];
            foreach ($rankingRows as $row) {
                $rankingMap[$row['date']] = (int)$row['position'];
            }

            foreach ($dates as $date) {
                $rankings[] = $rankingMap[$date] ?? null;
            }
        }

        return response([
            'id' => $open_chat_id,
            'name' => $ocData['name'],
            'currentMember' => (int)$ocData['member'],
            'category' => (int)$ocData['category'],
            'dates' => $dates,
            'members' => $members,
            'rankings' => $rankings,
            // 追加フィールド
            'description' => $ocData['description'] ?? '',
            'thumbnail' => $ocData['local_img_url'] ?? '',
            'emblem' => (int)($ocData['emblem'] ?? 0),
            'hourlyDiff' => (int)($ocData['hourly_diff_member'] ?? 0),
            'hourlyPercentage' => (float)($ocData['hourly_percent_increase'] ?? 0),
            'createdAt' => $ocData['created_at'] ? strtotime($ocData['created_at']) : null,
            'registeredAt' => $ocData['api_created_at'] ?? '',
            'joinMethodType' => (int)($ocData['join_method_type'] ?? 0),
            'url' => $ocData['url'] ?? '',
        ]);
    }

    /**
     * マイリスト用一括統計取得API
     * POST /alpha-api/batch-stats
     * Body: {"ids": [123, 456, 789]}
     */
    function batchStats(Reception $reception)
    {
        $json = $reception->input();

        if (!isset($json['ids']) || !is_array($json['ids'])) {
            throw new BadRequestException('ids parameter is required and must be an array');
        }

        $ids = array_map('intval', $json['ids']);

        // 最大50件に制限
        if (count($ids) > 50) {
            throw new BadRequestException('Maximum 50 IDs allowed');
        }

        if (empty($ids)) {
            return response(['data' => []]);
        }

        DB::connect();

        // IN句用のプレースホルダー作成
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "
            SELECT
                oc.id,
                oc.name,
                oc.member,
                oc.local_img_url AS img,
                oc.emblem,
                oc.category,
                COALESCE(sr.diff_member, 0) AS diff_member,
                COALESCE(sr.percent_increase, 0) AS percent_increase
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour24 AS sr ON oc.id = sr.open_chat_id
            WHERE
                oc.id IN ({$placeholders})
            ORDER BY
                FIELD(oc.id, {$placeholders})
        ";

        $stmt = DB::$pdo->prepare($sql);
        // パラメータを2回バインド（IN句とORDER BY FIELD用）
        $params = array_merge($ids, $ids);
        $stmt->execute($params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return response([
            'data' => $data,
        ]);
    }
}
