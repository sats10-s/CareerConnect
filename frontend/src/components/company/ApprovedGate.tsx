import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Lock } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'

/**
 * Wraps recruiting pages (postings, applicants, interviews, team). The API
 * already returns 403 for a company that isn't APPROVED; this just shows a
 * clear message instead of an error state.
 */
export function ApprovedGate({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  if (user?.company?.status === 'APPROVED') return <>{children}</>

  return (
    <div className="card mx-auto mt-6 max-w-xl p-6 text-center sm:p-8">
      <span className="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-sidebar text-ink-soft">
        <Lock className="h-5 w-5" aria-hidden="true" />
      </span>
      <h2 className="mt-4 font-display text-lg font-semibold text-ink">Available once your company is approved</h2>
      <p className="mt-2 text-sm text-ink-soft">Postings, applicants, interviews and your team unlock after a Super Admin approves your company.</p>
      <Link to="/company/dashboard" className="btn-secondary mt-6 inline-flex">See your status</Link>
    </div>
  )
}
