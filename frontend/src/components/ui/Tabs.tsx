interface TabItem {
  key: string
  label: string
}

interface TabsProps {
  items: TabItem[]
  active: string
  onChange: (key: string) => void
}

/** New shared primitive - no tabs component existed before this design-system pass. Not wired into any page yet. */
export function Tabs({ items, active, onChange }: TabsProps) {
  return (
    <div role="tablist" className="flex gap-1 border-b border-hairline">
      {items.map((item) => {
        const isActive = item.key === active
        return (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={isActive}
            onClick={() => onChange(item.key)}
            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${
              isActive ? 'border-primary text-primary' : 'border-transparent text-ink-soft hover:text-ink'
            }`}
          >
            {item.label}
          </button>
        )
      })}
    </div>
  )
}
