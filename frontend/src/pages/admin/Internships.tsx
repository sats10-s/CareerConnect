import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { AssignmentList } from '@/components/admin/AssignmentList'
import { OpportunityList } from '@/components/admin/OpportunityList'

const TABS = [
  { key: 'postings', label: 'Postings' },
  { key: 'assignments', label: 'Assignments' },
]

/**
 * Placement Admin > Internships: internship postings (/admin/internships)
 * and the assignments that pair a selected student with a faculty
 * coordinator (/admin/internship-assignments). Assignments live here
 * because the admin nav has no separate entry for them.
 */
export function AdminInternshipsPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'assignments' ? 'assignments' : 'postings'

  return (
    <div className="animate-fade-in">
      <PageHeader title="Internships" description="Internship postings, and the faculty coordinators assigned to selected students." />
      <div className="mb-5">
        <Tabs items={TABS} active={tab} onChange={(key) => setParams(key === 'assignments' ? { tab: 'assignments' } : {}, { replace: true })} />
      </div>
      {tab === 'postings' ? <OpportunityList kind="internship" /> : <AssignmentList />}
    </div>
  )
}
