<!DOCTYPE html>
<html lang="<?php echo t('ja') ?>">

<head prefix="og: http://ogp.me/ns#">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <?php echo $_meta ?>
    <link rel="icon" type="image/png" href="<?php echo fileUrl(\App\Config\AppConfig::SITE_ICON_FILE_PATH, urlRoot: '') ?>">

    <link rel="stylesheet" href="<?php echo fileUrl('js/alpha/index.css', urlRoot: '') ?>">
    <script defer="defer" src="<?php echo fileUrl('js/alpha/index.js', urlRoot: '') ?>"></script>
</head>

<body>
    <noscript>You need to enable JavaScript to run this app.</noscript>

    <!-- React マウントポイント -->
    <div id="alpha-root">
    </div>
</body>

</html>