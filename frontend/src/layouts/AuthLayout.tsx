import { Outlet } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'

export function AuthLayout() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center text-center">
          <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-navy-800 text-white">
            <GraduationCap className="h-6 w-6" strokeWidth={2.2} />
          </span>
          <h1 className="mt-4 text-xl font-bold text-gray-900">CareerConnect</h1>
          <p className="mt-1 text-sm text-gray-500">Internship & Placement Management Portal</p>
        </div>
        <div className="card animate-fade-in p-6 sm:p-8">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
