import { useEffect, useState } from 'react'
import { Search, X } from 'lucide-react'

interface SearchBarProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  /** Debounce delay in ms before onChange fires after typing stops. 0 disables debouncing. */
  debounceMs?: number
  className?: string
}

/**
 * New shared primitive, added while building the Student Applications
 * page - the first list page in this build-out with a real search
 * filter. Debounces internally so callers can wire `onChange` straight
 * to a query param / API call without building their own timer.
 */
export function SearchBar({ value, onChange, placeholder = 'Search...', debounceMs = 300, className = '' }: SearchBarProps) {
  const [draft, setDraft] = useState(value)

  // Keep the input in sync if the value is reset from outside (e.g. a "clear filters" action).
  useEffect(() => {
    setDraft(value)
  }, [value])

  useEffect(() => {
    if (debounceMs === 0) return
    const handle = window.setTimeout(() => {
      if (draft !== value) onChange(draft)
    }, debounceMs)
    return () => window.clearTimeout(handle)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft, debounceMs])

  return (
    <div className={`relative ${className}`}>
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
      <input
        type="search"
        value={draft}
        onChange={(event) => {
          const next = event.target.value
          setDraft(next)
          if (debounceMs === 0) onChange(next)
        }}
        placeholder={placeholder}
        className="input pl-9 pr-9"
        aria-label={placeholder}
      />
      {draft && (
        <button
          type="button"
          onClick={() => {
            setDraft('')
            onChange('')
          }}
          aria-label="Clear search"
          className="absolute right-2.5 top-1/2 -translate-y-1/2 text-ink-faint hover:text-ink"
        >
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  )
}
