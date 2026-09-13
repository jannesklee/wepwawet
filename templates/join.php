<?php
$appId = OCA\Sopdet\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-join');
\OCP\Util::addStyle($appId, $appId . '-join');

$guestUpdateUrl = $_['guest_update_url'];
$guestPositionsUrl = $_['guest_positions_url'];
$acceptUrl = $_['accept_url'];
$ownerDisplayName = $_['owner_display_name'];
$groupName = $_['group_name'];
$userId = $_['user_id'] ?? null;
$userDisplayName = $_['user_display_name'] ?? null;
$mainUrl = $_['main_url'] ?? null;
$debug = $_['debug'] ?? false;
$swUrl = $_['sw_url'] ?? '';
?>
<div id="sopdet-join"
	data-sw-url="<?= htmlspecialchars($swUrl, ENT_QUOTES) ?>"
	data-guest-update-url="<?= htmlspecialchars($guestUpdateUrl, ENT_QUOTES) ?>"
	data-guest-positions-url="<?= htmlspecialchars($guestPositionsUrl, ENT_QUOTES) ?>"
	data-accept-url="<?= htmlspecialchars($acceptUrl, ENT_QUOTES) ?>"
	<?php if ($userId !== null): ?>
	data-user-id="<?= htmlspecialchars($userId, ENT_QUOTES) ?>"
	data-user-display-name="<?= htmlspecialchars($userDisplayName ?? $userId, ENT_QUOTES) ?>"
	<?php endif; ?>
	<?php if ($mainUrl !== null): ?>
	data-main-url="<?= htmlspecialchars($mainUrl, ENT_QUOTES) ?>"
	<?php endif; ?>>

	<?php if ($userId !== null): ?>
	<!-- Logged-in Nextcloud user: joining a persistent group is a one-step
	     action, distinct from the guest's time-boxed sharing flow below. -->
	<div class="ls-join-card" id="ls-user-invite-view">
		<div class="ls-join-icon">📍</div>
		<h1><?= p($ownerDisplayName) ?> invited you to join "<?= p($groupName) ?>"</h1>
		<p class="ls-join-desc">You'll see everyone in this group on your map. You choose separately, any time, whether to share your own location with them.</p>
		<button id="ls-join-group-btn" type="button">Join group</button>
	</div>

	<div class="ls-join-card" id="ls-user-joined-view" style="display:none">
		<div class="ls-join-icon">✅</div>
		<h1>You joined "<?= p($groupName) ?>"</h1>
		<?php if ($mainUrl !== null): ?>
		<a href="<?= htmlspecialchars($mainUrl, ENT_QUOTES) ?>" class="ls-map-link">Open Sopdet →</a>
		<?php endif; ?>
	</div>

	<div class="ls-join-card" id="ls-user-error-view" style="display:none">
		<div class="ls-join-icon">⚠️</div>
		<h1>Something went wrong</h1>
		<p class="ls-join-sub">Couldn't join the group. Check your connection and try again.</p>
		<button id="ls-user-retry-btn" type="button">Try again</button>
	</div>

	<?php else: ?>
	<!-- Guest: time-boxed sharing, no Nextcloud account -->
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
		<div id="ls-guest-map"></div>
		<ul id="ls-guest-member-list" class="ls-guest-member-list"></ul>
		<button id="ls-stop-btn" type="button" class="ls-btn-secondary">Stop sharing</button>
	</div>

	<div class="ls-join-card" id="ls-stopped-view" style="display:none">
		<div class="ls-join-icon">✅</div>
		<h1>Sharing stopped</h1>
		<p class="ls-join-desc">Your location is no longer being shared.</p>
		<button id="ls-restart-btn" type="button">Share again</button>
	</div>

	<div class="ls-join-card" id="ls-error-view" style="display:none">
		<div class="ls-join-icon">⚠️</div>
		<h1>Something went wrong</h1>
		<p class="ls-join-sub" id="ls-error-text"></p>
		<button id="ls-retry-btn" type="button">Try again</button>
	</div>
	<?php endif; ?>
</div>

<style>
#sopdet-join {
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
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-container-large, 16px);
	padding: 32px 40px;
	box-sizing: border-box;
	box-shadow: 0 1px 10px var(--color-box-shadow, rgba(0, 0, 0, .1));
}

.ls-join-icon { font-size: 56px; line-height: 1; }

.ls-join-card h1 {
	font-size: 22px;
	font-weight: 700;
	color: var(--color-main-text, #222);
	margin: 0;
	line-height: 1.4;
}

.ls-join-sub { color: var(--color-text-maxcontrast, #767676); margin: 0; font-size: 14px; font-weight: 600; }
.ls-join-desc { color: var(--color-text-maxcontrast, #767676); margin: 0; font-size: 14px; line-height: 1.5; }
.ls-join-note { color: var(--color-text-maxcontrast, #767676); font-size: 13px; margin: 0; line-height: 1.5; }

#ls-name-input {
	width: 100%;
	padding: 14px 16px;
	font-size: 15px;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-element, 8px);
	outline: none;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	box-sizing: border-box;
	transition: border-color 0.2s;
}
#ls-name-input:focus { border-color: var(--color-primary, #0082c9); }

.ls-duration-group { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.ls-duration-label { margin: 0; font-size: 13px; color: var(--color-text-maxcontrast, #767676); text-align: left; }
.ls-duration-options { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }

.ls-duration-btn {
	padding: 10px 4px;
	font-size: 13px;
	font-weight: 600;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	cursor: pointer;
	-webkit-tap-highlight-color: transparent;
}

#ls-start-btn, #ls-stop-btn, #ls-restart-btn, #ls-retry-btn {
	width: 100%;
	padding: 13px 16px;
	font-size: 15px;
	font-weight: 600;
	border: none;
	border-radius: var(--border-radius-element, 8px);
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
	font-size: 14px;
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

#ls-guest-map {
	width: 100%;
	height: 220px;
	border-radius: var(--border-radius-element, 8px);
	overflow: hidden;
	background: var(--color-background-dark, #f0f0f0);
}

#ls-guest-map .ls-guest-marker {
	width: 30px;
	height: 30px;
	border-radius: 50%;
	border: 2px solid #fff;
	box-shadow: 0 1px 4px rgba(0, 0, 0, .4);
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-size: 13px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

#ls-guest-map .ls-guest-marker--me {
	border-color: var(--color-primary, #0082c9);
	border-width: 3px;
}

#ls-guest-map .ls-guest-marker--focused {
	border-color: #f59e0b;
	border-width: 3px;
	width: 36px;
	height: 36px;
}

#ls-guest-map .ls-guest-marker img { width: 100%; height: 100%; object-fit: cover; }

.ls-guest-member-list {
	list-style: none;
	margin: 10px 0 0;
	padding: 0;
	text-align: left;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ls-guest-member-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px;
	margin: 0 -6px;
	border-radius: var(--border-radius-element, 8px);
	border: none;
	background: none;
	font: inherit;
	width: 100%;
	text-align: left;
	cursor: default;
}

.ls-guest-member-item--clickable {
	cursor: pointer;
}

.ls-guest-member-item--clickable:hover {
	background: var(--color-background-hover, rgba(0, 0, 0, .05));
}

.ls-guest-member-item--selected {
	background: var(--color-primary-light, #e0eefb);
}

.ls-guest-member-avatar {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	flex-shrink: 0;
	object-fit: cover;
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-size: 13px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
}

.ls-guest-member-info {
	flex: 1;
	min-width: 0;
}

.ls-guest-member-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text, #222);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.ls-guest-member-seen {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #767676);
}

.ls-guest-member-status {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
	background: #c8c8c8;
}

.ls-guest-member-status--active { background: #46ba61; }
.ls-guest-member-status--stale { background: #c8c8c8; }

@keyframes ls-pulse {
	0%   { transform: scale(1); opacity: 0.4; }
	100% { transform: scale(4.5); opacity: 0; }
}

@media (max-width: 480px) {
	#sopdet-join { padding: 16px; }
	.ls-join-card { padding: 28px 20px; }
}
</style>
