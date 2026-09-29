/** "650000.00" (Laravel decimal string) -> "₹6,50,000". */
export function formatInr(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'number' ? value : Number(value)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n)
}

/** 66.666 -> "66.7%", 100 -> "100%" (the reports API already returns 0-100 percentages, not ratios). */
export function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `${Number.isInteger(value) ? value : value.toFixed(1)}%`
}
