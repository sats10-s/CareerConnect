import { SectionHeader } from '@/components/ui/SectionHeader'
import { StatusBadge, toneForApplicationStatus } from '@/components/ui/StatusBadge'
import type { StudentDashboardData } from '@/types/dashboard'

/**
 * List content (applications) uses hairline dividers + a section
 * header, not a bordered card - per the design system, card borders
 * are reserved for genuinely discrete comparable items like
 * opportunity listings.
 */
export function ApplicationActivity({ applications }: { applications: StudentDashboardData['recent_applications'] }) {
  return (
    <section>
      <SectionHeader title="Recent applications" subtitle="Your latest job & internship applications" />
      <div className="divide-y divide-border">
        {applications.length === 0 && (
          <p className="py-8 text-center text-sm text-ink-faint">No applications yet — browse Jobs or Internships to get started.</p>
        )}
        {applications.map((app) => (
          <div key={app.id} className="flex items-center justify-between gap-4 py-3.5">
            <div className="min-w-0">
              <p className="truncate text-sm font-medium text-ink">{app.title ?? 'Untitled opportunity'}</p>
              <p className="truncate text-xs text-ink-faint">
                {app.company} · {app.type === 'internship' ? 'Internship' : 'Job'}
              </p>
            </div>
            <StatusBadge tone={toneForApplicationStatus(app.status)}>{app.status}</StatusBadge>
          </div>
        ))}
      </div>
    </section>
  )
}
