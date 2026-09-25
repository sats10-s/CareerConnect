import { useEffect, useState } from 'react'
import { CalendarClock, MapPin, Video } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { DataTable } from '@/components/ui/DataTable'
import { Pagination } from '@/components/ui/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { StatusBadge, toneForInterviewStatus, labelForInterviewStatus } from '@/components/ui/StatusBadge'
import { interviewService } from '@/services/interviewService'
import { formatDateTime } from '@/utils/formatRelativeTime'
import type { InterviewListItem } from '@/types/interview'
import type { PaginationMeta } from '@/types/common'

type WhenFilter = 'upcoming' | 'past'

const MODE_ICON = { ONLINE: Video, OFFLINE: MapPin, HYBRID: Video } as const

export function StudentInterviewsPage() {
  const [when, setWhen] = useState<WhenFilter>('upcoming')
  const [page, setPage] = useState(1)
  const [items, setItems] = useState<InterviewListItem[]>([])
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    setIsLoading(true)
    setError(null)
    interviewService
      .list({ when, page })
      .then((data) => {
        setItems(data.items)
        setMeta(data.meta)
      })
      .catch(() => setError("Couldn't load your interviews right now."))
      .finally(() => setIsLoading(false))
  }, [when, page, reloadKey])

  return (
    <div>
      <PageHeader title="Interviews" description="Rounds scheduled for your applications." />

      <Tabs
        items={[
          { key: 'upcoming', label: 'Upcoming' },
          { key: 'past', label: 'Past' },
        ]}
        active={when}
        onChange={(key) => {
          setWhen(key as WhenFilter)
          setPage(1)
        }}
      />

      <div className="mt-5">
        {isLoading ? (
          <div className="card p-5">
            <LoadingSkeletonLines count={4} className="h-14 w-full" />
          </div>
        ) : error ? (
          <div className="card">
            <ErrorState description={error} onRetry={() => setReloadKey((k) => k + 1)} />
          </div>
        ) : items.length === 0 ? (
          <div className="card">
            <EmptyState
              icon={CalendarClock}
              title={when === 'upcoming' ? 'No upcoming interviews' : 'No past interviews'}
              description={
                when === 'upcoming'
                  ? "You'll see interview rounds here as soon as a placement admin schedules one."
                  : "Completed and past interview rounds will show up here."
              }
            />
          </div>
        ) : (
          <>
            <DataTable
              columns={[
                {
                  key: 'opportunity',
                  header: 'Opportunity',
                  render: (row) => (
                    <div>
                      <p className="font-medium text-ink">{row.opportunity?.title ?? 'Opportunity'}</p>
                      <p className="text-xs text-muted">{row.opportunity?.company?.name}</p>
                    </div>
                  ),
                },
                { key: 'round', header: 'Round', render: (row) => `Round ${row.round}${row.title ? ` · ${row.title}` : ''}` },
                {
                  key: 'scheduled_at',
                  header: 'When',
                  render: (row) => (row.scheduled_at ? formatDateTime(row.scheduled_at) : '—'),
                },
                {
                  key: 'mode',
                  header: 'Mode',
                  render: (row) => {
                    const Icon = MODE_ICON[row.mode]
                    return (
                      <span className="inline-flex items-center gap-1.5 text-muted">
                        <Icon className="h-3.5 w-3.5" />
                        {row.mode.charAt(0) + row.mode.slice(1).toLowerCase()}
                      </span>
                    )
                  },
                },
                {
                  key: 'status',
                  header: 'Status',
                  render: (row) => <StatusBadge tone={toneForInterviewStatus(row.status)}>{labelForInterviewStatus(row.status)}</StatusBadge>,
                },
              ]}
              rows={items}
              rowKey={(row) => row.id}
              renderMobileCard={(row) => (
                <div>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate font-medium text-ink">{row.opportunity?.title ?? 'Opportunity'}</p>
                      <p className="truncate text-xs text-muted">{row.opportunity?.company?.name}</p>
                    </div>
                    <StatusBadge tone={toneForInterviewStatus(row.status)}>{labelForInterviewStatus(row.status)}</StatusBadge>
                  </div>
                  <p className="mt-2 text-xs text-muted">
                    Round {row.round} · {row.scheduled_at ? formatDateTime(row.scheduled_at) : 'Not scheduled'}
                  </p>
                </div>
              )}
            />

            {meta && meta.last_page > 1 && (
              <div className="mt-4">
                <Pagination page={meta.current_page} totalPages={meta.last_page} onChange={setPage} />
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
