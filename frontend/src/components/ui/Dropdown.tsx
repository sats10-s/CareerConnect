import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'

interface DropdownItem {
  label: string
  onClick: () => void
  icon?: ReactNode
}

interface DropdownProps {
  trigger: ReactNode
  items: DropdownItem[]
  align?: 'left' | 'right'
}

/**
 * New shared primitive - generalizes the inline menu pattern already
 * used ad hoc in Topbar's account menu, as a reusable component. Not
 * wired into any page yet (Topbar keeps its own inline implementation,
 * unchanged, per "do not rewrite functionality").
 */
export function Dropdown({ trigger, items, align = 'right' }: DropdownProps) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onClickAway = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onClickAway)
    return () => document.removeEventListener('mousedown', onClickAway)
  }, [open])

  return (
    <div ref={containerRef} className="relative inline-block">
      <button type="button" onClick={() => setOpen((value) => !value)} aria-haspopup="menu" aria-expanded={open}>
        {trigger}
      </button>
      {open && (
        <div
          role="menu"
          className={`absolute top-full z-20 mt-2 w-44 rounded-lg border border-hairline bg-surface py-1 shadow-lg ${
            align === 'right' ? 'right-0' : 'left-0'
          }`}
        >
          {items.map((item) => (
            <button
              key={item.label}
              role="menuitem"
              type="button"
              onClick={() => {
                item.onClick()
                setOpen(false)
              }}
              className="flex w-full items-center gap-2 px-3.5 py-2 text-sm text-ink-soft transition-colors hover:bg-sidebar hover:text-ink"
            >
              {item.icon}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
