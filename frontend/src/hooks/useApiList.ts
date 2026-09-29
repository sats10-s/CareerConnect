import { useCallback, useEffect, useRef, useState } from 'react'
import type { PaginatedList, PaginationMeta } from '@/types/common'
import { parseApiError } from '@/utils/apiError'

/**
 * Server-paginated list state for the {items, meta} envelope every
 * list endpoint in this backend returns. Filters go in as a plain
 * object: changing any of them (compared by value) resets to page 1
 * and refetches, so callers just wire inputs to state - no effect,
 * timer or "reset page" bookkeeping per screen. Stale responses from
 * a superseded request are dropped, so fast filter changes can never
 * paint older data over newer data.
 */
export function useApiList<T, F extends object>(
  fetcher: (params: F & { page: number; per_page: number }) => Promise<PaginatedList<T>>,
  filters: F,
  perPage = 15,
) {
  const filterKey = JSON.stringify(filters)
  const [pageState, setPageState] = useState({ key: filterKey, page: 1 })
  const page = pageState.key === filterKey ? pageState.page : 1

  const [items, setItems] = useState<T[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [tick, setTick] = useState(0)

  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher
  const requestId = useRef(0)

  useEffect(() => {
    const id = ++requestId.current
    setIsLoading(true)
    setError(null)
    fetcherRef
      .current({ ...(JSON.parse(filterKey) as F), page, per_page: perPage })
      .then((data) => {
        if (id !== requestId.current) return
        setItems(data.items)
        setMeta(data.meta)
      })
      .catch((err: unknown) => {
        if (id !== requestId.current) return
        setError(parseApiError(err, 'Unable to load this list right now.').message)
      })
      .finally(() => {
        if (id === requestId.current) setIsLoading(false)
      })
  }, [filterKey, page, perPage, tick])

  const setPage = useCallback((next: number) => setPageState({ key: filterKey, page: next }), [filterKey])
  const reload = useCallback(() => setTick((n) => n + 1), [])

  return { items, meta, page, setPage, isLoading, error, reload }
}
