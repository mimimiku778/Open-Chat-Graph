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
     * カテゴリIDから名前を取得
     */
    private function getCategoryName(int $categoryId): string
    {
        $categories = \App\Config\AppConfig::OPEN_CHAT_CATEGORY[''];
        foreach ($categories as $name => $id) {
            if ($id === $categoryId) {
                return $name;
            }
        }
        return '';
    }

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

        $sql = "
            SELECT
                oc.id,
                oc.name,
                oc.description AS `desc`,
                oc.member,
                oc.img_url,
                oc.local_img_url AS img,
                oc.emblem,
                oc.category,
                oc.join_method_type,
                COALESCE(sr.diff_member, 0) AS increasedMember,
                COALESCE(sr.percent_increase, 0) AS percentageIncrease,
                oc.created_at,
                oc.api_created_at
            FROM
                open_chat AS oc
                LEFT JOIN statistics_ranking_hour24 AS sr ON oc.id = sr.open_chat_id
            WHERE
                1=1
        ";

        $params = [];

        // キーワード検索（スペース区切りでAND検索）
        if ($keyword !== '') {
            $keywords = preg_split('/\s+/', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($keywords as $index => $kw) {
                $paramKey = "keyword{$index}";
                $sql .= " AND (oc.name LIKE :{$paramKey} OR oc.description LIKE :{$paramKey})";
                $params[$paramKey] = '%' . $kw . '%';
            }
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

        // 画像URLを変換し、IDリストを作成
        $ids = [];
        foreach ($data as &$item) {
            $ids[] = $item['id'];
            // img_urlをobs.line-scdn.net形式に変換
            if (!empty($item['img_url'])) {
                $item['img'] = 'https://obs.line-scdn.net/' . $item['img_url'];
            }
            unset($item['img_url']);

            // カテゴリ名を追加
            $item['categoryName'] = $this->getCategoryName((int)$item['category']);

            // 作成日と登録日を追加
            $item['createdAt'] = !empty($item['created_at']) ? strtotime($item['created_at']) : null;
            $item['registeredAt'] = $item['api_created_at'] ?? '';
            unset($item['created_at']);
            unset($item['api_created_at']);
        }
        unset($item);

        // 24時間と1週間のデータを一括取得
        if (!empty($ids)) {
            $pdo = \App\Models\SQLite\SQLiteStatistics::connect();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statsSql = "
                SELECT
                    open_chat_id,
                    date,
                    member
                FROM statistics
                WHERE open_chat_id IN ($placeholders)
                ORDER BY open_chat_id, date DESC
            ";
            $statsStmt = $pdo->prepare($statsSql);
            $statsStmt->execute($ids);
            $statsRows = $statsStmt->fetchAll(\PDO::FETCH_ASSOC);

            // IDごとにメンバー数の配列を作成
            $membersById = [];
            foreach ($statsRows as $row) {
                $membersById[$row['open_chat_id']][] = (int)$row['member'];
            }

            // 各検索結果に24時間と1週間の差分を追加
            foreach ($data as &$item) {
                $members = $membersById[$item['id']] ?? [];
                $maxIndex = count($members) - 1;

                $item['diff24h'] = 0;
                $item['percent24h'] = 0.0;
                $item['diff1w'] = 0;
                $item['percent1w'] = 0.0;

                if ($maxIndex >= 1 && $members[$maxIndex - 1] > 0) {
                    $item['diff24h'] = $members[$maxIndex] - $members[$maxIndex - 1];
                    $item['percent24h'] = floor(($item['diff24h'] / $members[$maxIndex - 1]) * 100 * 1000000) / 1000000;
                }

                if ($maxIndex >= 7 && $members[$maxIndex - 7] > 0) {
                    $item['diff1w'] = $members[$maxIndex] - $members[$maxIndex - 7];
                    $item['percent1w'] = floor(($item['diff1w'] / $members[$maxIndex - 7]) * 100 * 1000000) / 1000000;
                }
            }
            unset($item);
        }

        // 総件数取得（簡易版：LIMITなしで同じ条件でCOUNT）
        $countSql = "
            SELECT COUNT(*) AS total
            FROM open_chat AS oc
            WHERE 1=1
        ";

        $countParams = [];
        if ($keyword !== '') {
            $keywords = preg_split('/\s+/', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($keywords as $index => $kw) {
                $paramKey = "keyword{$index}";
                $countSql .= " AND (oc.name LIKE :{$paramKey} OR oc.description LIKE :{$paramKey})";
                $countParams[$paramKey] = '%' . $kw . '%';
            }
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
                oc.img_url,
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

        // 24時間と1週間の差分を計算
        $maxIndex = count($members) - 1;
        $diff24h = 0;
        $percent24h = 0.0;
        $diff1w = 0;
        $percent1w = 0.0;

        if ($maxIndex >= 1 && $members[$maxIndex - 1] > 0) {
            $diff24h = $members[$maxIndex] - $members[$maxIndex - 1];
            $percent24h = ($diff24h / $members[$maxIndex - 1]) * 100;
            $percent24h = floor($percent24h * 1000000) / 1000000;
        }

        if ($maxIndex >= 7 && $members[$maxIndex - 7] > 0) {
            $diff1w = $members[$maxIndex] - $members[$maxIndex - 7];
            $percent1w = ($diff1w / $members[$maxIndex - 7]) * 100;
            $percent1w = floor($percent1w * 1000000) / 1000000;
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

        // URLをLINE形式に変換
        $lineUrl = '';
        if (!empty($ocData['url'])) {
            // すでに完全なURLの場合はそのまま使用
            if (strpos($ocData['url'], 'http') === 0) {
                $lineUrl = $ocData['url'];
            } else {
                // ハッシュのみの場合は https://line.me/ti/g2/{hash} 形式に変換
                $hash = trim($ocData['url'], '/');
                if (!empty($hash)) {
                    $lineUrl = 'https://line.me/ti/g2/' . $hash;
                }
            }
        }

        // 画像URLをobs.line-scdn.net形式に変換
        $imageUrl = '';
        if (!empty($ocData['img_url'])) {
            $imageUrl = 'https://obs.line-scdn.net/' . $ocData['img_url'];
        }

        return response([
            'id' => $open_chat_id,
            'name' => $ocData['name'],
            'currentMember' => (int)$ocData['member'],
            'category' => (int)$ocData['category'],
            'categoryName' => $this->getCategoryName((int)$ocData['category']),
            'dates' => $dates,
            'members' => $members,
            'rankings' => $rankings,
            // 追加フィールド
            'description' => $ocData['description'] ?? '',
            'thumbnail' => $imageUrl,
            'emblem' => (int)($ocData['emblem'] ?? 0),
            'hourlyDiff' => (int)($ocData['hourly_diff_member'] ?? 0),
            'hourlyPercentage' => (float)($ocData['hourly_percent_increase'] ?? 0),
            'diff24h' => $diff24h,
            'percent24h' => $percent24h,
            'diff1w' => $diff1w,
            'percent1w' => $percent1w,
            'createdAt' => $ocData['created_at'] ? strtotime($ocData['created_at']) : null,
            'registeredAt' => $ocData['api_created_at'] ?? '',
            'joinMethodType' => (int)($ocData['join_method_type'] ?? 0),
            'url' => $lineUrl,
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
                oc.description AS `desc`,
                oc.member,
                oc.img_url,
                oc.local_img_url AS img,
                oc.emblem,
                oc.category,
                oc.join_method_type,
                oc.created_at,
                oc.api_created_at,
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

        // 画像URLを変換し、フィールド名を変更
        foreach ($data as &$item) {
            if (!empty($item['img_url'])) {
                $item['img'] = 'https://obs.line-scdn.net/' . $item['img_url'];
            }
            unset($item['img_url']);

            // Rename fields to match frontend interface
            $item['increasedMember'] = (int)$item['diff_member'];
            $item['percentageIncrease'] = (float)$item['percent_increase'];
            unset($item['diff_member'], $item['percent_increase']);

            // カテゴリ名を追加
            $item['categoryName'] = $this->getCategoryName((int)$item['category']);

            // 作成日と登録日を追加
            $item['createdAt'] = !empty($item['created_at']) ? strtotime($item['created_at']) : null;
            $item['registeredAt'] = $item['api_created_at'] ?? '';
            unset($item['created_at']);
            unset($item['api_created_at']);
        }
        unset($item);

        // 24時間と1週間のデータを一括取得
        if (!empty($ids)) {
            $pdo = \App\Models\SQLite\SQLiteStatistics::connect();
            $placeholders2 = implode(',', array_fill(0, count($ids), '?'));
            $statsSql = "
                SELECT
                    open_chat_id,
                    date,
                    member
                FROM statistics
                WHERE open_chat_id IN ($placeholders2)
                ORDER BY open_chat_id, date DESC
            ";
            $statsStmt = $pdo->prepare($statsSql);
            $statsStmt->execute($ids);
            $statsRows = $statsStmt->fetchAll(\PDO::FETCH_ASSOC);

            // IDごとにメンバー数の配列を作成
            $membersById = [];
            foreach ($statsRows as $row) {
                $membersById[$row['open_chat_id']][] = (int)$row['member'];
            }

            // 各結果に24時間と1週間の差分を追加
            foreach ($data as &$item) {
                $members = $membersById[$item['id']] ?? [];
                $maxIndex = count($members) - 1;

                $item['diff24h'] = 0;
                $item['percent24h'] = 0.0;
                $item['diff1w'] = 0;
                $item['percent1w'] = 0.0;

                if ($maxIndex >= 1 && $members[$maxIndex - 1] > 0) {
                    $item['diff24h'] = $members[$maxIndex] - $members[$maxIndex - 1];
                    $item['percent24h'] = floor(($item['diff24h'] / $members[$maxIndex - 1]) * 100 * 1000000) / 1000000;
                }

                if ($maxIndex >= 7 && $members[$maxIndex - 7] > 0) {
                    $item['diff1w'] = $members[$maxIndex] - $members[$maxIndex - 7];
                    $item['percent1w'] = floor(($item['diff1w'] / $members[$maxIndex - 7]) * 100 * 1000000) / 1000000;
                }
            }
            unset($item);
        }

        return response([
            'data' => $data,
        ]);
    }
}
