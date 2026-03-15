<template>
	<div class="ls-app">
		<!-- Top bar -->
		<div class="ls-topbar">
			<span class="ls-title">LocShare</span>
			<button class="ls-share-btn"
				:class="{ active: sharing }"
				@click="toggleSharing">
				{{ sharing ? t('locshare', 'Stop sharing') : t('locshare', 'Share my location') }}
			</button>
		</div>

		<!-- Map -->
		<div class="ls-map">
			<div id="ls-map-container" />
		</div>

		<!-- Member strip -->
		<div class="ls-members">
			<div v-if="membersWithPosition.length === 0" class="ls-no-members">
				{{ t('locshare', 'No one is sharing yet. Tap the button above, then send the invite link to family.') }}
			</div>
			<div v-for="m in members"
				:key="m.userId"
				class="ls-member"
				:class="{ inactive: !m.hasPosition }"
				@click="m.hasPosition && focusMember(m)">
				<img v-if="m.avatarUrl" :src="m.avatarUrl" class="ls-avatar" :alt="m.displayName">
				<div v-else class="ls-avatar ls-avatar-initial">
					{{ m.displayName.charAt(0).toUpperCase() }}
				</div>
				<div class="ls-member-info">
					<span class="ls-member-name">{{ m.displayName }}{{ m.isMe ? ' (' + t('locshare', 'you') + ')' : '' }}</span>
					<span class="ls-member-time">{{ m.hasPosition ? formatAge(m.updatedAt) : t('locshare', 'not sharing') }}</span>
				</div>
			</div>
		</div>

		<!-- Sharing status toast -->
		<div v-if="sharingStatus" class="ls-status-toast">
			{{ sharingStatus }}
		</div>

		<!-- Invite bar -->
		<div v-if="state.inviteUrl" class="ls-invite-bar">
			<span class="ls-invite-label">{{ t('locshare', 'Invite link:') }}</span>
			<span class="ls-invite-url">{{ state.inviteUrl }}</span>
			<button class="ls-copy-btn" @click="copyInvite">
				{{ inviteCopied ? '✓' : t('locshare', 'Copy') }}
			</button>
		</div>
	</div>
</template>

<script>
import maplibregl from 'maplibre-gl'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

export default {
	name: 'LocShareApp',

	data() {
		const state = loadState('locshare', 'locshare-state', {})
		return {
			state,
			members: [],
			sharing: false,
			sharingStatus: '',
			watchId: null,
			pollTimer: null,
			markers: {},
			map: null,
			inviteCopied: false,
		}
	},

	computed: {
		membersWithPosition() {
			return this.members.filter(m => m.hasPosition)
		},
	},

	async mounted() {
		this.initMap()
		await this.loadMembers()
		this.pollTimer = setInterval(() => this.loadMembers(), 15000)
	},

	beforeUnmount() {
		this.stopSharing()
		if (this.pollTimer) clearInterval(this.pollTimer)
		if (this.map) this.map.remove()
	},

	methods: {
		initMap() {
			try {
				this.map = new maplibregl.Map({
					container: 'ls-map-container',
					style: {
						version: 8,
						sources: {
							osm: {
								type: 'raster',
								tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
								tileSize: 256,
								attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
							},
						},
						layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
					},
					center: [10, 50],
					zoom: 4,
				})
				this.map.addControl(new maplibregl.NavigationControl(), 'top-right')
				this.map.on('load', () => {
					this.map.resize()
					this.updateMarkers()
				})
			} catch (e) {
				console.error('[LocShare] MapLibre init failed:', e)
			}
		},

		async loadMembers() {
			if (!this.state.positionsUrl) return
			try {
				const { data } = await axios.get(this.state.positionsUrl)
				this.members = data
				if (this.map?.loaded()) this.updateMarkers()
			} catch (e) {
				console.error('[LocShare] Failed to load members', e)
			}
		},

		updateMarkers() {
			const seen = new Set()

			for (const m of this.membersWithPosition) {
				seen.add(m.userId)
				const lngLat = [m.lon, m.lat]

				if (this.markers[m.userId]) {
					this.markers[m.userId].setLngLat(lngLat)
				} else {
					const el = this.createMarkerEl(m)
					this.markers[m.userId] = new maplibregl.Marker({ element: el, anchor: 'center' })
						.setLngLat(lngLat)
						.setPopup(
							new maplibregl.Popup({ offset: 40 })
								.setHTML('<strong>' + this.escHtml(m.displayName) + '</strong><br>' + this.formatAge(m.updatedAt))
						)
						.addTo(this.map)
				}
			}

			for (const uid of Object.keys(this.markers)) {
				if (!seen.has(uid)) {
					this.markers[uid].remove()
					delete this.markers[uid]
				}
			}

			if (this.membersWithPosition.length > 0) {
				const bounds = new maplibregl.LngLatBounds()
				this.membersWithPosition.forEach(m => bounds.extend([m.lon, m.lat]))
				this.map.fitBounds(bounds, { padding: 80, maxZoom: 14, duration: 500 })
			}
		},

		createMarkerEl(member) {
			const el = document.createElement('div')
			el.className = 'ls-marker'
			el.title = member.displayName
			if (member.avatarUrl) {
				const img = document.createElement('img')
				img.src = member.avatarUrl
				img.alt = member.displayName
				img.onerror = () => {
					img.remove()
					el.textContent = member.displayName.charAt(0).toUpperCase()
				}
				el.appendChild(img)
			} else {
				el.textContent = member.displayName.charAt(0).toUpperCase()
			}
			return el
		},

		focusMember(member) {
			if (!this.map) return
			this.map.flyTo({ center: [member.lon, member.lat], zoom: 14 })
			this.markers[member.userId]?.togglePopup()
		},

		async toggleSharing() {
			if (this.sharing) {
				this.stopSharing()
				return
			}
			if (!navigator.geolocation) {
				this.sharingStatus = t('locshare', 'Your browser does not support location sharing.')
				return
			}
			this.sharing = true
			this.sharingStatus = t('locshare', 'Waiting for GPS fix…')

			this.watchId = navigator.geolocation.watchPosition(
				(pos) => this.onPosition(pos),
				(err) => {
					this.sharingStatus = t('locshare', 'Location error: ') + err.message
					this.stopSharing()
				},
				{ enableHighAccuracy: true, timeout: 20000, maximumAge: 5000 },
			)
		},

		async onPosition(pos) {
			this.sharingStatus = t('locshare', 'Sharing your location…')
			const params = new URLSearchParams()
			params.set('lat', pos.coords.latitude)
			params.set('lon', pos.coords.longitude)
			if (pos.coords.accuracy != null) params.set('acc', pos.coords.accuracy)
			if (pos.coords.altitude != null) params.set('alt', pos.coords.altitude)
			if (pos.coords.speed != null && pos.coords.speed >= 0) params.set('speed', pos.coords.speed)
			if (pos.coords.heading != null) params.set('bearing', pos.coords.heading)

			try {
				await axios.post(this.state.updateUrl + '?' + params.toString())
				await this.loadMembers()
			} catch (e) {
				console.error('[LocShare] Failed to update position', e)
			}
		},

		stopSharing() {
			if (this.watchId !== null) {
				navigator.geolocation.clearWatch(this.watchId)
				this.watchId = null
			}
			this.sharing = false
			this.sharingStatus = ''
		},

		formatAge(timestamp) {
			if (!timestamp) return ''
			const diff = Math.floor(Date.now() / 1000) - timestamp
			if (diff < 60) return t('locshare', 'just now')
			if (diff < 3600) return t('locshare', '{m} min ago', { m: Math.floor(diff / 60) })
			if (diff < 86400) return t('locshare', '{h}h ago', { h: Math.floor(diff / 3600) })
			return t('locshare', '{d}d ago', { d: Math.floor(diff / 86400) })
		},

		escHtml(str) {
			return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
		},

		async copyInvite() {
			try {
				await navigator.clipboard.writeText(this.state.inviteUrl)
				this.inviteCopied = true
				setTimeout(() => { this.inviteCopied = false }, 3000)
			} catch (e) {
				console.error(e)
			}
		},
	},
}
</script>

<style>
@import 'maplibre-gl/dist/maplibre-gl.css';

#ls-map-container {
	position: absolute;
	inset: 0;
}

.ls-marker {
	width: 44px;
	height: 44px;
	border-radius: 50%;
	border: 3px solid var(--color-primary, #0082c9);
	overflow: hidden;
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-weight: bold;
	font-size: 1.2rem;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.ls-marker img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
</style>

<style scoped>
.ls-app {
	display: flex;
	flex-direction: column;
	position: fixed;
	top: var(--header-height, 50px);
	left: 0;
	right: 0;
	bottom: 0;
}

.ls-topbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	z-index: 10;
	flex-shrink: 0;
}

.ls-title {
	font-size: 1.1rem;
	font-weight: 600;
}

.ls-share-btn {
	padding: 8px 20px;
	border: none;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	cursor: pointer;
	font-weight: 600;
	font-size: 0.9rem;
	transition: background 0.15s;
}

.ls-share-btn.active {
	background: var(--color-error, #e9322d);
}

.ls-map {
	flex: 1;
	min-height: 0;
	position: relative;
}

.ls-members {
	display: flex;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	overflow-x: auto;
	flex-shrink: 0;
	min-height: 64px;
	align-items: center;
}

.ls-no-members {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.ls-member {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	cursor: pointer;
	flex-shrink: 0;
	min-width: 120px;
	transition: background 0.1s;
}

.ls-member.inactive {
	opacity: 0.5;
	cursor: default;
}

.ls-member:not(.inactive):hover {
	background: var(--color-background-dark);
}

.ls-avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	object-fit: cover;
	border: 2px solid var(--color-primary, #0082c9);
	flex-shrink: 0;
}

.ls-avatar-initial {
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-weight: bold;
	font-size: 1rem;
	display: flex;
	align-items: center;
	justify-content: center;
}

.ls-member-info {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.ls-member-name {
	font-weight: 600;
	font-size: 0.9rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.ls-member-time {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.ls-status-toast {
	position: absolute;
	top: 56px;
	left: 50%;
	transform: translateX(-50%);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	padding: 4px 16px;
	font-size: 0.85rem;
	z-index: 20;
	white-space: nowrap;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.ls-invite-bar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	background: var(--color-background-dark);
	border-top: 1px solid var(--color-border);
	font-size: 0.8rem;
	flex-shrink: 0;
}

.ls-invite-label {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.ls-invite-url {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-primary, #0082c9);
}

.ls-copy-btn {
	flex-shrink: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	padding: 2px 10px;
	cursor: pointer;
	font-size: 0.8rem;
}
</style>
