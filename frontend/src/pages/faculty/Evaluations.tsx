import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs } from '@/components/ui/Tabs'
import { CompletionSection } from '@/components/faculty/CompletionSection'
import { PpoSection } from '@/components/faculty/PpoSection'

const TABS = [
  { key: 'completion', label: 'Completion' },
  { key: 'ppo', label: 'PPO offers' },
]

/**
 * Faculty > Evaluations. The backend has no standalone "evaluation"
 * resource; what it gives faculty for judging a finished internship is
 * the Internship Completion assessment (POST/PATCH/finalize under
 * /faculty/internship-assignments/{id}/completion) and the PPO record
 * for a pre-placement offer - so this page is those two, as tabs.
 */
export function FacultyEvaluationsPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'ppo' ? 'ppo' : 'completion'

  return (
    <div className="animate-fade-in">
      <PageHeader title="Evaluations" description="Record how each internship ended and any pre-placement offers that followed." />
      <div className="mb-5">
        <Tabs items={TABS} active={tab} onChange={(key) => setParams(key === 'ppo' ? { tab: 'ppo' } : {}, { replace: true })} />
      </div>
      {tab === 'completion' ? <CompletionSection /> : <PpoSection />}
    </div>
  )
}
