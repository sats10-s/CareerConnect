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
  /**
   * When provided, rows render through this instead of the table below
   * the `lg` breakpoint, so a page never needs horizontal scroll to
   * read its core info on mobile (the desktop `<table>` still renders,
   * just hidden `< lg`). Most pages should prefer intercepting the
   * empty-rows case themselves with `EmptyState` (specific, actionable
   * copy) rather than relying on `emptyMessage`.
   */
  renderMobileCard?: (row: T) => ReactNode
  onRowClick?: (row: T) => void
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  emptyMessage = 'No records found.',
  renderMobileCard,
  onRowClick,
}: DataTableProps<T>) {
  const isClickable = Boolean(onRowClick)

  return (
    <>
      <div className={`card overflow-x-auto ${renderMobileCard ? 'hidden lg:block' : ''}`}>
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
                <tr
                  key={rowKey(row)}
                  onClick={() => onRowClick?.(row)}
                  onKeyDown={
                    isClickable
                      ? (event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault()
                            onRowClick?.(row)
                          }
                        }
                      : undefined
                  }
                  role={isClickable ? 'button' : undefined}
                  tabIndex={isClickable ? 0 : undefined}
                  className={`border-b border-hairline transition-colors last:border-0 ${
                    isClickable ? 'cursor-pointer hover:bg-sidebar/60' : ''
                  }`}
                >
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

      {renderMobileCard && rows.length > 0 && (
        <div className="space-y-3 lg:hidden">
          {rows.map((row) => (
            <div
              key={rowKey(row)}
              onClick={() => onRowClick?.(row)}
              role={isClickable ? 'button' : undefined}
              tabIndex={isClickable ? 0 : undefined}
              className={`card p-4 ${isClickable ? 'cursor-pointer' : ''}`}
            >
              {renderMobileCard(row)}
            </div>
          ))}
        </div>
      )}
    </>
  )
}
