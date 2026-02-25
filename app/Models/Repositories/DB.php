<?php

declare(strict_types=1);

namespace App\Models\Repositories;

use App\Config\AppConfig;
use Shadow\DBInterface;
use Shared\MimimalCmsConfig;

class DB extends \Shadow\DB implements DBInterface
{
    public static ?\PDO $pdo = null;

    /**
     * 接続断時の最大リトライ回数。
     * Webリクエスト用途では低めの値を設定可能。バッチ処理では高い値を推奨。
     */
    public static int $maxRetries = 5;

    /**
     * 再接続バックオフの上限秒数（1回あたりの最大スリープ時間）。
     * Webリクエスト用途では低めの値を設定可能。バッチ処理では高い値を推奨。
     */
    public static int $maxBackoffSeconds = 8;

    public static function connect(?array $config = null): \PDO
    {
        return parent::connect($config ?? [
            'dbName' => AppConfig::$dbName[MimimalCmsConfig::$urlRoot]
        ]);
    }

    public static function execute(string $query, ?array $params = null): \PDOStatement
    {
        for ($attempt = 0; $attempt < static::$maxRetries; $attempt++) {
            try {
                return parent::execute($query, $params);
            } catch (\PDOException $e) {
                if ($attempt < static::$maxRetries - 1 && static::isConnectionLost($e)) {
                    try {
                        static::reconnect($attempt);
                    } catch (\PDOException $reconnectException) {
                        // 再接続自体が失敗しても、ループを継続して最大リトライ回数まで試行する
                    }
                    continue;
                }

                throw $e;
            }
        }

        throw new \LogicException('Unreachable');
    }

    /**
     * MySQL接続断エラーかどうかを判定する
     *
     * PDOExceptionのerrorInfoプロパティがnullの場合や、
     * ドライバーエラーコードが文字列の場合にも対応する。
     */
    private static function isConnectionLost(\PDOException $e): bool
    {
        // errorInfo[1] にドライバー固有のエラーコードがある場合（型を問わず比較）
        $driverCode = $e->errorInfo[1] ?? null;
        if ($driverCode !== null) {
            $driverCode = (int) $driverCode;
            // 2006: MySQL server has gone away
            // 2013: Lost connection to MySQL server during query
            if ($driverCode === 2006 || $driverCode === 2013) {
                return true;
            }
        }

        // errorInfoが未設定の場合のフォールバック: エラーメッセージで判定
        $message = $e->getMessage();
        return str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection');
    }

    /**
     * MySQL接続をリセットして再接続する
     * エクスポネンシャルバックオフ: sleep(min(1 << $attempt, $maxBackoffSeconds))
     */
    private static function reconnect(int $attempt = 0): void
    {
        static::$pdo = null;
        sleep(min(1 << $attempt, static::$maxBackoffSeconds));
        static::connect();
    }
}
