export const STALE_SECONDS = 60

export function isStale(updatedAt, nowTs) {
	if (!updatedAt) return false
	return (nowTs - updatedAt) >= STALE_SECONDS
}
