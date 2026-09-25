import { Link } from 'react-router-dom'
import { ArrowRight, GraduationCap } from 'lucide-react'
import { HeroVisual } from '@/components/marketing/HeroVisual'

/**
 * Public marketing landing page - previously didn't exist at all
 * (RootRedirect sent every guest straight to /login). Reachable at
 * "/" for unauthenticated visitors (see App.tsx's RootRedirect).
 *
 * "Explore Jobs" / "Explore Internships" both route to /login: there's
 * no public, unauthenticated opportunities endpoint (every
 * /student/opportunities route requires a logged-in student), so
 * sending guests anywhere else would either be a dead link or would
 * require inventing a backend capability that doesn't exist. Login is
 * the honest, working destination for "I want to see what's on offer."
 */
const STATS = [
  { value: '12,500+', label: 'Students' },
  { value: '250+', label: 'Companies' },
  { value: '1,200+', label: 'Opportunities' },
]

export function LandingPage() {
  return (
    <div className="animate-fade-in min-h-screen bg-base">
      <header className="border-b border-hairline">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
          <span className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-[color:var(--color-primary-ink)]">
              <GraduationCap className="h-[18px] w-[18px]" strokeWidth={2.2} />
            </span>
            <span className="text-sm font-bold text-ink">CareerConnect</span>
          </span>

          <nav className="flex items-center gap-2">
            <Link to="/login" className="btn-secondary">Sign in</Link>
            <Link to="/register" className="btn-primary hidden sm:inline-flex">Create account</Link>
          </nav>
        </div>
      </header>

      <main>
        <section className="bg-grid relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-8 lg:py-24">
          <div>
            <span className="badge-eyebrow">
              Internship &amp; Placement Portal
            </span>

            <h1 className="mt-5 font-extrabold text-4xl leading-[1.08] tracking-tight text-ink sm:text-5xl lg:text-6xl">
              Build Your Career With The <span className="text-accent">Right</span> Opportunity.
            </h1>

            <p className="mt-5 max-w-lg text-base leading-relaxed text-muted sm:text-lg">
              Discover verified internships and placement opportunities through your institution.
            </p>

            <div className="mt-8 flex flex-wrap items-center gap-3">
              <Link to="/login" className="btn-primary">
                Explore Jobs
                <ArrowRight className="h-4 w-4" />
              </Link>
              <Link to="/login" className="btn-accent">
                Explore Internships
                <ArrowRight className="h-4 w-4" />
              </Link>
            </div>
          </div>

          <HeroVisual />
        </section>

        <section className="border-t border-hairline bg-surface">
          <div className="mx-auto grid max-w-6xl grid-cols-3 gap-6 px-4 py-10 sm:px-6">
            {STATS.map((stat) => (
              <div key={stat.label} className="text-center">
                <p className="text-2xl font-bold tabular-nums tracking-tight text-primary sm:text-3xl">{stat.value}</p>
                <p className="mt-1 text-xs font-medium text-muted sm:text-sm">{stat.label}</p>
              </div>
            ))}
          </div>
        </section>
      </main>
    </div>
  )
}
