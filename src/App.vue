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
	},

	beforeUnmount() {
		if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId)
		if (this.map) this.map.remove()
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
</style>
