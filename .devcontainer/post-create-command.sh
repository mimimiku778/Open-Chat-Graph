#!/bin/bash
set -e

curl -fsSL https://claude.ai/install.sh | bash
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc && source ~/.bashrc
echo "✅ Claude CLIのインストールが完了しました。"
echo "実行コマンド: claude --dangerously-skip-permissions"

cat << 'EOF' > /var/www/html/shared/secrets.php
<?php

if (
    isset($_SERVER['HTTP_HOST'])
    && str_contains($_SERVER["HTTP_X_FORWARDED_HOST"], 'github.dev')
) {
    $_SERVER['HTTP_HOST'] = $_SERVER["HTTP_X_FORWARDED_HOST"];
    $_SERVER['HTTPS'] = 'on';
}
EOF

echo "🚀 Codespaces環境のセットアップが完了しました！"
