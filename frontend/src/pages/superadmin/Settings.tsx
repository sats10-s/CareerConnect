import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { toast } from '@/components/ui/Toast'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { useApiResource } from '@/hooks/useApiResource'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import type { InstitutionSettings } from '@/types/superAdmin'

interface FormValues {
  institution_name: string
  support_email: string
  academic_year: string
  allow_student_registration: boolean
  allow_company_registration: boolean
  company_postings_require_review: boolean
  attendance_threshold: string
}

const toForm = (s: InstitutionSettings): FormValues => ({
  institution_name: s.institution_name,
  support_email: s.support_email ?? '',
  academic_year: s.academic_year ?? '',
  allow_student_registration: s.allow_student_registration,
  allow_company_registration: s.allow_company_registration,
  company_postings_require_review: s.company_postings_require_review,
  attendance_threshold: String(s.attendance_threshold),
})

function SettingsSection({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <section className="grid grid-cols-1 gap-4 border-b border-hairline py-6 first:pt-0 last:border-0 md:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] md:gap-8">
      <div>
        <h2 className="text-sm font-semibold text-ink">{title}</h2>
        <p className="mt-1 text-sm text-ink-faint">{description}</p>
      </div>
      <div className="space-y-4">{children}</div>
    </section>
  )
}

function Switch({ id, checked, onChange, label, description }: { id: string; checked: boolean; onChange: (v: boolean) => void; label: string; description: string }) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-xl border border-hairline p-4">
      <div>
        <label htmlFor={id} className="text-sm font-medium text-ink">{label}</label>
        <p id={`${id}-desc`} className="mt-0.5 text-xs text-ink-faint">{description}</p>
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-describedby={`${id}-desc`}
        onClick={() => onChange(!checked)}
        className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-fast focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface ${checked ? 'bg-primary' : 'bg-border'}`}
      >
        <span className={`inline-block h-5 w-5 rounded-full bg-white shadow transition-transform duration-fast ${checked ? 'translate-x-[22px]' : 'translate-x-0.5'}`} />
      </button>
    </div>
  )
}

/**
 * Super Admin > Settings (GET/PATCH /super-admin/settings). Only changed
 * fields are sent; the server validates, stores and audits the diff.
 */
export function SuperAdminSettingsPage() {
  const { data, isLoading, error, reload } = useApiResource(() => superAdminService.settings.get(), 'super-admin-settings')
  const [saved, setSaved] = useState<FormValues | null>(null)
  const [form, setForm] = useState<FormValues | null>(null)
  const [busy, setBusy] = useState(false)
  const [banner, setBanner] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (data) {
      const values = toForm(data)
      setSaved(values)
      setForm(values)
    }
  }, [data])

  if (isLoading && !form) return <DashboardLoading stats={0} />
  if (error || !form || !saved) return <DashboardError message={error} onRetry={reload} />

  const set = <K extends keyof FormValues>(key: K, value: FormValues[K]) => setForm((f) => (f ? { ...f, [key]: value } : f))
  const changedKeys = (Object.keys(form) as (keyof FormValues)[]).filter((k) => form[k] !== saved[k])
  const dirty = changedKeys.length > 0

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!form) return
    setBanner(null)
    const local: Record<string, string> = {}
    if (!form.institution_name.trim()) local.institution_name = 'Enter the institution name.'
    if (form.support_email.trim() && !/^\S+@\S+\.\S+$/.test(form.support_email.trim())) local.support_email = 'Enter a valid email address.'
    if (form.academic_year.trim() && !/^\d{4}-\d{2,4}$/.test(form.academic_year.trim())) local.academic_year = 'Use the format 2026-27 or 2026-2027.'
    const threshold = Number(form.attendance_threshold)
    if (!Number.isInteger(threshold) || threshold < 1 || threshold > 100) local.attendance_threshold = 'Enter a whole number from 1 to 100.'
    setErrors(local)
    if (Object.keys(local).length) return

    const payload: Partial<InstitutionSettings> = {}
    for (const key of changedKeys) {
      if (key === 'institution_name') payload.institution_name = form.institution_name.trim()
      if (key === 'support_email') payload.support_email = form.support_email.trim() || null
      if (key === 'academic_year') payload.academic_year = form.academic_year.trim() || null
      if (key === 'allow_student_registration') payload.allow_student_registration = form.allow_student_registration
      if (key === 'allow_company_registration') payload.allow_company_registration = form.allow_company_registration
      if (key === 'company_postings_require_review') payload.company_postings_require_review = form.company_postings_require_review
      if (key === 'attendance_threshold') payload.attendance_threshold = threshold
    }
    setBusy(true)
    try {
      const { values, message } = await superAdminService.settings.update(payload)
      const next = toForm(values)
      setSaved(next)
      setForm(next)
      toast('success', message || 'Settings saved.')
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save settings.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="animate-fade-in">
      <PageHeader title="Settings" description="Institution-wide settings. Every change is recorded in the audit log." />

      <form onSubmit={submit} noValidate className="card p-5 md:p-6">
        {banner && <div role="alert" className="mb-5 rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>}

        <div>
        <SettingsSection title="Institution" description="Shown on the sign-up page and the Super Admin overview.">
          <div>
            <label className="label" htmlFor="s-name">Institution name</label>
            <Input id="s-name" maxLength={120} value={form.institution_name} onChange={(e) => set('institution_name', e.target.value)} error={errors.institution_name} />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="s-email">Support email <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="s-email" type="email" maxLength={255} placeholder="placements@college.edu" value={form.support_email} onChange={(e) => set('support_email', e.target.value)} error={errors.support_email} />
            </div>
            <div>
              <label className="label" htmlFor="s-year">Current academic year <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="s-year" maxLength={9} placeholder="2026-27" value={form.academic_year} onChange={(e) => set('academic_year', e.target.value)} error={errors.academic_year} />
            </div>
          </div>
        </SettingsSection>

        <SettingsSection title="Registration" description="Students create their own accounts; staff accounts are added from Users.">
          <Switch
            id="s-reg"
            checked={form.allow_student_registration}
            onChange={(v) => set('allow_student_registration', v)}
            label="Allow student self-registration"
            description={form.allow_student_registration ? 'Anyone with a college email can create a student account.' : 'The sign-up page is closed; new students must contact the placement office.'}
          />
        </SettingsSection>

        <SettingsSection title="Companies" description="Recruiters can register themselves; a Super Admin approves each one under Company Approvals. The posting review rule applies once recruiters can post from their portal.">
          <Switch
            id="s-company-reg"
            checked={form.allow_company_registration}
            onChange={(v) => set('allow_company_registration', v)}
            label="Allow company self-registration"
            description={form.allow_company_registration ? 'Recruiters can register from the public site; new companies wait for approval.' : 'The company sign-up page is closed; the placement office adds companies itself.'}
          />
          <Switch
            id="s-company-review"
            checked={form.company_postings_require_review}
            onChange={(v) => set('company_postings_require_review', v)}
            label="Company postings need Placement Admin review"
            description={form.company_postings_require_review ? 'Jobs a recruiter creates stay unpublished until a Placement Admin publishes them.' : 'Approved companies can publish their own jobs directly.'}
          />
        </SettingsSection>

        <SettingsSection title="Internships" description="Defaults used by placement reports.">
          <div className="max-w-xs">
            <label className="label" htmlFor="s-att">Attendance alert threshold</label>
            <div className="relative">
              <Input id="s-att" inputMode="numeric" maxLength={3} value={form.attendance_threshold} onChange={(e) => set('attendance_threshold', e.target.value.replace(/\D/g, ''))} error={errors.attendance_threshold} className="pr-9" />
              <span className="pointer-events-none absolute right-3 top-2.5 text-sm text-ink-faint">%</span>
            </div>
            <p className="mt-1 text-xs text-ink-faint">Interns below this attendance are flagged in Reports by default.</p>
          </div>
        </SettingsSection>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-5">
          {dirty && <p className="mr-auto text-xs text-warning" aria-live="polite">{changedKeys.length} unsaved change{changedKeys.length === 1 ? '' : 's'}</p>}
          <Button type="button" variant="secondary" disabled={!dirty || busy} onClick={() => { setForm(saved); setErrors({}); setBanner(null) }}>Discard</Button>
          <Button type="submit" isLoading={busy} disabled={!dirty}>Save settings</Button>
        </div>
      </form>
    </div>
  )
}
