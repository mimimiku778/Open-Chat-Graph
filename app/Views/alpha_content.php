<!DOCTYPE html>
<html lang="<?php echo t('ja') ?>">

<head prefix="og: http://ogp.me/ns#">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <?php echo $_meta ?>
    <link rel="icon" type="image/png" href="<?php echo fileUrl(\App\Config\AppConfig::SITE_ICON_FILE_PATH, urlRoot: '') ?>">

    <link rel="stylesheet" href="<?php echo fileUrl('js/alpha/index.css', urlRoot: '') ?>">
    <script defer="defer" src="<?php echo fileUrl('js/alpha/index.js', urlRoot: '') ?>"></script>

    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
                'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue',
                sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        #alpha-root {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .placeholder {
            text-align: center;
            padding: 2rem;
        }

        .placeholder h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .placeholder p {
            color: #666;
            font-size: 1rem;
        }
    </style>
</head>

<body>
    <noscript>You need to enable JavaScript to run this app.</noscript>

    <!-- React マウントポイント -->
    <div id="alpha-root">
        <div class="placeholder">
            <h1>オプチャグラフα</h1>
            <p>統計監視ツール（開発中）</p>
            <p>バックエンドAPIは稼働しています。フロントエンド実装中...</p>
        </div>
    </div>
</body>

</html>
