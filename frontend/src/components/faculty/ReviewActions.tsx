import { useState } from 'react'
import { CheckCircle2, MessageSquareWarning, PlayCircle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { parseApiError } from '@/utils/apiError'
import type { ReviewAction } from '@/services/facultyService'

interface ReviewActionsProps {
  /** Current status of the item being reviewed. */
  status: string
  /** What the item is called in copy ("report", "document"). */
  noun: string
  onReview: (action: ReviewAction, remarks?: string) => Promise<unknown>
}

/**
 * The review state machine that weekly reports and internship
 * documents share on the backend (WeeklyReportStatus /
 * InternshipDocumentStatus::canTransitionTo): SUBMITTED can only move
 * to UNDER_REVIEW; only UNDER_REVIEW can be APPROVED or sent back with
 * CHANGES_REQUESTED; CHANGES_REQUESTED goes back to the student. The
 * buttons offered mirror that, so faculty are never shown an action
 * the API will refuse - the API still enforces it regardless.
 *
 * "Request changes" requires remarks here (the API allows them to be
 * empty): sending something back with no explanation leaves the
 * student nothing to act on.
 */
export function ReviewActions({ status, noun, onReview }: ReviewActionsProps) {
  const [remarks, setRemarks] = useState('')
  const [pending, setPending] = useState<ReviewAction | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function submit(action: ReviewAction) {
    if (action === 'CHANGES_REQUESTED' && !remarks.trim()) {
      setError(`Tell the student what needs to change before sending this ${noun} back.`)
      return
    }
    setError(null)
    setPending(action)
    try {
      await onReview(action, remarks.trim() || undefined)
      setRemarks('')
      toast('success', action === 'UNDER_REVIEW' ? `Review started.` : action === 'APPROVED' ? `Approved.` : `Sent back to the student for changes.`)
    } catch (err) {
      setError(parseApiError(err, `Unable to update this ${noun}.`).message)
    } finally {
      setPending(null)
    }
  }

  if (status === 'SUBMITTED') {
    return (
      <div className="space-y-3">
        {error && <p role="alert" className="text-sm text-error">{error}</p>}
        <p className="text-sm text-ink-soft">Start a review to lock in that you are looking at this {noun}. You can approve it or request changes afterwards.</p>
        <Button onClick={() => submit('UNDER_REVIEW')} isLoading={pending === 'UNDER_REVIEW'}>
          <PlayCircle className="h-4 w-4" />
          Start review
        </Button>
      </div>
    )
  }

  if (status === 'UNDER_REVIEW') {
    return (
      <div className="space-y-3">
        <div>
          <label className="label" htmlFor="review-remarks">Remarks for the student</label>
          <Textarea id="review-remarks" rows={3} maxLength={2000} value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Optional for approval, required when requesting changes" />
        </div>
        {error && <p role="alert" className="text-sm text-error">{error}</p>}
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => submit('APPROVED')} isLoading={pending === 'APPROVED'} disabled={pending !== null}>
            <CheckCircle2 className="h-4 w-4" />
            Approve
          </Button>
          <Button variant="secondary" onClick={() => submit('CHANGES_REQUESTED')} isLoading={pending === 'CHANGES_REQUESTED'} disabled={pending !== null}>
            <MessageSquareWarning className="h-4 w-4" />
            Request changes
          </Button>
        </div>
      </div>
    )
  }

  const waiting: Record<string, string> = {
    DRAFT: `The student hasn't submitted this ${noun} yet.`,
    CHANGES_REQUESTED: `Sent back to the student. It returns here once they resubmit.`,
    APPROVED: `Approved - nothing further to do.`,
  }
  return <p className="text-sm text-ink-soft">{waiting[status] ?? 'No review actions are available.'}</p>
}
