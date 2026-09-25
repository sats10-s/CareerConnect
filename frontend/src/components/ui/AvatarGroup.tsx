import { Avatar } from './Avatar'

/**
 * New shared primitive - stacked overlapping avatars with a "+N"
 * overflow indicator, built on the existing Avatar. Not wired into any
 * page yet (nothing currently shows a group of people together - e.g.
 * interview panelists - but faculty/admin modules in later phases
 * will).
 */
export function AvatarGroup({ names, max = 4, size = 'sm' }: { names: string[]; max?: number; size?: 'sm' | 'md' | 'lg' }) {
  const visible = names.slice(0, max)
  const overflow = names.length - visible.length
  const sizeClasses = { sm: 'h-7 w-7 text-xs', md: 'h-9 w-9 text-sm', lg: 'h-12 w-12 text-base' }[size]

  return (
    <div className="flex -space-x-2">
      {visible.map((name, index) => (
        <span key={`${name}-${index}`} className="ring-2 ring-surface rounded-full">
          <Avatar name={name} size={size} />
        </span>
      ))}
      {overflow > 0 && (
        <span
          className={`flex shrink-0 items-center justify-center rounded-full bg-sidebar font-semibold text-muted ring-2 ring-surface ${sizeClasses}`}
        >
          +{overflow}
        </span>
      )}
    </div>
  )
}
