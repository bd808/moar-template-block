<!DOCTYPE HTML>
<?php use Moar\Template\Block as T; ?>
<html>
<head>
<title><?php T::block('title-full'); T::emptyblock('title');?> - Tests<?php T::endblock('title-full')?></title>
<?php T::emptyblock('html-head'); ?>
</head>
<body>
<div id="content"><?php T::emptyblock('content'); ?></div>
<?php T::emptyblock('body-end'); ?>
</body>
</html>
