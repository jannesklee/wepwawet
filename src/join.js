/* eslint-disable */
;(function () {
	'use strict'

	var FAKE_CITIES = [
		{ name: 'Berlin', lat: 52.5200, lon: 13.4050 },
		{ name: 'Tokyo', lat: 35.6762, lon: 139.6503 },
		{ name: 'New York', lat: 40.7128, lon: -74.0060 },
		{ name: 'Sydney', lat: -33.8688, lon: 151.2093 },
		{ name: 'Cape Town', lat: -33.9249, lon: 18.4241 },
		{ name: 'Mumbai', lat: 19.0760, lon: 72.8777 },
		{ name: 'São Paulo', lat: -23.5505, lon: -46.6333 },
		{ name: 'Oslo', lat: 59.9139, lon: 10.7522 },
	]

	document.addEventListener('DOMContentLoaded', function () {
		var container = document.getElementById('locshare-join')
		if (!container) return

		if ('serviceWorker' in navigator && container.dataset.swUrl) {
			window.addEventListener('load', function () {
				navigator.serviceWorker.register(container.dataset.swUrl)
					.catch(function (err) { console.warn('SW registration failed:', err) })
			})
		}

		if (container.dataset.userId) {
			setupUserInviteFlow(container)
		} else {
			setupGuestFlow(container)
		}
	})

	// Logged-in Nextcloud user: joining a group is a single, immediate action.
	// No name to enter (already known), no duration to pick (visibility is a
	// separate, ongoing choice made later from the main app).
	function setupUserInviteFlow(container) {
		var ACCEPT_URL = container.dataset.acceptUrl
		var MAIN_URL = container.dataset.mainUrl || null

		var inviteView = document.getElementById('ls-user-invite-view')
		var joinedView = document.getElementById('ls-user-joined-view')
		var errorView = document.getElementById('ls-user-error-view')
		var joinBtn = document.getElementById('ls-join-group-btn')
		var retryBtn = document.getElementById('ls-user-retry-btn')

		function showView(view) {
			[inviteView, joinedView, errorView].forEach(function (v) {
				if (v) v.style.display = 'none'
			})
			if (view) view.style.display = 'flex'
		}

		function join() {
			if (joinBtn) { joinBtn.disabled = true; joinBtn.textContent = 'Joining…' }
			fetch(ACCEPT_URL, { method: 'POST' })
				.then(function (r) {
					if (!r.ok) throw new Error('accept failed')
					return r.json()
				})
				.then(function () {
					showView(joinedView)
					if (MAIN_URL) {
						setTimeout(function () { window.location.href = MAIN_URL }, 1200)
					}
				})
				.catch(function () {
					showView(errorView)
					if (joinBtn) { joinBtn.disabled = false; joinBtn.textContent = 'Join group' }
				})
		}

		if (joinBtn) joinBtn.addEventListener('click', join)
		if (retryBtn) retryBtn.addEventListener('click', join)
	}

	// Guest: time-boxed sharing under a chosen name, no Nextcloud account.
	function setupGuestFlow(container) {
		var GUEST_UPDATE_URL = container.dataset.guestUpdateUrl

		var formView = document.getElementById('ls-form-view')
		var sharingView = document.getElementById('ls-sharing-view')
		var stoppedView = document.getElementById('ls-stopped-view')
		var errorView = document.getElementById('ls-error-view')
		var nameInput = document.getElementById('ls-name-input')
		var startBtn = document.getElementById('ls-start-btn')
		var fakeBtn = document.getElementById('ls-fake-btn')
		var durationBtns = Array.prototype.slice.call(document.querySelectorAll('.ls-duration-btn'))
		var statusText = document.getElementById('ls-status-text')
		var expiresText = document.getElementById('ls-expires-text')
		var stopBtn = document.getElementById('ls-stop-btn')
		var restartBtn = document.getElementById('ls-restart-btn')
		var retryBtn = document.getElementById('ls-retry-btn')
		var errorText = document.getElementById('ls-error-text')

		var selectedMinutes = 60
		var watchId = null
		var fakeMode = false
		var expiresAt = null
		var countdownTimer = null
		var fakeCity = null
		var fakeLat, fakeLon
		var currentName = null

		var style = getComputedStyle(document.documentElement)
		var primaryColor = style.getPropertyValue('--color-primary').trim() || '#0082c9'
		var primaryText = style.getPropertyValue('--color-primary-text').trim() || '#fff'
		var defaultBg = style.getPropertyValue('--color-main-background').trim() || '#fff'
		var defaultText = style.getPropertyValue('--color-main-text').trim() || '#222'

		durationBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				selectedMinutes = parseInt(btn.dataset.minutes, 10)
				updateDurationButtons()
			})
		})
		updateDurationButtons()

		function updateDurationButtons() {
			durationBtns.forEach(function (btn) {
				var active = parseInt(btn.dataset.minutes, 10) === selectedMinutes
				btn.style.backgroundColor = active ? primaryColor : defaultBg
				btn.style.color = active ? primaryText : defaultText
				btn.style.borderColor = active ? primaryColor : ''
			})
		}

		function showView(view) {
			[formView, sharingView, stoppedView, errorView].forEach(function (v) {
				if (v) v.style.display = 'none'
			})
			if (view) view.style.display = 'flex'
		}

		function sendPosition(guestUpdateUrl, name, lat, lon, alt, acc) {
			var params = new URLSearchParams({ name: name, lat: lat, lon: lon })
			if (alt !== undefined && alt !== null) params.append('alt', alt)
			if (acc !== undefined && acc !== null) params.append('acc', acc)
			if (selectedMinutes > 0) params.append('duration', selectedMinutes)
			params.append('timestamp', Math.floor(Date.now() / 1000))

			fetch(guestUpdateUrl + '?' + params.toString(), { method: 'POST' })
				.then(function (r) {
					if (r.ok && statusText) {
						statusText.textContent = fakeCity
							? 'Sending fake position near ' + fakeCity.name + '…'
							: 'Sending position…'
					}
				})
				.catch(function () {
					if (statusText) statusText.textContent = 'Failed to send. Retrying…'
				})
		}

		function updateExpiresText() {
			if (!expiresAt || !expiresText) return
			var diff = expiresAt - Math.floor(Date.now() / 1000)
			if (diff <= 0) {
				stopSharing(false)
				showView(stoppedView)
				return
			}
			var h = Math.floor(diff / 3600)
			var m = Math.floor((diff % 3600) / 60)
			expiresText.textContent = h > 0
				? 'Stops in ' + h + 'h ' + m + 'min'
				: 'Stops in ' + (m + 1) + ' min'
		}

		function stopSharing(showStopped) {
			if (watchId !== null) {
				fakeMode ? clearInterval(watchId) : navigator.geolocation.clearWatch(watchId)
				watchId = null
			}
			fakeMode = false
			fakeCity = null
			if (countdownTimer !== null) { clearInterval(countdownTimer); countdownTimer = null }
			if (currentName) {
				var params = new URLSearchParams({ name: currentName, stop: '1' })
				fetch(GUEST_UPDATE_URL + '?' + params.toString(), { method: 'POST' })
					.catch(function () { /* best-effort */ })
				currentName = null
			}
			if (showStopped) showView(stoppedView)
		}

		function startSharingWithUrl(guestUpdateUrl, name, useFake) {
			currentName = name
			expiresAt = selectedMinutes > 0
				? Math.floor(Date.now() / 1000) + selectedMinutes * 60
				: null

			showView(sharingView)
			if (statusText) statusText.textContent = useFake ? 'Starting fake location…' : 'Waiting for GPS fix…'
			if (!expiresAt && expiresText) expiresText.textContent = 'Sharing until you stop'

			if (useFake) {
				fakeCity = FAKE_CITIES[Math.floor(Math.random() * FAKE_CITIES.length)]
				fakeLat = fakeCity.lat + (Math.random() - 0.5) * 0.01
				fakeLon = fakeCity.lon + (Math.random() - 0.5) * 0.01
				fakeMode = true
				var sendFake = function () {
					fakeLat += (Math.random() - 0.5) * 0.001
					fakeLon += (Math.random() - 0.5) * 0.001
					sendPosition(guestUpdateUrl, name, fakeLat.toFixed(6), fakeLon.toFixed(6), 50, 10)
				}
				sendFake()
				watchId = setInterval(sendFake, 10000)
			} else {
				watchId = navigator.geolocation.watchPosition(
					function (pos) {
						sendPosition(guestUpdateUrl, name, pos.coords.latitude, pos.coords.longitude, pos.coords.altitude, pos.coords.accuracy)
					},
					function (err) {
						stopSharing(false)
						if (errorText) errorText.textContent = 'Could not get location: ' + err.message
						showView(errorView)
					},
					{ enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 }
				)
			}

			if (expiresAt) {
				updateExpiresText()
				countdownTimer = setInterval(updateExpiresText, 30000)
			}
		}

		function beginSharing(useFake) {
			var name = nameInput ? nameInput.value.trim() : ''
			if (!name) {
				if (nameInput) { nameInput.style.borderColor = 'var(--color-error, red)'; nameInput.focus() }
				return
			}
			if (nameInput) nameInput.style.borderColor = ''
			startSharingWithUrl(GUEST_UPDATE_URL, name, useFake)
		}

		if (startBtn) startBtn.addEventListener('click', function () { beginSharing(false) })
		if (fakeBtn) fakeBtn.addEventListener('click', function () { beginSharing(true) })
		if (stopBtn) stopBtn.addEventListener('click', function () { stopSharing(true) })
		if (restartBtn) restartBtn.addEventListener('click', function () { showView(formView) })
		if (retryBtn) retryBtn.addEventListener('click', function () { showView(formView) })
		if (nameInput) {
			nameInput.addEventListener('input', function () { nameInput.style.borderColor = '' })
			nameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') beginSharing(false) })
		}
	}
}())
