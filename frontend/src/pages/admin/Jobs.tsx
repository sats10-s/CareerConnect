import { PageHeader } from '@/components/ui/PageHeader'
import { OpportunityList } from '@/components/admin/OpportunityList'

/** Placement Admin > Jobs - /api/admin/jobs (JobPostingController): list, create, edit and publish/close/archive job postings. */
export function AdminJobsPage() {
  return (
    <div className="animate-fade-in">
      <PageHeader title="Jobs" description="Job postings students can apply to. Drafts stay hidden until you publish them." />
      <OpportunityList kind="job" />
    </div>
  )
}
