<template>
	<div id="ls-viewer-wrap">
		<!-- Header bar -->
		<div class="ls-viewer-header">
			<img
				v-if="state.ownerAvatarUrl"
				class="ls-viewer-avatar"
				:class="{ 'ls-viewer-avatar--stale': isStale }"
				:src="state.ownerAvatarUrl"
				:alt="state.ownerDisplayName" />
			<div class="ls-viewer-info">
				<span class="ls-viewer-name">{{ state.ownerDisplayName }}</span>
				<span v-if="statusText" class="ls-viewer-status">{{ statusText }}</span>
			</div>
			<span v-if="expiryText" class="ls-viewer-expiry">{{ expiryText }}</span>
		</div>

		<!-- Map -->
		<div id="ls-viewer-map" />

		<!-- Expired overlay -->
		<div v-if="expired" class="ls-viewer-overlay">
			<div class="ls-viewer-overlay-card">
				<div style="font-size:48px">⏱️</div>
				<h2>Share link expired</h2>
				<p>This live location link is no longer active.</p>
			</div>
		</div>
	</div>
</template>

<script>
import maplibregl from 'maplibre-gl'
import { loadState } from '@nextcloud/initial-state'
import { isStale } from './utils/stale.js'

export default {
	name: 'SopdetViewer',

	data() {
		return {
			state: loadState('sopdet', 'sopdet-viewer-state'),
			map: null,
			marker: null,
			markerEl: null,
			popup: null,
			pollInterval: null,
			expiryInterval: null,
			statusInterval: null,
			expired: false,
			lastUpdated: null,
			expiryText: null,
			nowTs: Math.floor(Date.now() / 1000),
		}
	},

	computed: {
		isStale() {
			return isStale(this.lastUpdated, this.nowTs)
		},

		statusText() {
			if (!this.lastUpdated) return 'Waiting for position…'
			const ago = Math.round((this.nowTs - this.lastUpdated) / 60)
			if (ago < 1) return 'Updated just now'
			if (ago === 1) return 'Updated 1 min ago'
			return `Updated ${ago} min ago`
		},
	},

	watch: {
		isStale(val) {
			if (this.markerEl) {
				this.markerEl.classList.toggle('ls-marker--stale', val)
			}
		},
	},

	mounted() {
		this.$nextTick(() => {
			this.map = new maplibregl.Map({
				container: 'ls-viewer-map',
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
				center: [0, 20],
				zoom: 2,
			})

			this.fetchPosition()
			this.pollInterval = setInterval(() => this.fetchPosition(), 10000)
			this.statusInterval = setInterval(() => { this.nowTs = Math.floor(Date.now() / 1000) }, 30000)

			if (this.state.expiresAt) {
				this.updateExpiryText()
				this.expiryInterval = setInterval(() => this.updateExpiryText(), 30000)
			}
		})
	},

	beforeUnmount() {
		if (this.pollInterval) clearInterval(this.pollInterval)
		if (this.expiryInterval) clearInterval(this.expiryInterval)
		if (this.statusInterval) clearInterval(this.statusInterval)
		if (this.marker) this.marker.remove()
		if (this.map) this.map.remove()
	},

	methods: {
		async fetchPosition() {
			if (this.expired) return

			try {
				const res = await fetch(this.state.positionUrl)
				if (res.status === 410) {
					this.expired = true
					clearInterval(this.pollInterval)
					return
				}
				const data = await res.json()

				if (data.expired) {
					this.expired = true
					clearInterval(this.pollInterval)
					return
				}

				if (!data.hasPosition) return

				this.lastUpdated = data.updatedAt
				this.nowTs = Math.floor(Date.now() / 1000)

				if (this.marker) {
					this.marker.setLngLat([data.lon, data.lat])
					if (this.popup) this.popup.setHTML(this.buildPopupHtml())
				} else {
					this.markerEl = this.createMarkerEl()
					this.popup = new maplibregl.Popup({ offset: 28, maxWidth: 'none' })
						.setHTML(this.buildPopupHtml())
					this.marker = new maplibregl.Marker({ element: this.markerEl })
						.setLngLat([data.lon, data.lat])
						.setPopup(this.popup)
						.addTo(this.map)
					this.map.flyTo({ center: [data.lon, data.lat], zoom: 13 })
				}
			} catch (e) {
				console.warn('Failed to fetch position', e)
			}
		},

		createMarkerEl() {
			const el = document.createElement('div')
			el.className = 'ls-marker ls-marker--me'
			if (this.state.ownerAvatarUrl) {
				const img = document.createElement('img')
				img.src = this.state.ownerAvatarUrl
				img.alt = this.state.ownerDisplayName
				el.appendChild(img)
			} else {
				el.textContent = this.state.ownerDisplayName.charAt(0).toUpperCase()
			}
			return el
		},

		buildPopupHtml() {
			const name = this.state.ownerDisplayName
			const avatar = this.state.ownerAvatarUrl
				? `<img src="${this.state.ownerAvatarUrl}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;" />`
				: `<span style="width:32px;height:32px;border-radius:50%;background:#0082c9;color:#fff;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${name.charAt(0).toUpperCase()}</span>`
			const updated = this.lastUpdated
				? new Date(this.lastUpdated * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
				: null
			return `<div style="display:flex;align-items:center;gap:10px;padding:4px 2px;font-family:sans-serif;">
				${avatar}
				<div style="display:flex;flex-direction:column;gap:2px;">
					<span style="font-size:13px;font-weight:600;color:#222;white-space:nowrap;">${name}</span>
					${updated ? `<span style="font-size:11px;color:#767676;">Updated ${updated}</span>` : ''}
				</div>
			</div>`
		},

		updateExpiryText() {
			if (!this.state.expiresAt) return
			const diff = this.state.expiresAt - Math.floor(Date.now() / 1000)
			if (diff <= 0) {
				this.expired = true
				this.expiryText = 'Expired'
				clearInterval(this.expiryInterval)
				clearInterval(this.pollInterval)
				return
			}
			const h = Math.floor(diff / 3600)
			const m = Math.floor((diff % 3600) / 60)
			this.expiryText = h > 0 ? `Expires in ${h}h ${m}m` : `Expires in ${m + 1} min`
		},
	},
}
</script>

<style>
@import 'maplibre-gl/dist/maplibre-gl.css';

/* Escape Nextcloud's public-page layout entirely — it has multiple nested
   wrappers with max-width / padding constraints designed for file-share cards.
   Fixed positioning anchors us directly to the viewport below the header. */
#ls-viewer-wrap {
	position: fixed;
	top: var(--header-height, 50px);
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
}

.ls-viewer-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background, #fff);
	border-bottom: 1px solid var(--color-border, #ededed);
	z-index: 10;
	flex-shrink: 0;
}

.ls-viewer-avatar {
	width: 40px;
	height: 40px;
	border-radius: 50%;
	object-fit: cover;
	flex-shrink: 0;
	transition: filter 0.4s, opacity 0.4s;
}

.ls-viewer-avatar--stale {
	filter: grayscale(100%);
	opacity: 0.5;
}

.ls-viewer-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.ls-viewer-name {
	font-size: 15px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.ls-viewer-status {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #767676);
}

.ls-viewer-expiry {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-primary, #0082c9);
	white-space: nowrap;
}

#ls-viewer-map {
	flex: 1;
	min-height: 0;
}

.ls-viewer-overlay {
	position: absolute;
	inset: 0;
	background: rgba(0, 0, 0, .5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 20;
}

.ls-viewer-overlay-card {
	background: var(--color-main-background, #fff);
	border-radius: 16px;
	padding: 32px 40px;
	text-align: center;
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 320px;
}

.ls-viewer-overlay-card h2 {
	font-size: 1.2rem;
	font-weight: 700;
	margin: 0;
	color: var(--color-main-text, #222);
}

.ls-viewer-overlay-card p {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

/* Reuse marker styles */
.ls-marker {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	border: 2px solid #fff;
	box-shadow: 0 1px 4px rgba(0,0,0,.4);
	background: var(--color-primary, #0082c9);
	color: #fff;
	font-size: 15px;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	overflow: hidden;
	transition: filter 0.4s, opacity 0.4s;
}

.ls-marker--me {
	border-color: var(--color-primary, #0082c9);
	border-width: 3px;
}

.ls-marker--stale {
	filter: grayscale(100%);
	opacity: 0.5;
}

.ls-marker img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

/* MapLibre popup reset */
.maplibregl-popup-content {
	padding: 10px 14px !important;
	border-radius: 8px !important;
	box-shadow: 0 2px 8px rgba(0,0,0,.2) !important;
	font-size: inherit !important;
	line-height: inherit !important;
}
</style>
