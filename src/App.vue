<template>
	<NcContent app-name="locshare">
		<NcAppNavigation>
			<template #list>
				<template v-if="openGroup">
					<!-- Group detail -->
					<div class="ls-nav-section">
						<button class="ls-back-link" @click="openGroupId = null">‹ Groups</button>
					</div>
					<div class="ls-nav-section">
						<h3 class="ls-nav-heading">{{ openGroup.name }}</h3>
						<p class="ls-share-expiry" style="margin-bottom:6px;">
							{{ openGroup.visible ? "You're visible to this group." : "You're hidden from this group." }}
						</p>

						<ul class="ls-member-list">
							<li
								v-for="member in openGroup.members"
								:key="member.userId"
								class="ls-member-item">
								<img
									v-if="member.avatarUrl"
									class="ls-member-avatar"
									:src="member.avatarUrl"
									:alt="member.displayName" />
								<span v-else class="ls-member-avatar ls-member-avatar--initial">
									{{ member.displayName.charAt(0).toUpperCase() }}
								</span>
								<span class="ls-member-name">
									{{ member.displayName }}
									<span v-if="member.isMe"> (you)</span>
								</span>
								<span
									class="ls-member-status"
									:class="memberStatusClass(member)"
									:title="memberStatusTitle(member)" />
								<NcButton
									v-if="openGroup.isOwner && !member.isMe"
									type="tertiary"
									aria-label="Remove member"
									@click="removeGroupMember(openGroup, member.userId)">
									<template #icon>
										<DeleteIcon :size="16" />
									</template>
								</NcButton>
							</li>
						</ul>

						<div class="ls-share-url-row" style="margin-top:10px;">
							<input
								class="ls-invite-input"
								readonly
								:value="openGroup.inviteUrl"
								@focus="$event.target.select()" />
							<NcButton
								type="tertiary"
								:aria-label="copiedGroupId === openGroup.id ? 'Copied!' : 'Copy invite link'"
								@click="copyGroupInvite(openGroup)">
								<template #icon>
									<CheckIcon v-if="copiedGroupId === openGroup.id" :size="18" />
									<ContentCopyIcon v-else :size="18" />
								</template>
							</NcButton>
						</div>

						<button
							v-if="openGroup.isOwner"
							class="ls-delete-group-btn"
							@click="deleteGroup(openGroup)">
							Delete group
						</button>
					</div>
				</template>

				<template v-else>
				<!-- Share location (Mode 2) -->
				<div class="ls-nav-section">
					<h3 class="ls-nav-heading">Share my location</h3>
					<div class="ls-duration-row">
						<button
							v-for="opt in durationOptions"
							:key="opt.minutes"
							class="ls-dur-btn"
							:class="{ 'ls-dur-btn--active': shareMinutes === opt.minutes }"
							@click="shareMinutes = opt.minutes">
							{{ opt.label }}
						</button>
					</div>
					<NcButton
						type="primary"
						:disabled="creatingShare"
						style="width:100%;margin-top:8px;"
						@click="createShare">
						Create share link
					</NcButton>
				</div>

				<!-- Active shares -->
				<div v-if="shares.length" class="ls-nav-section">
					<h3 class="ls-nav-heading">Active links</h3>
					<ul class="ls-share-list">
						<li v-for="share in shares" :key="share.id" class="ls-share-item">
							<div class="ls-share-url-row">
								<input
									class="ls-invite-input"
									readonly
									:value="share.url"
									@focus="$event.target.select()" />
								<NcButton
									type="tertiary"
									:aria-label="copiedId === share.id ? 'Copied!' : 'Copy link'"
									@click="copyShare(share)">
									<template #icon>
										<CheckIcon v-if="copiedId === share.id" :size="18" />
										<ContentCopyIcon v-else :size="18" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									aria-label="Revoke link"
									@click="revokeShare(share.id)">
									<template #icon>
										<DeleteIcon :size="18" />
									</template>
								</NcButton>
							</div>
							<p class="ls-share-expiry">
								{{ formatExpiry(share.expiresAt) }}
							</p>
						</li>
					</ul>
				</div>

				<!-- Groups overview (Mode 1) -->
				<div class="ls-nav-section">
					<h3 class="ls-nav-heading">Groups</h3>
					<p class="ls-share-expiry" style="margin-bottom:6px;">
						Everyone in a group can see each other's location.
					</p>

					<div v-for="group in groups" :key="group.id" class="ls-group-overview-row">
						<button class="ls-group-name-link" @click="openGroupId = group.id">
							{{ group.name }} <span class="ls-chevron">›</span>
						</button>
						<label class="ls-group-visible">
							<input
								type="checkbox"
								:checked="group.visible"
								@change="toggleGroupVisibility(group)" />
							Visible here
						</label>
					</div>

					<div class="ls-new-group-row">
						<input
							v-model="newGroupName"
							class="ls-invite-input"
							placeholder="New group name"
							@keyup.enter="createGroup" />
						<NcButton
							type="tertiary"
							:disabled="creatingGroup || !newGroupName.trim()"
							@click="createGroup">
							+ Create
						</NcButton>
					</div>
				</div>
				</template>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div id="ls-map-container" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import maplibregl from 'maplibre-gl'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcContent, NcAppNavigation, NcAppContent, NcButton } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { isStale } from './utils/stale.js'

export default {
	name: 'LocShareApp',

	components: { NcContent, NcAppNavigation, NcAppContent, NcButton, CheckIcon, ContentCopyIcon, DeleteIcon },

	data() {
		return {
			map: null,
			state: loadState('locshare', 'locshare-state'),
			watchId: null,
			pollInterval: null,
			heartbeatInterval: null,
			markers: {},
			members: [],
			nowTs: Math.floor(Date.now() / 1000),
			statusInterval: null,
			lastPosition: null,
			wakeLock: null,
			// groups (Mode 1)
			groups: [],
			openGroupId: null,
			newGroupName: '',
			creatingGroup: false,
			copiedGroupId: null,
			// share management (Mode 2)
			shares: [],
			shareMinutes: 60,
			creatingShare: false,
			copiedId: null,
		}
	},

	mounted() {
		this.$nextTick(() => {
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
			})

			this.watchId = navigator.geolocation.watchPosition(
				(pos) => this.sendPosition(pos),
				(err) => console.warn('Geolocation error', err),
				{ enableHighAccuracy: true, maximumAge: 30000, timeout: 15000 },
			)

			// Heartbeat: re-send last known position every 30s in case watchPosition
			// stops firing (stationary device, browser throttling, etc.)
			this.heartbeatInterval = setInterval(() => {
				if (this.lastPosition) this.sendPosition(this.lastPosition)
			}, 30000)

			this.acquireWakeLock()
			document.addEventListener('visibilitychange', this.onVisibilityChange)

			this.fetchGroups().then(() => this.fetchPositions())
			this.pollInterval = setInterval(() => {
				this.fetchGroups().then(() => this.fetchPositions())
				this.fetchShares()
			}, 15000)
			this.statusInterval = setInterval(() => {
				this.nowTs = Math.floor(Date.now() / 1000)
				this.updateStaleClasses()
			}, 30000)
			this.fetchShares()
		})
	},

	beforeUnmount() {
		if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId)
		if (this.pollInterval !== null) clearInterval(this.pollInterval)
		if (this.heartbeatInterval !== null) clearInterval(this.heartbeatInterval)
		if (this.statusInterval !== null) clearInterval(this.statusInterval)
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
		this.releaseWakeLock()
		Object.values(this.markers).forEach((m) => m.remove())
		if (this.map) this.map.remove()
	},

	methods: {
		sendPosition(pos) {
			this.lastPosition = pos
			axios.post(this.state.updateUrl, {
				lat: pos.coords.latitude,
				lon: pos.coords.longitude,
				accuracy: pos.coords.accuracy ?? null,
				altitude: pos.coords.altitude ?? null,
				speed: pos.coords.speed ?? null,
				bearing: pos.coords.heading ?? null,
			}).catch((e) => console.error('Failed to update position', e))
		},

		async acquireWakeLock() {
			if (!('wakeLock' in navigator)) return
			try {
				this.wakeLock = await navigator.wakeLock.request('screen')
			} catch (e) {
				console.warn('Wake lock not granted', e)
			}
		},

		releaseWakeLock() {
			if (this.wakeLock) {
				this.wakeLock.release()
				this.wakeLock = null
			}
		},

		// Re-acquire wake lock when tab becomes visible again (it is released automatically
		// when the tab goes to the background)
		onVisibilityChange() {
			if (document.visibilityState === 'visible') {
				this.acquireWakeLock()
				// Also send an immediate heartbeat so we don't wait up to 30s
				if (this.lastPosition) this.sendPosition(this.lastPosition)
			}
		},

		async fetchGroups() {
			try {
				const { data } = await axios.get(this.state.groupsUrl)
				this.groups = data.groups
			} catch (e) {
				console.error('Failed to fetch groups', e)
			}
		},

		async createGroup() {
			const name = this.newGroupName.trim()
			if (!name) return
			this.creatingGroup = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/locshare/groups') + '?name=' + encodeURIComponent(name),
				)
				data.members = []
				this.groups.push(data)
				this.newGroupName = ''
			} catch (e) {
				console.error('Failed to create group', e)
			} finally {
				this.creatingGroup = false
			}
		},

		async toggleGroupVisibility(group) {
			const next = !group.visible
			try {
				await axios.post(
					generateUrl('/apps/locshare/group/' + group.id + '/visibility') + '?visible=' + (next ? '1' : '0'),
				)
				group.visible = next
				this.fetchPositions()
			} catch (e) {
				console.error('Failed to update group visibility', e)
			}
		},

		async removeGroupMember(group, userId) {
			try {
				await axios.post(
					generateUrl('/apps/locshare/group/' + group.id + '/members/' + encodeURIComponent(userId) + '/remove'),
				)
				group.members = group.members.filter((m) => m.userId !== userId)
			} catch (e) {
				console.error('Failed to remove group member', e)
			}
		},

		async deleteGroup(group) {
			if (!confirm(`Delete "${group.name}"? Everyone in it will lose access, including you.`)) return
			try {
				await axios.post(generateUrl('/apps/locshare/group/' + group.id + '/delete'))
				this.groups = this.groups.filter((g) => g.id !== group.id)
				this.openGroupId = null
			} catch (e) {
				console.error('Failed to delete group', e)
			}
		},

		async copyGroupInvite(group) {
			try {
				await navigator.clipboard.writeText(group.inviteUrl)
			} catch {
				// fallback: not needed for modern browsers
			}
			this.copiedGroupId = group.id
			setTimeout(() => { this.copiedGroupId = null }, 2000)
		},

		// Merges each group's own member/guest list (kept on group.members for the
		// per-group sidebar view) into one flat, deduplicated list for the map —
		// the same person can be visible via more than one group.
		async fetchPositions() {
			try {
				const results = await Promise.all(
					this.groups.map((g) => axios.get(g.positionsUrl).then((r) => r.data).catch(() => [])),
				)
				const merged = new Map()
				this.groups.forEach((group, i) => {
					group.members = results[i]
					for (const m of results[i]) {
						const existing = merged.get(m.userId)
						if (!existing || (!existing.hasPosition && m.hasPosition)) {
							merged.set(m.userId, m)
						}
					}
				})
				const data = Array.from(merged.values())
				this.members = data
				this.updateMarkers(data)
			} catch (e) {
				console.error('Failed to fetch positions', e)
			}
		},

		updateMarkers(members) {
			const seen = new Set()
			this.nowTs = Math.floor(Date.now() / 1000)

			for (const member of members) {
				if (!member.hasPosition) continue
				seen.add(member.userId)

				if (this.markers[member.userId]) {
					this.markers[member.userId].setLngLat([member.lon, member.lat])
					this.markers[member.userId].getElement()
						.classList.toggle('ls-marker--stale', isStale(member.updatedAt, this.nowTs))
				} else {
					const el = this.createMarkerEl(member)
					el.classList.toggle('ls-marker--stale', isStale(member.updatedAt, this.nowTs))
					this.markers[member.userId] = new maplibregl.Marker({ element: el })
						.setLngLat([member.lon, member.lat])
						.setPopup(new maplibregl.Popup({ offset: 28, maxWidth: 'none' })
							.setHTML(this.buildPopupHtml(member)))
						.addTo(this.map)
				}
			}

			for (const userId of Object.keys(this.markers)) {
				if (!seen.has(userId)) {
					this.markers[userId].remove()
					delete this.markers[userId]
				}
			}

			this.fitBounds(members.filter((m) => m.hasPosition))
		},

		memberStatusClass(member) {
			if (!member.hasPosition) return 'ls-member-status--inactive'
			if (isStale(member.updatedAt, this.nowTs)) return 'ls-member-status--stale'
			return 'ls-member-status--active'
		},

		memberStatusTitle(member) {
			if (!member.hasPosition) return 'Not sharing'
			if (isStale(member.updatedAt, this.nowTs)) {
				const mins = Math.round((this.nowTs - member.updatedAt) / 60)
				return `Last update ${mins} min ago`
			}
			return 'Sharing location'
		},

		updateStaleClasses() {
			for (const member of this.members) {
				if (!member.hasPosition || !this.markers[member.userId]) continue
				this.markers[member.userId].getElement()
					.classList.toggle('ls-marker--stale', isStale(member.updatedAt, this.nowTs))
			}
		},

		createMarkerEl(member) {
			const el = document.createElement('div')
			el.className = 'ls-marker' + (member.isMe ? ' ls-marker--me' : '')

			if (member.avatarUrl) {
				const img = document.createElement('img')
				img.src = member.avatarUrl
				img.alt = member.displayName
				el.appendChild(img)
			} else {
				el.textContent = member.displayName.charAt(0).toUpperCase()
			}

			return el
		},

		buildPopupHtml(member) {
			const avatar = member.avatarUrl
				? `<img src="${member.avatarUrl}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;" />`
				: `<span style="width:32px;height:32px;border-radius:50%;background:#0082c9;color:#fff;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${member.displayName.charAt(0).toUpperCase()}</span>`
			const updated = member.updatedAt
				? new Date(member.updatedAt * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
				: null
			return `<div style="display:flex;align-items:center;gap:10px;padding:4px 2px;font-family:sans-serif;">
				${avatar}
				<div style="display:flex;flex-direction:column;gap:2px;">
					<span style="font-size:13px;font-weight:600;color:#222;white-space:nowrap;">${member.displayName}</span>
					${updated ? `<span style="font-size:11px;color:#767676;">Updated ${updated}</span>` : ''}
					${member.acc ? `<span style="font-size:11px;color:#767676;">±${Math.round(member.acc)} m</span>` : ''}
				</div>
			</div>`
		},

		fitBounds(members) {
			if (members.length === 0) return
			if (members.length === 1) {
				this.map.flyTo({ center: [members[0].lon, members[0].lat], zoom: 13 })
				return
			}
			const bounds = new maplibregl.LngLatBounds()
			members.forEach((m) => bounds.extend([m.lon, m.lat]))
			this.map.fitBounds(bounds, { padding: 60, maxZoom: 15 })
		},

		async fetchShares() {
			try {
				const { data } = await axios.get(generateUrl('/apps/locshare/shares'))
				this.shares = data
			} catch (e) {
				console.error('Failed to fetch shares', e)
			}
		},

		async createShare() {
			this.creatingShare = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/locshare/share') + '?duration=' + this.shareMinutes,
				)
				this.shares.unshift(data)
				this.copyShare(data)
			} catch (e) {
				console.error('Failed to create share', e)
			} finally {
				this.creatingShare = false
			}
		},

		async revokeShare(id) {
			try {
				await axios.post(generateUrl('/apps/locshare/share/' + id + '/revoke'))
				this.shares = this.shares.filter((s) => s.id !== id)
			} catch (e) {
				console.error('Failed to revoke share', e)
			}
		},

		async copyShare(share) {
			try {
				await navigator.clipboard.writeText(share.url)
			} catch {
				// fallback: not needed for modern browsers
			}
			this.copiedId = share.id
			setTimeout(() => { this.copiedId = null }, 2000)
		},

		formatExpiry(expiresAt) {
			if (!expiresAt) return 'No expiry'
			const diff = expiresAt - Math.floor(Date.now() / 1000)
			if (diff <= 0) return 'Expired'
			const h = Math.floor(diff / 3600)
			const m = Math.floor((diff % 3600) / 60)
			return h > 0 ? `Expires in ${h}h ${m}m` : `Expires in ${m + 1} min`
		},
	},

	computed: {
		openGroup() {
			return this.groups.find((g) => g.id === this.openGroupId) || null
		},

		durationOptions() {
			return [
				{ minutes: 15, label: '15 min' },
				{ minutes: 60, label: '1 hr' },
				{ minutes: 240, label: '4 hr' },
				{ minutes: 0, label: '∞' },
			]
		},
	},
}
</script>

<style>
@import 'maplibre-gl/dist/maplibre-gl.css';

/* Ensure Vue root fills Nextcloud's content area so height:100% chain works */
#locshare-app {
	height: 100%;
}

#ls-map-container {
	width: 100%;
	height: 100%;
}

/* Sidebar sections */
.ls-nav-section {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.ls-nav-heading {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 8px;
}

/* Duration picker */
.ls-duration-row {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 6px;
}

.ls-duration-row .ls-dur-btn {
	padding: 7px 2px;
	font-size: 12px;
	font-weight: 600;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 3px);
	background: var(--color-main-background, #fff);
	color: var(--color-text-maxcontrast, #000000);
	cursor: pointer;
	transition: border-color 0.1s, color 0.1s;
}

.ls-duration-row .ls-dur-btn--active,
.ls-duration-row .ls-dur-btn:active {
	background-color: var(--color-primary, #0082c9) !important;
	color: var(--color-primary-text, #fff) !important;
	border-color: var(--color-primary, #0082c9);
}


/* Active share list */
.ls-share-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.ls-share-item {
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.ls-share-url-row {
	display: flex;
	align-items: center;
	gap: 2px;
}

.ls-invite-input {
	flex: 1;
	min-width: 0;
	font-size: 12px;
	padding: 4px 8px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 3px);
	background: var(--color-background-hover, #f5f5f5);
	color: var(--color-text-light, #222);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ls-share-expiry {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #767676);
	margin: 0;
	padding-left: 2px;
}

/* Groups */
.ls-group-overview-row {
	padding: 8px 0;
	border-top: 1px solid var(--color-border, #ededed);
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ls-group-overview-row:first-child {
	border-top: none;
	padding-top: 0;
}

.ls-group-name-link {
	display: flex;
	align-items: center;
	gap: 2px;
	background: none;
	border: none;
	padding: 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-light, #222);
	cursor: pointer;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ls-chevron {
	color: var(--color-text-maxcontrast, #767676);
}

.ls-back-link {
	background: none;
	border: none;
	padding: 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-primary, #0082c9);
	cursor: pointer;
}

.ls-delete-group-btn {
	display: block;
	background: none;
	border: none;
	margin: 14px auto 0;
	padding: 4px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-error, #dc2626);
	cursor: pointer;
}

.ls-group-visible {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast, #767676);
	white-space: nowrap;
}

.ls-new-group-row {
	display: flex;
	align-items: center;
	gap: 2px;
	margin-top: 4px;
}

/* Member list */
.ls-member-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ls-member-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ls-member-avatar {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	flex-shrink: 0;
	object-fit: cover;
}

.ls-member-avatar--initial {
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-size: 14px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
}

.ls-member-name {
	flex: 1;
	font-size: 13px;
	color: var(--color-text-light, #222);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ls-member-status {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}

.ls-member-status--active {
	background: #46ba61;
}

.ls-member-status--stale {
	background: var(--color-border-dark, #c8c8c8);
}

.ls-member-status--inactive {
	background: var(--color-border-dark, #c8c8c8);
}

/* MapLibre popup reset — Nextcloud global styles can collapse the content */
.maplibregl-popup-content {
	padding: 10px 14px !important;
	border-radius: 8px !important;
	box-shadow: 0 2px 8px rgba(0,0,0,.2) !important;
	font-size: inherit !important;
	line-height: inherit !important;
}

/* Map markers */
.ls-marker {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	border: 2px solid #fff;
	box-shadow: 0 1px 4px rgba(0, 0, 0, .4);
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-size: 15px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	overflow: hidden;
}

.ls-marker--me {
	border-color: var(--color-primary, #0082c9);
	border-width: 3px;
}

.ls-marker--stale {
	filter: grayscale(100%);
	opacity: 0.5;
	transition: filter 0.4s, opacity 0.4s;
}

.ls-marker img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
</style>
