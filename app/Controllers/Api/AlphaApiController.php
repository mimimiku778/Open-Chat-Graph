<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Config\AppConfig;
use App\Models\ApiRepositories\OpenChatStatsRankingApiRepository;
use App\Models\ApiRepositories\OpenChatApiArgs;
use App\Models\Repositories\DB;
use App\Models\SQLite\SQLiteStatistics;
use App\Models\SQLite\SQLiteRankingPosition;
use Shadow\Kernel\Reception;
use Shadow\Kernel\Validator;
use Shared\Exceptions\BadRequestException;
use Shared\MimimalCmsConfig;

class AlphaApiController
{
    private OpenChatApiArgs $args;

    function __construct(OpenChatApiArgs $argsObj)
    {
        $this->args = $argsObj;
    }

    /**
     * カテゴリIDから名前を取得
     */
    private function getCategoryName(int $categoryId): string
    {
        $categories = AppConfig::OPEN_CHAT_CATEGORY[''];
        foreach ($categories as $name => $id) {
            if ($id === $categoryId) {
                return $name;
            }
        }
        return '';
    }

    /**
     * 検索API
     * GET /alpha-api/search?keyword=xxx&category=0&page=0&limit=20&sort=member&order=desc
     */
    function search(OpenChatStatsRankingApiRepository $repo)
    {
        $error = BadRequestException::class;
        Reception::$isJson = true;

        // バリデーション
        $this->args->page = Validator::num(Reception::input('page', 0), min: 0, e: $error);
        $this->args->limit = Validator::num(Reception::input('limit', 20), min: 1, max: 100, e: $error);
        $this->args->category = (int)Validator::str(
            (string)Reception::input('category', '0'),
            regex: AppConfig::OPEN_CHAT_CATEGORY[MimimalCmsConfig::$urlRoot],
            e: $error
        );
        $this->args->order = Validator::str(Reception::input('order', 'desc'), regex: ['asc', 'desc'], e: $error);
        $this->args->sort = Validator::str(
            Reception::input('sort', 'member'),
            regex: ['member', 'created_at', 'hourly_diff', 'diff_24h', 'diff_1w'],
            e: $error
        );

        $keyword = Validator::str(Reception::input('keyword', ''), emptyAble: true, maxLen: 1000, e: $error);
        if ($keyword) {
            $this->args->keyword = $keyword;
        }

        // ソート条件に応じて適切なリポジトリメソッドを呼ぶ
        // OpenChatStatsRankingApiRepositoryのメソッドに合わせてargsを調整
        switch ($this->args->sort) {
            case 'hourly_diff':
                // 1時間でソート
                $this->args->list = 'hourly';
                $this->args->sort = 'increase'; // diff_member順
                $baseData = $repo->findHourlyStatsRanking($this->args);
                break;

            case 'diff_24h':
                // 24時間でソート
                $this->args->list = 'daily';
                $this->args->sort = 'increase';
                $baseData = $repo->findDailyStatsRanking($this->args);
                break;

            case 'diff_1w':
                // 1週間でソート
                $this->args->list = 'weekly';
                $this->args->sort = 'increase';
                $baseData = $repo->findWeeklyStatsRanking($this->args);
                break;

            case 'created_at':
                // 作成日でソート
                $this->args->list = 'all';
                $this->args->sort = 'created_at';
                $baseData = $repo->findStatsAll($this->args);
                break;

            case 'member':
            default:
                // メンバー数でソート
                $this->args->list = 'all';
                $this->args->sort = 'member';
                $baseData = $repo->findStatsAll($this->args);
                break;
        }

        // OpenChatListDtoオブジェクトをIDリストに変換
        $ids = array_map(fn($item) => $item->id, $baseData);
        $totalCount = $baseData[0]->totalCount ?? 0;

        if (empty($ids)) {
            return response([
                'data' => [],
                'totalCount' => 0,
            ]);
        }

        // 不足しているデータを追加のSQLで取得
        $additionalData = $this->fetchAdditionalStats($ids);

        // レスポンスを整形
        $responseData = $this->formatResponse($baseData, $additionalData);

        return response([
            'data' => $responseData,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * 不足データを一括取得（LEFT JOINでnullを許容）
     */
    private function fetchAdditionalStats(array $ids): array
    {
        DB::connect();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // hourly, daily, weeklyのデータを一括取得
        // 登録日（api_created_at）と作成日（created_at）も取得
        $sql = "
            SELECT
                oc.id,
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
                oc.id IN ($placeholders)
            ORDER BY
                FIELD(oc.id, $placeholders)
        ";

        $stmt = DB::$pdo->prepare($sql);
        // パラメータを2回バインド（IN句とORDER BY FIELD用）
        $params = array_merge($ids, $ids);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // IDをキーにした連想配列に変換
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row;
        }

        return $result;
    }

    /**
     * レスポンスをフロントエンドインターフェイスに合わせて整形
     */
    private function formatResponse(array $baseData, array $additionalData): array
    {
        $result = [];

        foreach ($baseData as $item) {
            $id = $item->id;
            $additional = $additionalData[$id] ?? null;

            // フロントエンドのOpenChatインターフェイスに合わせる
            $result[] = [
                'id' => $item->id,
                'name' => $item->name,
                'desc' => $item->desc,
                'member' => $item->member,
                'img' => $item->img,
                'emblem' => $item->emblem,
                'category' => $item->category,
                'categoryName' => $this->getCategoryName($item->category),
                'join_method_type' => $item->joinMethodType,

                // 1時間の差分（number）
                'increasedMember' => $additional['hourly_diff'] !== null ? (int)$additional['hourly_diff'] : 0,
                'percentageIncrease' => $additional['hourly_percent'] !== null ? (float)$additional['hourly_percent'] : 0.0,

                // 24時間の差分（number）
                'diff24h' => $additional['daily_diff'] !== null ? (int)$additional['daily_diff'] : 0,
                'percent24h' => $additional['daily_percent'] !== null ? (float)$additional['daily_percent'] : 0.0,

                // 1週間の差分（number）
                'diff1w' => $additional['weekly_diff'] !== null ? (int)$additional['weekly_diff'] : 0,
                'percent1w' => $additional['weekly_percent'] !== null ? (float)$additional['weekly_percent'] : 0.0,

                // 作成日と登録日
                'createdAt' => !empty($additional['created_at']) ? strtotime($additional['created_at']) : null,
                'registeredAt' => $additional['api_created_at'] ?? '',
            ];
        }

        return $result;
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
                oc.local_img_url,
                oc.emblem,
                oc.category,
                oc.join_method_type,
                oc.created_at,
                oc.api_created_at
            FROM
                open_chat AS oc
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

        // 不足しているデータを追加のSQLで取得
        $additionalData = $this->fetchAdditionalStats($ids);

        // レスポンスを整形
        $result = [];
        foreach ($data as $item) {
            $id = $item['id'];
            $additional = $additionalData[$id] ?? null;

            // 画像URLを変換
            $imgUrl = '';
            if (!empty($item['img_url'])) {
                $imgUrl = 'https://obs.line-scdn.net/' . $item['img_url'];
            }

            $result[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'desc' => $item['desc'],
                'member' => (int)$item['member'],
                'img' => $imgUrl,
                'emblem' => (int)$item['emblem'],
                'category' => (int)$item['category'],
                'categoryName' => $this->getCategoryName((int)$item['category']),
                'join_method_type' => (int)$item['join_method_type'],

                // 1時間の差分（number）
                'increasedMember' => $additional['hourly_diff'] !== null ? (int)$additional['hourly_diff'] : 0,
                'percentageIncrease' => $additional['hourly_percent'] !== null ? (float)$additional['hourly_percent'] : 0.0,

                // 24時間の差分（number）
                'diff24h' => $additional['daily_diff'] !== null ? (int)$additional['daily_diff'] : 0,
                'percent24h' => $additional['daily_percent'] !== null ? (float)$additional['daily_percent'] : 0.0,

                // 1週間の差分（number）
                'diff1w' => $additional['weekly_diff'] !== null ? (int)$additional['weekly_diff'] : 0,
                'percent1w' => $additional['weekly_percent'] !== null ? (float)$additional['weekly_percent'] : 0.0,

                // 作成日と登録日
                'createdAt' => !empty($item['created_at']) ? strtotime($item['created_at']) : null,
                'registeredAt' => $item['api_created_at'] ?? '',
            ];
        }

        return response([
            'data' => $result,
        ]);
    }
}
