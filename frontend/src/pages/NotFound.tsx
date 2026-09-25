import { Link } from 'react-router-dom'
import { CompassIcon } from 'lucide-react'

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-base px-4 text-center">
      <CompassIcon className="h-10 w-10 text-navy-700" />
      <h1 className="mt-4 text-2xl font-bold text-ink">Page not found</h1>
      <p className="mt-1 text-sm text-muted">The page you're looking for doesn't exist.</p>
      <Link to="/" className="btn-primary mt-6">Go home</Link>
    </div>
  )
}
