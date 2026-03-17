<?php
$appId = OCA\LocShare\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-main');
\OCP\Util::addStyle($appId, $appId . '-main');
?>
<div id="locshare-app"></div>
