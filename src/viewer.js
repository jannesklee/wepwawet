import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import Viewer from './Viewer.vue'
import { registerServiceWorker } from './utils/registerSW.js'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(Viewer)
	app.mount('#locshare-viewer')

	const swUrl = loadState('locshare', 'locshare-viewer-state').swUrl
	registerServiceWorker(swUrl)
})
