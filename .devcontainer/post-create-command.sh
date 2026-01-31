#!/bin/bash
set -e

echo "🚀 Codespaces環境のセットアップを開始します..."

# リポジトリのルートディレクトリに移動
cd /workspace

# mkcertのインストール（SSL証明書生成用）
echo "📦 mkcertをインストールしています..."
wget -q https://github.com/FiloSottile/mkcert/releases/download/v1.4.4/mkcert-v1.4.4-linux-amd64
chmod +x mkcert-v1.4.4-linux-amd64
sudo mv mkcert-v1.4.4-linux-amd64 /usr/local/bin/mkcert

# ローカル認証局のインストール
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
