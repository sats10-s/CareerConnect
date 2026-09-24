import { Outlet } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'

export function AuthLayout() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center text-center">
          <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-white">
            <GraduationCap className="h-6 w-6" strokeWidth={2.2} />
          </span>
          <h1 className="mt-4 font-serif text-xl font-bold text-ink">CareerConnect</h1>
          <p className="mt-1 text-sm text-ink-soft">Internship & Placement Management Portal</p>
        </div>
        <div className="card animate-fade-in p-6 sm:p-8">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
