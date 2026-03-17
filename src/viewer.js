import { createApp } from 'vue'
import Viewer from './Viewer.vue'

document.addEventListener('DOMContentLoaded', () => {
	const app = createApp(Viewer)
	app.mount('#locshare-viewer')
})
