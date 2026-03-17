<template>
	<div id="ls-map-container"></div>
</template>

<script>
import maplibregl from 'maplibre-gl'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'

export default {
	name: 'LocShareApp',

	data() {
		return {
			map: null,
			state: loadState('locshare', 'locshare-state'),
			watchId: null,
			pollInterval: null,
			markers: {}, // userId -> maplibregl.Marker
		}
	},

	mounted() {
		this.map = new maplibregl.Map({
			container: 'ls-map-container',
			style: {
				version: 8,
				sources: {
					osm: {
						type: 'raster',
						tiles: [
							'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
						],
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
						.setPopup(new maplibregl.Popup({ offset: 28 }).setText(member.displayName))
						.addTo(this.map)
				}
			}

			// Remove markers for members no longer in the response
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
	},
}
</script>

<style>
@import 'maplibre-gl/dist/maplibre-gl.css';

#locshare-app,
#ls-map-container {
	width: 100%;
	height: 100%;
}

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
