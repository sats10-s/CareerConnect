import type { ReactNode } from 'react'

interface Column<T> {
  key: string
  header: string
  render?: (row: T) => ReactNode
  className?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  emptyMessage?: string
}

/**
 * New shared primitive - no generic table component existed in this
 * codebase before this design-system pass (Faculty/Super Admin
 * dashboards render raw <table> markup directly). Not wired into any
 * page yet.
 */
export function DataTable<T>({ columns, rows, rowKey, emptyMessage = 'No records found.' }: DataTableProps<T>) {
  return (
    <div className="card overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="border-b border-hairline">
            {columns.map((column) => (
              <th
                key={column.key}
                className={`px-4 py-3 text-xs font-semibold uppercase tracking-wide text-ink-soft ${column.className ?? ''}`}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="px-4 py-8 text-center text-sm text-ink-soft">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={rowKey(row)} className="border-b border-hairline transition-colors last:border-0 hover:bg-sidebar/60">
                {columns.map((column) => (
                  <td key={column.key} className={`px-4 py-3 text-ink ${column.className ?? ''}`}>
                    {column.render ? column.render(row) : String((row as Record<string, unknown>)[column.key] ?? '')}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}
