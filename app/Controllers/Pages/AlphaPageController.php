<?php

declare(strict_types=1);

namespace App\Controllers\Pages;

class AlphaPageController
{
    function index()
    {
        $_meta = meta()
            ->setTitle('オプチャグラフα - 統計監視ツール')
            ->setDescription('オープンチャット管理者向け統計監視ツール。マイリスト、フォルダ管理、グラフ表示で統計を効率的に監視。')
            ->generateTags();

        // React アプリのマウントポイントを含むビューを返す
        return view('alpha_content', compact('_meta'));
    }
}
