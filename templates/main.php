<?php
$appId = OCA\Wepwawet\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-main');
\OCP\Util::addStyle($appId, $appId . '-main');
?>
<div id="wepwawet-app"></div>
