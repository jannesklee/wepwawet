export function registerServiceWorker(swUrl) {
	if (!('serviceWorker' in navigator)) return
	window.addEventListener('load', () => {
		navigator.serviceWorker.register(swUrl)
			.catch(err => console.warn('SW registration failed:', err))
	})
}
