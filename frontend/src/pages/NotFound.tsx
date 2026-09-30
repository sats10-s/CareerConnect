import { Link } from 'react-router-dom'
import { CompassIcon } from 'lucide-react'

export function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-base px-4 text-center">
      <span className="flex h-16 w-16 items-center justify-center rounded-full bg-primary-tint text-primary">
        <CompassIcon className="h-8 w-8" aria-hidden="true" />
      </span>
      <p className="badge-eyebrow mt-6">404</p>
      <h1 className="mt-3 font-display text-2xl font-bold tracking-tight text-ink">Page not found</h1>
      <p className="mt-1 text-sm text-muted">The page you're looking for doesn't exist.</p>
      <Link to="/" className="btn-primary mt-6">Go home</Link>
    </div>
  )
}
