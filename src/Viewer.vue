<template>
	<div id="ls-viewer-wrap">
		<!-- Header bar -->
		<div class="ls-viewer-header">
			<img
				v-if="state.ownerAvatarUrl"
				class="ls-viewer-avatar"
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

export default {
	name: 'LocShareViewer',

	data() {
		return {
			state: loadState('locshare', 'locshare-viewer-state'),
			map: null,
			marker: null,
			pollInterval: null,
			expiryInterval: null,
			expired: false,
			lastUpdated: null,
			expiryText: null,
		}
	},

	computed: {
		statusText() {
			if (!this.lastUpdated) return 'Waiting for position…'
			const ago = Math.round((Date.now() / 1000 - this.lastUpdated) / 60)
			if (ago < 1) return 'Updated just now'
			if (ago === 1) return 'Updated 1 min ago'
			return `Updated ${ago} min ago`
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

			if (this.state.expiresAt) {
				this.updateExpiryText()
				this.expiryInterval = setInterval(() => this.updateExpiryText(), 30000)
			}
		})
	},

	beforeUnmount() {
		if (this.pollInterval) clearInterval(this.pollInterval)
		if (this.expiryInterval) clearInterval(this.expiryInterval)
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

				if (this.marker) {
					this.marker.setLngLat([data.lon, data.lat])
				} else {
					const el = this.createMarkerEl()
					this.marker = new maplibregl.Marker({ element: el })
						.setLngLat([data.lon, data.lat])
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

#ls-viewer-wrap {
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	position: relative;
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
