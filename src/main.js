import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import App from './App.vue'
import { registerServiceWorker } from './utils/registerSW.js'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(App)
	app.mixin({ methods: { t, n } })
	app.mount('#sopdet-app')

	const swUrl = loadState('sopdet', 'sopdet-state').swUrl
	registerServiceWorker(swUrl)
})
