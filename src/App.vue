<template>
	<div id="ls-map-container"></div>
</template>

<script>
import maplibregl from 'maplibre-gl'

export default {
	name: 'LocShareApp',

	data() {
		return {
			map: null,
		}
	},

	async mounted() {
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
	},

	beforeUnmount() {
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
