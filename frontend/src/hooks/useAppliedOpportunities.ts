import { useCallback, useEffect, useState } from 'react'
import { applicationService } from '@/services/applicationService'
import type { ApplicationStatus } from '@/types/application'
import type { OpportunityType } from '@/types/opportunity'

export type AppliedLookup = Map<string, ApplicationStatus>

function lookupKey(type: OpportunityType, id: number): string {
  return `${type}:${id}`
}

/**
 * Neither the opportunity list endpoint nor the single-opportunity
 * detail endpoint exposes any "has this student applied" field -
 * confirmed by reading OpportunityListResource/JobPostingResource/
 * InternshipResource. So this is built from the student's own real
 * Applications data (GET /student/applications, the same endpoint the
 * Applications page itself uses) instead of inventing a field or
 * tracking apply/withdraw as frontend-only state.
 *
 * Fetches once per mount at the endpoint's own per_page ceiling (100,
 * enforced server-side) - a student with more than 100 applications
 * (unlikely for a college placement portal) may see a small number of
 * already-applied rows not yet marked "Applied" until this refetches.
 * Harmless: re-applying to an opportunity that already has an
 * application row is safely rejected by ApplicationService::apply()
 * regardless (student_id+applicable unique, checked and DB-enforced),
 * so this is a rare display gap, never a duplicate-application bug.
 */
export function useAppliedOpportunities() {
  const [lookup, setLookup] = useState<AppliedLookup>(new Map())
  const [isLoading, setIsLoading] = useState(true)

  const reload = useCallback(() => {
    setIsLoading(true)
    applicationService
      .list({ per_page: 100 })
      .then((result) => {
        const next: AppliedLookup = new Map()
        for (const item of result.items) {
          if (item.opportunity) next.set(lookupKey(item.opportunity.type, item.opportunity.id), item.status)
        }
        setLookup(next)
      })
      .catch(() => setLookup(new Map()))
      .finally(() => setIsLoading(false))
  }, [])

  useEffect(() => {
    reload()
  }, [reload])

  const statusFor = useCallback((type: OpportunityType, id: number) => lookup.get(lookupKey(type, id)), [lookup])

  /** Called right after a successful apply, using the real POST response's own status - not a guess. */
  const markApplied = useCallback((type: OpportunityType, id: number, status: ApplicationStatus) => {
    setLookup((prev) => {
      const next = new Map(prev)
      next.set(lookupKey(type, id), status)
      return next
    })
  }, [])

  return { statusFor, markApplied, isLoading, reload }
}
