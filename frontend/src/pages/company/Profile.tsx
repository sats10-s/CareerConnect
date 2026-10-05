import { useEffect, useState, type FormEvent } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { DashboardError, DashboardLoading } from '@/components/dashboard/DashboardStatus'
import { useApiResource } from '@/hooks/useApiResource'
import { useAuth } from '@/hooks/useAuth'
import { companyService, type CompanyProfilePayload } from '@/services/companyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { COMPANY_SIZES, COMPANY_STATUS, metaFor } from '@/utils/statusMeta'
import type { CompanyRecord } from '@/types/admin'

const FIELDS = ['name', 'industry', 'website', 'company_size', 'headquarters', 'registration_number', 'description', 'contact_name', 'contact_email', 'contact_phone'] as const
type Field = (typeof FIELDS)[number]
type FormValues = Record<Field, string>

const toForm = (c: CompanyRecord): FormValues =>
  Object.fromEntries(FIELDS.map((field) => [field, (c[field] as string | null) ?? ''])) as FormValues

/**
 * /company/profile - PATCH /company/profile. Editable while the company is
 * pending, so a recruiter can correct details before (or after) review.
 * Status and review fields are read-only here.
 */
export function CompanyProfilePage() {
  const { refresh } = useAuth()
  const { data, isLoading, error, reload } = useApiResource(companyService.me, 'company-profile')
  const [saved, setSaved] = useState<FormValues | null>(null)
  const [form, setForm] = useState<FormValues | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (data) {
      setSaved(toForm(data))
      setForm(toForm(data))
    }
  }, [data])

  if (isLoading && !data) return <DashboardLoading stats={0} />
  if (error || !data || !form || !saved) return <DashboardError message={error} onRetry={reload} />

  const changed = FIELDS.filter((field) => form[field] !== saved[field])
  const meta = metaFor(COMPANY_STATUS, data.status)
  const set = (field: Field, value: string) => setForm((prev) => (prev ? { ...prev, [field]: value } : prev))

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!form) return
    setBanner(null)
    const local: Record<string, string> = {}
    if (!form.name.trim()) local.name = 'Enter your company name.'
    if (!form.contact_name.trim()) local.contact_name = 'Enter the contact person’s name.'
    if (!form.contact_email.trim()) local.contact_email = 'Enter a contact email.'
    setErrors(local)
    if (Object.keys(local).length) return

    const payload: CompanyProfilePayload = {}
    for (const field of changed) {
      const value = form[field].trim()
      ;(payload as Record<string, string | null>)[field] = value || null
    }
    setBusy(true)
    try {
      const next = toForm(await companyService.updateProfile(payload))
      setSaved(next)
      setForm(next)
      toast('success', 'Company profile saved.')
      if (changed.includes('name')) void refresh()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save your profile.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  const label = (text: string, optional = true) => (
    <>
      {text} {optional && <span className="font-normal text-ink-faint">(optional)</span>}
    </>
  )

  return (
    <div className="animate-fade-in">
      <PageHeader title="Company Profile" description="What the placement office and students will see about your company." action={<StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>} />

      <form onSubmit={submit} noValidate className="card space-y-6 p-5 md:p-6">
        {banner && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>}

        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-ink">Company</h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="label" htmlFor="cp-name">{label('Company name', false)}</label>
              <Input id="cp-name" maxLength={255} value={form.name} onChange={(e) => set('name', e.target.value)} error={errors.name} />
            </div>
            <div>
              <label className="label" htmlFor="cp-industry">{label('Industry')}</label>
              <Input id="cp-industry" maxLength={100} value={form.industry} onChange={(e) => set('industry', e.target.value)} error={errors.industry} />
            </div>
            <div>
              <label className="label" htmlFor="cp-website">{label('Website')}</label>
              <Input id="cp-website" type="url" maxLength={255} placeholder="https://example.com" value={form.website} onChange={(e) => set('website', e.target.value)} error={errors.website} />
            </div>
            <div>
              <label className="label" htmlFor="cp-size">{label('Company size')}</label>
              <Select id="cp-size" value={form.company_size} onChange={(e) => set('company_size', e.target.value)}>
                <option value="">Not set</option>
                {COMPANY_SIZES.map((size) => (
                  <option key={size} value={size}>{size} employees</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="label" htmlFor="cp-hq">{label('Headquarters')}</label>
              <Input id="cp-hq" maxLength={150} value={form.headquarters} onChange={(e) => set('headquarters', e.target.value)} error={errors.headquarters} />
            </div>
            <div>
              <label className="label" htmlFor="cp-reg">{label('Registration no.')}</label>
              <Input id="cp-reg" maxLength={64} value={form.registration_number} onChange={(e) => set('registration_number', e.target.value)} error={errors.registration_number} />
            </div>
          </div>
          <div>
            <label className="label" htmlFor="cp-desc">{label('About the company')}</label>
            <Textarea id="cp-desc" maxLength={5000} rows={4} value={form.description} onChange={(e) => set('description', e.target.value)} error={errors.description} />
          </div>
        </section>

        <section className="space-y-4 border-t border-hairline pt-6">
          <h2 className="text-sm font-semibold text-ink">Contact</h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className="label" htmlFor="cp-contact">{label('Contact person', false)}</label>
              <Input id="cp-contact" maxLength={150} value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} error={errors.contact_name} />
            </div>
            <div>
              <label className="label" htmlFor="cp-email">{label('Contact email', false)}</label>
              <Input id="cp-email" type="email" maxLength={255} value={form.contact_email} onChange={(e) => set('contact_email', e.target.value)} error={errors.contact_email} />
            </div>
            <div>
              <label className="label" htmlFor="cp-phone">{label('Phone')}</label>
              <Input id="cp-phone" type="tel" maxLength={20} value={form.contact_phone} onChange={(e) => set('contact_phone', e.target.value)} error={errors.contact_phone} />
            </div>
          </div>
          <p className="text-xs text-ink-faint">Your sign-in email stays the same; this is who the placement office contacts.</p>
        </section>

        <div className="flex flex-wrap items-center justify-end gap-3 border-t border-hairline pt-5">
          {changed.length > 0 && <p className="mr-auto text-xs text-warning" aria-live="polite">{changed.length} unsaved change{changed.length === 1 ? '' : 's'}</p>}
          <Button type="button" variant="secondary" disabled={changed.length === 0 || busy} onClick={() => { setForm(saved); setErrors({}); setBanner(null) }}>Discard</Button>
          <Button type="submit" isLoading={busy} disabled={changed.length === 0}>Save profile</Button>
        </div>
      </form>
    </div>
  )
}
