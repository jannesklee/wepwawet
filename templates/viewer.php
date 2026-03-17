<?php
$appId = OCA\LocShare\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-viewer');
\OCP\Util::addStyle($appId, $appId . '-main');
?>
<div id="locshare-viewer"></div>
