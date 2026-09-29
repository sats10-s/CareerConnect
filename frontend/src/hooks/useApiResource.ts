import { useCallback, useEffect, useRef, useState } from 'react'
import { parseApiError } from '@/utils/apiError'

/**
 * Loads a single resource (a detail record, a summary object). Pass
 * `null` as the key to stay idle - used by drawers/modals that only
 * fetch once something is selected. Refetches when the key changes;
 * responses for a superseded key are dropped.
 */
export function useApiResource<T>(fetcher: () => Promise<T>, key: string | number | null) {
  const [data, setData] = useState<T | null>(null)
  const [isLoading, setIsLoading] = useState(key !== null)
  const [error, setError] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher
  const requestId = useRef(0)

  useEffect(() => {
    if (key === null) {
      setData(null)
      setIsLoading(false)
      setError(null)
      return
    }
    const id = ++requestId.current
    setIsLoading(true)
    setError(null)
    fetcherRef
      .current()
      .then((result) => {
        if (id === requestId.current) setData(result)
      })
      .catch((err: unknown) => {
        if (id === requestId.current) setError(parseApiError(err, 'Unable to load this right now.').message)
      })
      .finally(() => {
        if (id === requestId.current) setIsLoading(false)
      })
  }, [key, tick])

  const reload = useCallback(() => setTick((n) => n + 1), [])
  return { data, isLoading, error, reload }
}
