/** Small, dependency-free "time ago" formatter - the app has no date library installed. */
export function formatRelativeTime(iso: string): string {
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return ''

  const diffSeconds = Math.round((then - Date.now()) / 1000)
  const diffMinutes = Math.round(diffSeconds / 60)
  const diffHours = Math.round(diffMinutes / 60)
  const diffDays = Math.round(diffHours / 24)

  if (Math.abs(diffSeconds) < 45) return 'just now'
  if (Math.abs(diffMinutes) < 60) return formatUnit(diffMinutes, 'minute')
  if (Math.abs(diffHours) < 24) return formatUnit(diffHours, 'hour')
  if (Math.abs(diffDays) < 7) return formatUnit(diffDays, 'day')

  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatUnit(value: number, unit: string): string {
  const abs = Math.abs(value)
  const plural = abs === 1 ? unit : `${unit}s`
  return value <= 0 ? `${abs} ${plural} ago` : `in ${abs} ${plural}`
}
