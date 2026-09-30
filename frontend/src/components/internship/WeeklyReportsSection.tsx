import { useEffect, useState } from 'react'
import { isAxiosError } from 'axios'
import { AlertTriangle, FileText, Plus } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { Modal } from '@/components/ui/Modal'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { toast } from '@/components/ui/Toast'
import { weeklyReportService } from '@/services/weeklyReportService'
import type { WeeklyReportDetail, WeeklyReportListItem, WeeklyReportStatus } from '@/types/weeklyReport'

const EDITABLE_STATUSES: WeeklyReportStatus[] = ['DRAFT', 'CHANGES_REQUESTED']

const STATUS_TONE: Record<WeeklyReportStatus, 'neutral' | 'info' | 'warning' | 'success'> = {
  DRAFT: 'neutral',
  SUBMITTED: 'info',
  UNDER_REVIEW: 'info',
  CHANGES_REQUESTED: 'warning',
  APPROVED: 'success',
}

function addDays(dateString: string, days: number): string {
  const date = new Date(dateString + 'T00:00:00')
  date.setDate(date.getDate() + days)
  return date.toISOString().slice(0, 10)
}

function extractMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}

export function WeeklyReportsSection({ assignmentId }: { assignmentId: number }) {
  const [reports, setReports] = useState<WeeklyReportListItem[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [isCreating, setIsCreating] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [weekStart, setWeekStart] = useState('')
  const [workDone, setWorkDone] = useState('')
  const [learningOutcomes, setLearningOutcomes] = useState('')
  const [challenges, setChallenges] = useState('')
  const [nextWeekPlan, setNextWeekPlan] = useState('')

  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [detail, setDetail] = useState<WeeklyReportDetail | null>(null)
  const [isLoadingDetail, setIsLoadingDetail] = useState(false)
  const [isSubmittingDetail, setIsSubmittingDetail] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)

  function load() {
    setIsLoading(true)
    setLoadError(false)
    weeklyReportService
      .forAssignment(assignmentId)
      .then(setReports)
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }

  useEffect(load, [assignmentId])

  useEffect(() => {
    if (selectedId === null) {
      setDetail(null)
      return
    }
    setIsLoadingDetail(true)
    setDetailError(null)
    weeklyReportService
      .show(selectedId)
      .then((data) => {
        setDetail(data)
        setWorkDone(data.work_done)
        setLearningOutcomes(data.learning_outcomes ?? '')
        setChallenges(data.challenges ?? '')
        setNextWeekPlan(data.next_week_plan ?? '')
      })
      .finally(() => setIsLoadingDetail(false))
  }, [selectedId])

  function openCreate() {
    const suggestedStart = reports.length > 0 ? addDays(reports[0].week_end_date, 1) : new Date().toISOString().slice(0, 10)
    setWeekStart(suggestedStart)
    setWorkDone('')
    setLearningOutcomes('')
    setChallenges('')
    setNextWeekPlan('')
    setCreateError(null)
    setIsCreating(true)
  }

  function handleCreate() {
    if (!weekStart || !workDone.trim()) return
    const nextWeekNumber = reports.length > 0 ? Math.max(...reports.map((r) => r.week_number)) + 1 : 1
    setIsSaving(true)
    setCreateError(null)
    weeklyReportService
      .create(assignmentId, {
        week_number: nextWeekNumber,
        week_start_date: weekStart,
        week_end_date: addDays(weekStart, 6),
        work_done: workDone.trim(),
        learning_outcomes: learningOutcomes.trim() || undefined,
        challenges: challenges.trim() || undefined,
        next_week_plan: nextWeekPlan.trim() || undefined,
      })
      .then(() => {
        toast('success', 'Weekly report draft saved.')
        setIsCreating(false)
        load()
      })
      .catch((error) => setCreateError(extractMessage(error, "Couldn't save this report. Please check the week dates and try again.")))
      .finally(() => setIsSaving(false))
  }

  function handleSaveDraft() {
    if (!detail) return
    setIsSubmittingDetail(true)
    setDetailError(null)
    weeklyReportService
      .update(detail.id, {
        work_done: workDone.trim() || undefined,
        learning_outcomes: learningOutcomes.trim() || undefined,
        challenges: challenges.trim() || undefined,
        next_week_plan: nextWeekPlan.trim() || undefined,
      })
      .then((updated) => {
        setDetail(updated)
        toast('success', 'Draft saved.')
        load()
      })
      .catch((error) => setDetailError(extractMessage(error, "Couldn't save your changes.")))
      .finally(() => setIsSubmittingDetail(false))
  }

  function handleSubmitReport() {
    if (!detail) return
    setIsSubmittingDetail(true)
    setDetailError(null)
    weeklyReportService
      .update(detail.id, {
        work_done: workDone.trim() || undefined,
        learning_outcomes: learningOutcomes.trim() || undefined,
        challenges: challenges.trim() || undefined,
        next_week_plan: nextWeekPlan.trim() || undefined,
      })
      .then(() => weeklyReportService.submit(detail.id))
      .then(() => {
        toast('success', `Week ${detail.week_number} report submitted.`)
        setSelectedId(null)
        load()
      })
      .catch((error) => setDetailError(extractMessage(error, "Couldn't submit this report.")))
      .finally(() => setIsSubmittingDetail(false))
  }

  const isDetailEditable = detail ? EDITABLE_STATUSES.includes(detail.status) : false

  return (
    <section className="space-y-4">
      <SectionHeader
        title="Weekly progress reports"
        action={
          <Button variant="secondary" onClick={openCreate}>
            <Plus className="h-4 w-4" />
            New report
          </Button>
        }
      />

      {isLoading ? (
        <LoadingSkeletonLines count={3} className="h-14 w-full" />
      ) : loadError ? (
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Couldn't load weekly reports"
            description="Something went wrong reaching the server."
            action={
              <Button variant="secondary" onClick={load}>
                Retry
              </Button>
            }
          />
        </div>
      ) : reports.length === 0 ? (
        <div className="card">
          <EmptyState icon={FileText} title="No weekly reports yet" description="Submit a report for each week of your internship - your coordinator reviews it here." />
        </div>
      ) : (
        <div className="card divide-y divide-hairline">
          {reports.map((report) => (
            <button
              key={report.id}
              type="button"
              onClick={() => setSelectedId(report.id)}
              className="flex w-full items-center justify-between gap-4 px-5 py-3 text-left transition-colors hover:bg-sidebar/60"
            >
              <div>
                <p className="text-sm font-medium text-ink">Week {report.week_number}</p>
                <p className="text-xs text-ink-faint">
                  {report.week_start_date} – {report.week_end_date}
                </p>
              </div>
              <StatusBadge tone={STATUS_TONE[report.status]}>{report.status.replace('_', ' ')}</StatusBadge>
            </button>
          ))}
        </div>
      )}

      <Modal open={isCreating} onClose={() => setIsCreating(false)} title="New weekly report">
        <div className="space-y-4">
          {createError && <p className="rounded-lg bg-error-bg p-3 text-sm text-error">{createError}</p>}
          <div>
            <label className="mb-1 block text-xs font-medium text-ink-soft">Week starting</label>
            <Input type="date" value={weekStart} onChange={(event) => setWeekStart(event.target.value)} />
            {weekStart && <p className="mt-1 text-xs text-ink-faint">Week ends {addDays(weekStart, 6)} (7-day week, auto-calculated)</p>}
          </div>
          <Textarea value={workDone} onChange={(event) => setWorkDone(event.target.value)} placeholder="What did you work on this week? (required)" rows={4} />
          <Textarea value={learningOutcomes} onChange={(event) => setLearningOutcomes(event.target.value)} placeholder="Learning outcomes (optional)" rows={2} />
          <Textarea value={challenges} onChange={(event) => setChallenges(event.target.value)} placeholder="Challenges (optional)" rows={2} />
          <Textarea value={nextWeekPlan} onChange={(event) => setNextWeekPlan(event.target.value)} placeholder="Plan for next week (optional)" rows={2} />
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setIsCreating(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleCreate} isLoading={isSaving} disabled={!weekStart || !workDone.trim()}>
            Save draft
          </Button>
        </div>
      </Modal>

      <Modal open={selectedId !== null} onClose={() => setSelectedId(null)} title={detail ? `Week ${detail.week_number}` : 'Weekly report'}>
        {isLoadingDetail || !detail ? (
          <LoadingSkeletonLines count={4} className="h-4 w-full" />
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              <StatusBadge tone={STATUS_TONE[detail.status]}>{detail.status.replace('_', ' ')}</StatusBadge>
              <span className="text-xs text-ink-faint">
                {detail.week_start_date} – {detail.week_end_date}
              </span>
            </div>

            {detailError && <p className="rounded-lg bg-error-bg p-3 text-sm text-error">{detailError}</p>}

            {detail.status === 'CHANGES_REQUESTED' && detail.faculty_review_remarks && (
              <div className="rounded-lg bg-warning-bg p-3 text-sm text-warning">
                <p className="font-medium">Changes requested:</p>
                <p>{detail.faculty_review_remarks}</p>
              </div>
            )}
            {detail.status === 'APPROVED' && detail.faculty_review_remarks && (
              <div className="rounded-lg bg-success-bg p-3 text-sm text-success">{detail.faculty_review_remarks}</div>
            )}

            {isDetailEditable ? (
              <>
                <Textarea value={workDone} onChange={(event) => setWorkDone(event.target.value)} placeholder="Work done" rows={4} />
                <Textarea value={learningOutcomes} onChange={(event) => setLearningOutcomes(event.target.value)} placeholder="Learning outcomes" rows={2} />
                <Textarea value={challenges} onChange={(event) => setChallenges(event.target.value)} placeholder="Challenges" rows={2} />
                <Textarea value={nextWeekPlan} onChange={(event) => setNextWeekPlan(event.target.value)} placeholder="Plan for next week" rows={2} />
                <div className="flex justify-end gap-2">
                  <Button variant="secondary" onClick={handleSaveDraft} isLoading={isSubmittingDetail}>
                    Save draft
                  </Button>
                  <Button onClick={handleSubmitReport} isLoading={isSubmittingDetail} disabled={!workDone.trim()}>
                    Submit
                  </Button>
                </div>
              </>
            ) : (
              <dl className="space-y-3 text-sm">
                <div>
                  <dt className="text-xs font-semibold uppercase tracking-wide text-ink-soft">Work done</dt>
                  <dd className="mt-1 whitespace-pre-line text-ink">{detail.work_done}</dd>
                </div>
                {detail.learning_outcomes && (
                  <div>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-ink-soft">Learning outcomes</dt>
                    <dd className="mt-1 whitespace-pre-line text-ink">{detail.learning_outcomes}</dd>
                  </div>
                )}
                {detail.challenges && (
                  <div>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-ink-soft">Challenges</dt>
                    <dd className="mt-1 whitespace-pre-line text-ink">{detail.challenges}</dd>
                  </div>
                )}
                {detail.next_week_plan && (
                  <div>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-ink-soft">Plan for next week</dt>
                    <dd className="mt-1 whitespace-pre-line text-ink">{detail.next_week_plan}</dd>
                  </div>
                )}
              </dl>
            )}
          </div>
        )}
      </Modal>
    </section>
  )
}
