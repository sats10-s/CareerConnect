import { Briefcase, GraduationCap, Sparkles } from 'lucide-react'

interface FloatingCard {
  role: string
  company: string
  meta: string
}

/**
 * The landing hero's right-hand visual. No product screenshot or stock
 * photo exists for this yet, and the spec explicitly says not to reach
 * for a random external image URL - so this is a clean, self-contained
 * illustration built from the existing design tokens (a soft forest
 * gradient panel + a few floating "opportunity" cards), not a
 * placeholder <img>. The floating cards are presentational examples
 * only (per spec) - not wired to any API.
 */
const FLOATING_CARDS: FloatingCard[] = [
  { role: 'Software Engineer', company: 'TechNova', meta: '₹8–10 LPA' },
  { role: 'Data Analyst Intern', company: 'FinEdge', meta: '₹25k/mo' },
  { role: 'Product Design', company: 'Craftly', meta: '₹6–9 LPA' },
]

export function HeroVisual() {
  return (
    <div className="relative mx-auto aspect-square w-full max-w-md sm:max-w-lg">
      <div
        className="bg-grid absolute inset-4 overflow-hidden rounded-[28px] sm:inset-8"
        style={{
          background: 'linear-gradient(155deg, var(--color-surface-raised) 0%, var(--color-base) 100%)',
          boxShadow: 'var(--shadow-glow-lg)',
          border: '1px solid var(--color-hairline)',
        }}
      >
        <div className="flex h-full flex-col items-center justify-center gap-4 p-8 text-center">
          <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-tint text-primary">
            <GraduationCap className="h-8 w-8" strokeWidth={1.75} />
          </span>
          <p className="max-w-[16rem] text-sm font-medium leading-relaxed text-muted">
            Your placement journey, tracked end to end - applications,
            interviews, offers.
          </p>
          <span className="badge-eyebrow">
            <Sparkles className="h-3.5 w-3.5" />
            Verified opportunities only
          </span>
        </div>
      </div>

      {FLOATING_CARDS.map((card, index) => (
        <div
          key={card.role}
          className={`card absolute w-48 animate-fade-in p-3 ${FLOATING_POSITION[index]}`}
          style={{ animationDelay: `${index * 120}ms` }}
        >
          <div className="flex items-start gap-2.5">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-primary">
              <Briefcase className="h-4 w-4" />
            </span>
            <div className="min-w-0">
              <p className="truncate text-xs font-semibold text-ink">{card.role}</p>
              <p className="truncate text-[11px] text-muted">{card.company} · {card.meta}</p>
            </div>
          </div>
          <span className="mt-2 inline-block rounded-md bg-accent-tint px-2 py-0.5 text-[10px] font-semibold text-accent-str">
            Apply
          </span>
        </div>
      ))}
    </div>
  )
}

const FLOATING_POSITION = [
  '-left-2 top-6 sm:-left-6 sm:top-10',
  '-right-2 top-1/2 -translate-y-1/2 sm:-right-8',
  '-bottom-2 left-1/3 sm:-bottom-6',
]
