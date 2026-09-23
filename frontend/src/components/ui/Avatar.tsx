/**
 * Initials avatar - indigo-tinted circle, used wherever a person needs
 * a compact visual identity (topbar account menu, activity feed actor).
 * No photo upload exists in this app, so initials-only is the honest
 * representation rather than a placeholder image.
 */
export function Avatar({ name, size = 'md' }: { name: string | undefined | null; size?: 'sm' | 'md' | 'lg' }) {
  const initial = name?.trim()?.charAt(0)?.toUpperCase() ?? '?'
  const sizeClasses = {
    sm: 'h-7 w-7 text-xs',
    md: 'h-9 w-9 text-sm',
    lg: 'h-12 w-12 text-base',
  }[size]

  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-full bg-indigo-tint font-semibold text-indigo ${sizeClasses}`}
      aria-hidden="true"
    >
      {initial}
    </span>
  )
}
