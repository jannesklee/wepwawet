<template>
	<NcContent app-name="locshare">
		<NcAppNavigation>
			<template #list>
				<div class="ls-nav-section">
					<h3 class="ls-nav-heading">Invite link</h3>
					<div class="ls-invite-box">
						<input
							ref="inviteInput"
							class="ls-invite-input"
							readonly
							:value="state.inviteUrl"
							@focus="$event.target.select()" />
						<NcButton
							type="tertiary"
							:aria-label="copied ? 'Copied!' : 'Copy invite link'"
							@click="copyInviteLink">
							<template #icon>
								<CheckIcon v-if="copied" :size="20" />
								<ContentCopyIcon v-else :size="20" />
							</template>
						</NcButton>
					</div>
					<p class="ls-invite-hint">Share this link to invite family or guests.</p>
				</div>

				<div class="ls-nav-section">
					<h3 class="ls-nav-heading">Members</h3>
					<ul class="ls-member-list">
						<li
							v-for="member in members"
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
								:class="member.hasPosition ? 'ls-member-status--active' : 'ls-member-status--inactive'"
								:title="member.hasPosition ? 'Sharing location' : 'Not sharing'" />
						</li>
					</ul>
				</div>
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
import { NcContent, NcAppNavigation, NcAppContent, NcButton } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'LocShareApp',

	components: { NcContent, NcAppNavigation, NcAppContent, NcButton, CheckIcon, ContentCopyIcon },

	data() {
		return {
			map: null,
			state: loadState('locshare', 'locshare-state'),
			watchId: null,
			pollInterval: null,
			markers: {},
			members: [],
			copied: false,
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
				(pos) => {
					axios.post(this.state.updateUrl, {
						lat: pos.coords.latitude,
						lon: pos.coords.longitude,
						accuracy: pos.coords.accuracy ?? null,
						altitude: pos.coords.altitude ?? null,
						speed: pos.coords.speed ?? null,
						bearing: pos.coords.heading ?? null,
					}).catch((e) => console.error('Failed to update position', e))
				},
				(err) => console.warn('Geolocation error', err),
				{ enableHighAccuracy: true },
			)

			this.fetchPositions()
			this.pollInterval = setInterval(() => this.fetchPositions(), 15000)
		})
	},

	beforeUnmount() {
		if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId)
		if (this.pollInterval !== null) clearInterval(this.pollInterval)
		Object.values(this.markers).forEach((m) => m.remove())
		if (this.map) this.map.remove()
	},

	methods: {
		async fetchPositions() {
			try {
				const { data } = await axios.get(this.state.positionsUrl)
				this.members = data
				this.updateMarkers(data)
			} catch (e) {
				console.error('Failed to fetch positions', e)
			}
		},

		updateMarkers(members) {
			const seen = new Set()

			for (const member of members) {
				if (!member.hasPosition) continue
				seen.add(member.userId)

				if (this.markers[member.userId]) {
					this.markers[member.userId].setLngLat([member.lon, member.lat])
				} else {
					const el = this.createMarkerEl(member)
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

		async copyInviteLink() {
			try {
				await navigator.clipboard.writeText(this.state.inviteUrl)
			} catch {
				this.$refs.inviteInput.select()
				document.execCommand('copy')
			}
			this.copied = true
			setTimeout(() => { this.copied = false }, 2000)
		},
	},
}
</script>

<style>
@import 'maplibre-gl/dist/maplibre-gl.css';

#ls-map-container {
	width: 100%;
	height: 100%;
}

/* Invite section */
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

.ls-invite-box {
	display: flex;
	align-items: center;
	gap: 4px;
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

.ls-invite-hint {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #767676);
	margin: 6px 0 0;
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

.ls-marker img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
</style>
