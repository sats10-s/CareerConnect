import { Lock } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import type { StudentProfileData } from '@/types/studentProfile'

/**
 * JNTU No, department, batch, year, CGPA, backlogs - all from the college's
 * own records (Super Admin's Student Data import). Read-only: the backend
 * rejects any attempt to change them from the student profile.
 */
export function AcademicSection({ profile }: { profile: StudentProfileData }) {
  return (
    <Card>
      <CardHeader
        title="Academic information"
        subtitle="Managed by your college. Contact the placement office if this is wrong."
        action={<Lock className="h-4 w-4 text-ink-faint" aria-label="From college records" />}
      />
      <div className="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-3">
        <ReadOnlyField label="JNTU No" value={profile.roll_number || '—'} />
        <ReadOnlyField label="Department" value={profile.department ?? '—'} />
        <ReadOnlyField label="Batch" value={profile.batch ?? '—'} />
        <ReadOnlyField label="Year" value={profile.year ? `Year ${profile.year}` : '—'} />
        <ReadOnlyField label="CGPA" value={profile.cgpa === null ? 'CGPA not yet available' : profile.cgpa.toFixed(2)} />
        <ReadOnlyField label="Backlogs" value={profile.backlogs === null ? 'Backlogs not reported' : String(profile.backlogs)} />
      </div>
    </Card>
  )
}

function ReadOnlyField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</p>
      <p className="mt-1 text-sm font-medium text-ink">{value}</p>
    </div>
  )
}
