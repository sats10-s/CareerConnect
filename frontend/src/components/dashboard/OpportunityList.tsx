import { Briefcase } from 'lucide-react'
import { SectionHeader } from '@/components/ui/SectionHeader'
import { Card } from '@/components/ui/Card'

export interface Opportunity {
  id: number
  title: string
  company: string
  type: 'job' | 'internship'
}

/**
 * Opportunity listings ARE genuinely discrete, comparable items, so
 * (per the design system) these get real card borders - unlike the
 * hairline-divided list sections elsewhere on this page.
 *
 * Fed by StudentDashboardPage from GET /student/opportunities (most
 * recently published, `per_page=3`, no type filter) - real data as of
 * the Jobs/Internships build-out. NOT eligibility- or department/batch-
 * matched: Api\Student\OpportunityController::index() has an explicit
 * product rule against filtering the listing by eligibility at all, so
 * this is honestly labeled "recently posted" rather than "matched to
 * you" - see the Jobs/Internships pages for the real eligibility view.
 */
export function OpportunityList({ opportunities = [] }: { opportunities?: Opportunity[] }) {
  return (
    <section>
      <SectionHeader title="Open opportunities" subtitle="Recently posted jobs and internships" />
      <div className="mt-3 space-y-3">
        {opportunities.length === 0 ? (
          <Card className="flex flex-col items-center gap-2 px-5 py-8 text-center">
            <Briefcase className="h-5 w-5 text-ink-faint" />
            <p className="text-sm text-ink-faint">No open opportunities to show yet.</p>
          </Card>
        ) : (
          opportunities.map((op) => (
            <Card key={op.id} className="flex items-center justify-between gap-4 px-5 py-4">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-ink">{op.title}</p>
                <p className="truncate text-xs text-ink-faint">
                  {op.company} · {op.type === 'internship' ? 'Internship' : 'Job'}
                </p>
              </div>
            </Card>
          ))
        )}
      </div>
    </section>
  )
}
