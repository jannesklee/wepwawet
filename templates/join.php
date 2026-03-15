<?php
$appId = OCA\LocShare\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-join');

$guestUpdateUrl = $_['guest_update_url'];
$acceptUrl = $_['accept_url'];
$ownerDisplayName = $_['owner_display_name'];
$groupName = $_['group_name'];
$userId = $_['user_id'] ?? null;
$userDisplayName = $_['user_display_name'] ?? null;
$mainUrl = $_['main_url'] ?? null;
$debug = $_['debug'] ?? false;
?>
<div id="locshare-join"
	data-guest-update-url="<?= htmlspecialchars($guestUpdateUrl, ENT_QUOTES) ?>"
	data-accept-url="<?= htmlspecialchars($acceptUrl, ENT_QUOTES) ?>"
	<?php if ($userId !== null): ?>
	data-user-id="<?= htmlspecialchars($userId, ENT_QUOTES) ?>"
	data-user-display-name="<?= htmlspecialchars($userDisplayName ?? $userId, ENT_QUOTES) ?>"
	<?php endif; ?>
	<?php if ($mainUrl !== null): ?>
	data-main-url="<?= htmlspecialchars($mainUrl, ENT_QUOTES) ?>"
	<?php endif; ?>>

	<div class="ls-join-card" id="ls-form-view">
		<div class="ls-join-icon">📍</div>
		<h1><?= p($ownerDisplayName) ?> wants to share locations with you</h1>
		<p class="ls-join-sub"><?= p($groupName) ?></p>

		<input type="text"
			id="ls-name-input"
			placeholder="Your name"
			maxlength="48"
			autocomplete="name"
			autocorrect="off"
			autocapitalize="words" />

		<div class="ls-duration-group">
			<p class="ls-duration-label">Share for how long?</p>
			<div class="ls-duration-options">
				<button type="button" class="ls-duration-btn" data-minutes="15">15 min</button>
				<button type="button" class="ls-duration-btn" data-minutes="60">1 hour</button>
				<button type="button" class="ls-duration-btn" data-minutes="240">4 hours</button>
				<button type="button" class="ls-duration-btn" data-minutes="0">Until I stop</button>
			</div>
		</div>

		<button id="ls-start-btn" type="button">Start sharing my location</button>
		<?php if ($debug): ?>
		<button id="ls-fake-btn" type="button" class="ls-btn-secondary">Use fake location (testing)</button>
		<?php endif; ?>
		<p class="ls-join-note">Your browser will ask for location permission.</p>
	</div>

	<div class="ls-join-card" id="ls-sharing-view" style="display:none">
		<div class="ls-pulse-container">
			<div class="ls-pulse-dot"></div>
		</div>
		<h1>Sharing your location</h1>
		<p class="ls-join-sub" id="ls-status-text">Waiting for GPS fix…</p>
		<p class="ls-join-note" id="ls-expires-text"></p>
		<button id="ls-stop-btn" type="button" class="ls-btn-secondary">Stop sharing</button>
		<?php if ($mainUrl !== null): ?>
		<a href="<?= htmlspecialchars($mainUrl, ENT_QUOTES) ?>" class="ls-map-link">See everyone on the map →</a>
		<?php endif; ?>
	</div>

	<div class="ls-join-card" id="ls-stopped-view" style="display:none">
		<div class="ls-join-icon">✅</div>
		<h1>Sharing stopped</h1>
		<p class="ls-join-desc">Your location is no longer being shared.</p>
		<button id="ls-restart-btn" type="button">Share again</button>
		<?php if ($mainUrl !== null): ?>
		<a href="<?= htmlspecialchars($mainUrl, ENT_QUOTES) ?>" class="ls-map-link">See everyone on the map →</a>
		<?php endif; ?>
	</div>

	<div class="ls-join-card" id="ls-error-view" style="display:none">
		<div class="ls-join-icon">⚠️</div>
		<h1>Something went wrong</h1>
		<p class="ls-join-sub" id="ls-error-text"></p>
		<button id="ls-retry-btn" type="button">Try again</button>
	</div>
</div>

<style>
#locshare-join {
	min-height: 60vh;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 32px 24px;
	box-sizing: border-box;
}

.ls-join-card {
	width: 100%;
	max-width: 420px;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	text-align: center;
}

.ls-join-icon { font-size: 56px; line-height: 1; }

.ls-join-card h1 {
	font-size: 1.35rem;
	font-weight: 700;
	color: var(--color-main-text, #222);
	margin: 0;
	line-height: 1.4;
}

.ls-join-sub { color: var(--color-text-maxcontrast, #666); margin: 0; font-size: 0.95rem; font-weight: 600; }
.ls-join-desc { color: var(--color-text-maxcontrast, #666); margin: 0; font-size: 0.9rem; line-height: 1.5; }
.ls-join-note { color: var(--color-text-maxcontrast, #999); font-size: 0.85rem; margin: 0; line-height: 1.5; }

#ls-name-input {
	width: 100%;
	padding: 14px 16px;
	font-size: 1rem;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	outline: none;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	box-sizing: border-box;
	transition: border-color 0.2s;
}
#ls-name-input:focus { border-color: var(--color-primary, #0082c9); }

.ls-duration-group { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.ls-duration-label { margin: 0; font-size: 0.85rem; color: var(--color-text-maxcontrast, #666); text-align: left; }
.ls-duration-options { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }

.ls-duration-btn {
	padding: 10px 4px;
	font-size: 0.85rem;
	font-weight: 600;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}

#ls-start-btn, #ls-stop-btn, #ls-restart-btn, #ls-retry-btn {
	width: 100%;
	padding: 16px;
	font-size: 1rem;
	font-weight: 600;
	border: none;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}

#ls-stop-btn, #ls-fake-btn {
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-main-text, #222);
	border: none;
}

.ls-map-link {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-primary, #0082c9);
	text-decoration: none;
}
.ls-map-link:hover { text-decoration: underline; }

.ls-pulse-container {
	position: relative;
	width: 80px;
	height: 80px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.ls-pulse-dot {
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	position: relative;
}

.ls-pulse-dot::before, .ls-pulse-dot::after {
	content: '';
	position: absolute;
	inset: 0;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	animation: ls-pulse 2s ease-out infinite;
	opacity: 0.4;
}
.ls-pulse-dot::after { animation-delay: 1s; }

@keyframes ls-pulse {
	0%   { transform: scale(1); opacity: 0.4; }
	100% { transform: scale(4.5); opacity: 0; }
}
</style>
