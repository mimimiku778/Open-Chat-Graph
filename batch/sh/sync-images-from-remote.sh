#!/bin/bash

# リモートサーバーから画像ディレクトリを同期するスクリプト
# 本番環境のpublic/oc-img* ディレクトリをrsyncで差分同期
# 進捗は1行で表示され、転送済みバイト数/速度/進捗率が更新される

# スクリプトのディレクトリを取得（batch/sh）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# プロジェクトルートを取得（batch/sh の2階層上）
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# 共通設定を読み込む
source "${SCRIPT_DIR}/lib/remote-config.sh"

# 必須変数のチェック
if ! validate_required_vars "REMOTE_SERVER" "REMOTE_USER" "REMOTE_PORT" "REMOTE_KEY"; then
  echo "Error: 必須変数が設定されていません。" >&2
  exit 1
fi

echo "========================================"
echo "リモートサーバーから画像を同期"
echo "========================================"
echo ""

# ========================================
# 画像ディレクトリの同期
# ========================================

echo "----------------------------------------"
echo "画像ディレクトリを同期中..."
echo "----------------------------------------"
echo ""

# リモートの画像ディレクトリパス
REMOTE_IMG_BASE="${CONFIG_VARS[REMOTE_IMG_BASE]}"

# ローカルの画像ディレクトリパス
LOCAL_IMG_BASE="${PROJECT_ROOT}/public"

# 同期する画像ディレクトリのリスト
IMG_DIRS=(
  "oc-img"
  "oc-img-th"
  "oc-img-tw"
)

# エラーカウンター
FAILED_DIRS=()

for DIR_NAME in "${IMG_DIRS[@]}"; do
  echo "同期中: ${DIR_NAME}"

  # SSH接続設定
  SSH_CMD="ssh -p ${CONFIG_VARS[REMOTE_PORT]} -i ${CONFIG_VARS[REMOTE_KEY]}"
  REMOTE_PATH="${CONFIG_VARS[REMOTE_USER]}@${CONFIG_VARS[REMOTE_SERVER]}:${REMOTE_IMG_BASE}/${DIR_NAME}/"
  LOCAL_PATH="${LOCAL_IMG_BASE}/${DIR_NAME}/"

  # 1. 転送が必要なファイル数を取得（dry-run）
  echo -n "転送ファイル数を確認中..."
  TOTAL_FILES=$(rsync -an --delete --out-format="%n" -e "${SSH_CMD}" "${REMOTE_PATH}" "${LOCAL_PATH}" 2>/dev/null | grep -v '^$' | wc -l)
  echo " ${TOTAL_FILES} ファイル"

  if [ "${TOTAL_FILES}" -eq 0 ]; then
    echo "✓ 同期済み（変更なし）"
    echo ""
    continue
  fi

  # 2. 実際に転送（進捗カウンター表示）
  rsync -a --delete -v \
    -e "${SSH_CMD}" \
    "${REMOTE_PATH}" "${LOCAL_PATH}" 2>&1 | \
    awk -v total="${TOTAL_FILES}" '
      BEGIN { count=0 }
      /^[^d].*\/$/ { next }
      /^[^d]/ && NF>0 && !/(sending|total|sent|received)/ {
        count++
        printf "\r転送中: %d / %d ファイル (%.1f%%)", count, total, (count/total)*100
        fflush()
      }
      END { print "" }
    '

  if [ ${PIPESTATUS[0]} -ne 0 ]; then
    echo "✗ ${DIR_NAME} の同期に失敗しました。" >&2
    FAILED_DIRS+=("${DIR_NAME}")
  else
    echo "✓ ${DIR_NAME} を同期しました。"
  fi

  echo ""
done

# ========================================
# 完了
# ========================================

echo "========================================"
if [ ${#FAILED_DIRS[@]} -eq 0 ]; then
  echo "✓ 全ての画像同期が完了しました！"
else
  echo "✗ 一部の画像同期に失敗しました"
  echo "失敗したディレクトリ: ${FAILED_DIRS[*]}"
fi
echo "========================================"
echo ""
echo "同期されたディレクトリ:"
for DIR_NAME in "${IMG_DIRS[@]}"; do
  if [ -d "${LOCAL_IMG_BASE}/${DIR_NAME}" ]; then
    FILE_COUNT=$(find "${LOCAL_IMG_BASE}/${DIR_NAME}" -type f | wc -l)
    echo "  - ${DIR_NAME}: ${FILE_COUNT} ファイル"
  fi
done

# 失敗があった場合は終了コード1
if [ ${#FAILED_DIRS[@]} -gt 0 ]; then
  exit 1
fi

exit 0
