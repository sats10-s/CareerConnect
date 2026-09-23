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
 * NOTE: the current /student/dashboard API response has no opportunity
 * feed (that's a separate Jobs/Internships listing endpoint, out of
 * scope for this presentation-only pass - no backend/API changes were
 * made). This component is built and composed per spec, but renders
 * its honest empty state rather than fabricated postings until that
 * data is wired up.
 */
export function OpportunityList({ opportunities = [] }: { opportunities?: Opportunity[] }) {
  return (
    <section>
      <SectionHeader title="Open opportunities" subtitle="Roles matched to your department and batch" />
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
