import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import Viewer from './Viewer.vue'
import { registerServiceWorker } from './utils/registerSW.js'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(Viewer)
	app.mount('#wepwawet-viewer')

	const swUrl = loadState('wepwawet', 'wepwawet-viewer-state').swUrl
	registerServiceWorker(swUrl)
})
