import { Outlet } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'
import { AuthVisualPanel } from '@/components/marketing/AuthVisualPanel'

/**
 * Frontend Phase 1: split-screen auth shell (campus visual left / auth
 * card right), replacing the previous single centered card. Shared by
 * both /login and /register - the visual panel collapses below `lg`
 * so it never squeezes Register's longer form on narrower screens,
 * and the right column scrolls independently so that form isn't
 * constrained to viewport height.
 */
export function AuthLayout() {
  return (
    <div className="grid min-h-screen bg-base lg:grid-cols-2">
      <AuthVisualPanel />

      <div className="flex min-h-screen flex-col items-center justify-center overflow-y-auto px-4 py-10 sm:px-8">
        <div className="w-full max-w-md">
          <div className="mb-8 flex flex-col items-center text-center lg:hidden">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-[color:var(--color-primary-ink)]">
              <GraduationCap className="h-6 w-6" strokeWidth={2.2} />
            </span>
            <h1 className="mt-4 text-xl font-bold text-ink">CareerConnect</h1>
            <p className="mt-1 text-sm text-muted">Internship &amp; Placement Management Portal</p>
          </div>

          <div className="card animate-fade-in p-6 sm:p-8">
            <Outlet />
          </div>
        </div>
      </div>
    </div>
  )
}
