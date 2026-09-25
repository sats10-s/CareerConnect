const DATE_FORMATTER = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric' })

function timeOfDayGreeting(date: Date): string {
  const hour = date.getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 17) return 'Good afternoon'
  return 'Good evening'
}

interface WelcomeHeaderProps {
  /** The authenticated user's full name - only the first name is used in the greeting itself. Never hardcode a demo name here. */
  name: string
  department?: string | null
  batch?: string | null
}

/**
 * Masthead line (date + department + batch - a functional editorial
 * device, small and muted, not decoration) above the greeting. The
 * greeting is the ONE serif-italic accent moment in the whole app -
 * 'Newsreader' italic, used nowhere else - and always uses the real
 * logged-in user's first name.
 */
export function WelcomeHeader({ name, department, batch }: WelcomeHeaderProps) {
  const firstName = name.trim().split(/\s+/)[0] ?? name
  const masthead = [DATE_FORMATTER.format(new Date()), department, batch ? `Batch ${batch}` : null]
    .filter(Boolean)
    .join(' · ')

  return (
    <header>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{masthead}</p>
      <h1 className="font-extrabold tracking-tight mt-1 text-3xl text-ink sm:text-4xl">
        {timeOfDayGreeting(new Date())}, {firstName}
      </h1>
    </header>
  )
}
