import { useState } from 'react'

const SIZE_CLASSES = {
  sm: 'h-9 w-9 text-xs',
  md: 'h-11 w-11 text-sm',
  lg: 'h-14 w-14 text-base',
} as const

/**
 * Company logo block, per spec: "with initials fallback - never fetch
 * random external logos." The backend's Company.logo_path is stored
 * and returned as-is (no upload/URL-resolution pipeline exists for it
 * yet on the backend - checked the Resources), so this only ever
 * renders it as an <img> when it's already a real absolute URL (what
 * the backend actually gives us, not something invented here), and
 * falls back to initials for everything else - null, a bare relative
 * path this frontend has no documented way to resolve, or a broken
 * image.
 */
export function CompanyLogo({
  name,
  logoPath,
  size = 'md',
}: {
  name: string | undefined | null
  logoPath?: string | null
  size?: 'sm' | 'md' | 'lg'
}) {
  const [broken, setBroken] = useState(false)
  const initials =
    (name ?? '')
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((word) => word.charAt(0).toUpperCase())
      .join('') || '?'

  const canUseImage = Boolean(logoPath) && /^https?:\/\//.test(logoPath ?? '') && !broken

  if (canUseImage) {
    return (
      <img
        src={logoPath ?? undefined}
        alt=""
        className={`shrink-0 rounded-lg border border-hairline object-cover ${SIZE_CLASSES[size]}`}
        onError={() => setBroken(true)}
      />
    )
  }

  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-lg bg-primary-tint font-semibold text-primary ${SIZE_CLASSES[size]}`}
      aria-hidden="true"
    >
      {initials}
    </span>
  )
}
