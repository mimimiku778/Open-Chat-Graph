#!/bin/bash
set -e

curl -fsSL https://claude.ai/install.sh | bash
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc && source ~/.bashrc
echo "✅ Claude CLIのインストールが完了しました。"
echo "実行コマンド: claude --dangerously-skip-permissions"

echo "🚀 Codespaces環境のセットアップが完了しました！"

