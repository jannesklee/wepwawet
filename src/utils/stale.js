export const STALE_SECONDS = 180

export function isStale(updatedAt, nowTs) {
	if (!updatedAt) return false
	return (nowTs - updatedAt) >= STALE_SECONDS
}
