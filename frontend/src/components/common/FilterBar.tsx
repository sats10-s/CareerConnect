import type { ReactNode } from 'react'
import { Select } from '@/components/ui/Select'

export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-center gap-3">{children}</div>
}

interface FilterSelectProps {
  label: string
  value: string
  onChange: (value: string) => void
  options: { value: string | number; label: string }[]
  /** Label of the "no filter" option; empty value means unfiltered. */
  allLabel: string
}

/** A labelled (for screen readers) select whose empty value means "no filter". */
export function FilterSelect({ label, value, onChange, options, allLabel }: FilterSelectProps) {
  return (
    <div className="w-[calc(50%-0.375rem)] sm:w-44">
      <Select aria-label={label} value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">{allLabel}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    </div>
  )
}

/** A labelled date input for from/to range filters. */
export function FilterDate({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return (
    <label className="flex w-full items-center gap-2 text-xs font-medium text-ink-faint sm:w-auto">
      <span className="w-9 shrink-0 sm:w-auto">{label}</span>
      <input type="date" className="input sm:w-40" value={value} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}
