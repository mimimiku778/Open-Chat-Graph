#!/bin/bash

# データベース初期構築スクリプト
# 使い方: ./setup/init-database.sh

set -e

SCHEMA_DIR="$(cd "$(dirname "$0")/schema/mysql" && pwd)"
MYSQL_USER="root"
MYSQL_PASSWORD="test_root_pass"

echo "================================"
echo "データベース初期構築"
echo "================================"

# docker composeでMySQLサービスが起動しているか確認
COMPOSE_FILES=""

# COMPOSE_FILE環境変数が設定されている場合はそれを使用（CI環境用）
if [ -n "${COMPOSE_FILE}" ]; then
    COMPOSE_FILES="-f ${COMPOSE_FILE}"
    echo "Using COMPOSE_FILE環境変数: ${COMPOSE_FILE}"
elif [ -f ".github/docker-compose.ci.yml" ] && docker compose -f .github/docker-compose.ci.yml ps mysql 2>/dev/null | grep -q "Up"; then
    COMPOSE_FILES="-f .github/docker-compose.ci.yml"
elif docker compose -f docker-compose.yml -f docker-compose.mock.yml ps mysql 2>/dev/null | grep -q "Up"; then
    COMPOSE_FILES="-f docker-compose.yml -f docker-compose.mock.yml"
elif docker compose ps mysql 2>/dev/null | grep -q "Up"; then
    COMPOSE_FILES=""
else
    echo "エラー: MySQLコンテナが起動していません"
    echo "先に環境を起動してください:"
    echo "  make up または make up-mock"
    exit 1
fi

echo "MySQL: docker compose ${COMPOSE_FILES} exec mysql"
echo "User: $MYSQL_USER"
echo ""

# MySQLコマンド構築（docker compose経由）
MYSQL_CMD="docker compose ${COMPOSE_FILES} exec -T mysql mysql -u${MYSQL_USER} -p${MYSQL_PASSWORD}"

# MySQLが完全に起動するまで待機（最大30秒）
echo "MySQLの準備を待機中..."
for i in {1..30}; do
    if docker compose ${COMPOSE_FILES} exec -T mysql mysql -u${MYSQL_USER} -p${MYSQL_PASSWORD} -e "SELECT 1" >/dev/null 2>&1; then
        echo "MySQLが準備完了しました"
        break
    fi
    if [ $i -eq 30 ]; then
        echo "エラー: MySQLの起動がタイムアウトしました"
        exit 1
    fi
    echo "  待機中... ($i/30)"
    sleep 1
done
echo ""

# スキーマファイルの順序（依存関係を考慮）
SCHEMA_FILES=(
    "ocgraph_ocreview_schema.sql"
    "ocgraph_ocreviewtw_schema.sql"
    "ocgraph_ocreviewth_schema.sql"
    "ocgraph_ranking_schema.sql"
    "ocgraph_rankingtw_schema.sql"
    "ocgraph_rankingth_schema.sql"
    "ocgraph_comment_schema.sql"
    "ocgraph_commenttw_schema.sql"
    "ocgraph_commentth_schema.sql"
    "ocgraph_userlog_schema.sql"
)

echo "スキーマファイルを適用中..."
echo ""

for schema_file in "${SCHEMA_FILES[@]}"; do
    schema_path="$SCHEMA_DIR/$schema_file"

    if [ ! -f "$schema_path" ]; then
        echo "⚠ スキップ: $schema_file (ファイルが見つかりません)"
        continue
    fi

    db_name=$(echo "$schema_file" | sed 's/_schema\.sql$//')
    echo "→ $db_name を作成中..."

    $MYSQL_CMD < "$schema_path"

    if [ $? -eq 0 ]; then
        echo "  ✓ 完了"
    else
        echo "  ✗ エラー"
        exit 1
    fi
done

echo ""
echo "================================"
echo "初期構築完了"
echo "================================"
echo ""
echo "作成されたデータベース:"
$MYSQL_CMD -e "SHOW DATABASES LIKE 'ocgraph_%';"
