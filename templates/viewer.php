<?php
$appId = OCA\Sopdet\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-viewer');
\OCP\Util::addStyle($appId, $appId . '-viewer');
?>
<div id="sopdet-viewer"></div>
