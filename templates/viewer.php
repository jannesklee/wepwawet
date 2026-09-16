<?php
$appId = OCA\Wepwawet\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-viewer');
\OCP\Util::addStyle($appId, $appId . '-viewer');
?>
<div id="wepwawet-viewer"></div>
