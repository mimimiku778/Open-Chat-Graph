#!/bin/bash
set -e

echo "🚀 Codespaces環境のセットアップを開始します..."

# ローカル認証局のインストール
echo "📦 SSL証明書をセットアップしています..."
mkcert -install

# 初期セットアップの実行
echo "🔧 初期セットアップを実行しています..."
make init-y

echo ""
echo "✅ セットアップが完了しました！"
echo ""
echo "📝 次のステップ:"
echo "  1. Mock環境を起動: make up-mock"
echo "  2. ブラウザでアクセス: https://localhost:8443"
echo ""
echo "詳細はREADME.mdを参照してください。"
