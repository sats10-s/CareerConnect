import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Building2, Lock } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { superAdminService } from '@/services/superAdminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { COMPANY_SIZES } from '@/utils/statusMeta'
import type { PublicSettings } from '@/types/superAdmin'

interface FormState {
  company_name: string
  industry: string
  website: string
  company_size: string
  headquarters: string
  registration_number: string
  description: string
  contact_name: string
  email: string
  contact_phone: string
  password: string
  password_confirmation: string
}

const EMPTY_FORM: FormState = {
  company_name: '',
  industry: '',
  website: '',
  company_size: '',
  headquarters: '',
  registration_number: '',
  description: '',
  contact_name: '',
  email: '',
  contact_phone: '',
  password: '',
  password_confirmation: '',
}

const STEP_ONE_FIELDS: (keyof FormState)[] = ['company_name', 'industry', 'website', 'company_size', 'headquarters', 'registration_number', 'description']

const optional = (value: string) => value.trim() || null

function StepIndicator({ step }: { step: 1 | 2 }) {
  return (
    <ol className="mt-6 grid grid-cols-2 gap-2" aria-label="Registration progress">
      {(['Company', 'Contact & login'] as const).map((label, index) => {
        const active = step === index + 1
        const done = step > index + 1
        return (
          <li key={label} aria-current={active ? 'step' : undefined}>
            <div className={`h-1 rounded-full ${active || done ? 'bg-primary' : 'bg-border'}`} />
            <p className={`mt-2 text-xs font-medium ${active ? 'text-ink' : 'text-ink-faint'}`}>
              {index + 1}. {label}
            </p>
          </li>
        )
      })}
    </ol>
  )
}

/**
 * Public /company/register - two steps (company details, then the contact
 * person's login). Creates a PENDING company; the recruiter is signed in
 * and lands on their status page until a Super Admin approves it.
 */
export function CompanyRegisterPage() {
  const { registerCompany } = useAuth()
  const navigate = useNavigate()
  const [step, setStep] = useState<1 | 2>(1)
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [banner, setBanner] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [settings, setSettings] = useState<PublicSettings | null>(null)

  useEffect(() => {
    // If this fails the form stays open; the API still enforces the setting.
    superAdminService.publicSettings().then(setSettings).catch(() => setSettings(null))
  }, [])

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
    setErrors((prev) => {
      if (!prev[key]) return prev
      const next = { ...prev }
      delete next[key]
      return next
    })
  }

  function continueToStepTwo(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string> = {}
    if (!form.company_name.trim()) local.company_name = 'Enter your company name.'
    if (form.website.trim() && !/^https?:\/\/\S+\.\S+/.test(form.website.trim())) local.website = 'Enter a full URL, e.g. https://example.com'
    setErrors(local)
    if (Object.keys(local).length === 0) {
      setBanner(null)
      setStep(2)
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const local: Record<string, string> = {}
    if (!form.contact_name.trim()) local.contact_name = 'Enter the contact person’s name.'
    if (!form.email.trim()) local.email = 'Enter a work email.'
    if (form.password.length < 8) local.password = 'Use at least 8 characters, with upper and lower case letters and a number.'
    if (form.password !== form.password_confirmation) local.password_confirmation = 'Passwords do not match.'
    setErrors(local)
    if (Object.keys(local).length) return

    setBanner(null)
    setIsSubmitting(true)
    try {
      const user = await registerCompany({
        company_name: form.company_name.trim(),
        industry: optional(form.industry),
        website: optional(form.website),
        company_size: optional(form.company_size),
        headquarters: optional(form.headquarters),
        registration_number: optional(form.registration_number),
        description: optional(form.description),
        contact_name: form.contact_name.trim(),
        email: form.email.trim(),
        contact_phone: optional(form.contact_phone),
        password: form.password,
        password_confirmation: form.password_confirmation,
      })
      navigate('/company/dashboard', { replace: true, state: { justRegistered: user.company?.name ?? true } })
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to register your company. Please try again.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
      if (STEP_ONE_FIELDS.some((field) => parsed.fieldErrors[field])) setStep(1)
    } finally {
      setIsSubmitting(false)
    }
  }

  const office = settings?.institution_name ? `the ${settings.institution_name} placement office` : 'the placement office'

  if (settings && !settings.allow_company_registration) {
    return (
      <div>
        <h1 className="font-display text-3xl font-bold tracking-tight text-ink">Company registration is closed</h1>
        <div className="mt-6 flex gap-3 rounded-xl border border-hairline bg-surface p-4">
          <Lock className="mt-0.5 h-5 w-5 shrink-0 text-ink-faint" aria-hidden="true" />
          <p className="text-sm text-ink-soft">
            New recruiters can’t register online right now. Contact {office}
            {settings.support_email ? (
              <> at <a href={`mailto:${settings.support_email}`} className="font-medium text-primary hover:underline">{settings.support_email}</a></>
            ) : null}{' '}
            to recruit here.
          </p>
        </div>
        <p className="mt-6 text-center text-sm text-muted">
          Already registered?{' '}
          <Link to="/login" className="font-semibold text-primary hover:text-primary-hov">Sign in</Link>
        </p>
      </div>
    )
  }

  return (
    <div>
      <h1 className="font-display text-3xl font-bold tracking-tight text-ink">Register your company</h1>
      <p className="mt-1 text-sm text-muted">Recruit from {settings?.institution_name ?? 'our campus'}. A Super Admin reviews every new company before it goes live.</p>

      <StepIndicator step={step} />

      {banner && (
        <div role="alert" className="mt-6 rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>
      )}

      {step === 1 ? (
        <form onSubmit={continueToStepTwo} noValidate className="mt-6 space-y-5">
          <div>
            <label className="label" htmlFor="company_name">Company name</label>
            <Input id="company_name" required maxLength={255} autoComplete="organization" value={form.company_name} onChange={(e) => update('company_name', e.target.value)} error={errors.company_name} />
          </div>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="industry">Industry <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="industry" maxLength={100} placeholder="Software" value={form.industry} onChange={(e) => update('industry', e.target.value)} error={errors.industry} />
            </div>
            <div>
              <label className="label" htmlFor="company_size">Company size <span className="font-normal text-ink-faint">(optional)</span></label>
              <Select id="company_size" value={form.company_size} onChange={(e) => update('company_size', e.target.value)}>
                <option value="">Select…</option>
                {COMPANY_SIZES.map((size) => (
                  <option key={size} value={size}>{size} employees</option>
                ))}
              </Select>
              {errors.company_size && <p className="mt-1 text-xs text-error">{errors.company_size}</p>}
            </div>
          </div>
          <div>
            <label className="label" htmlFor="website">Website <span className="font-normal text-ink-faint">(optional)</span></label>
            <Input id="website" type="url" maxLength={255} placeholder="https://example.com" value={form.website} onChange={(e) => update('website', e.target.value)} error={errors.website} />
          </div>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="headquarters">Headquarters <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="headquarters" maxLength={150} placeholder="City, Country" value={form.headquarters} onChange={(e) => update('headquarters', e.target.value)} error={errors.headquarters} />
            </div>
            <div>
              <label className="label" htmlFor="registration_number">Registration no. <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="registration_number" maxLength={64} placeholder="CIN / GSTIN" value={form.registration_number} onChange={(e) => update('registration_number', e.target.value)} error={errors.registration_number} />
            </div>
          </div>
          <div>
            <label className="label" htmlFor="description">About the company <span className="font-normal text-ink-faint">(optional)</span></label>
            <Textarea id="description" maxLength={5000} rows={3} value={form.description} onChange={(e) => update('description', e.target.value)} error={errors.description} />
          </div>
          <Button type="submit" className="w-full">
            Continue
            <ArrowRight className="h-4 w-4" />
          </Button>
        </form>
      ) : (
        <form onSubmit={submit} noValidate className="mt-6 space-y-5">
          <div className="flex items-center gap-3 rounded-xl border border-hairline bg-surface px-4 py-3">
            <Building2 className="h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
            <p className="min-w-0 truncate text-sm font-medium text-ink">{form.company_name}</p>
            <button type="button" className="ml-auto text-xs font-semibold text-primary hover:text-primary-hov" onClick={() => setStep(1)}>Edit</button>
          </div>
          <div>
            <label className="label" htmlFor="contact_name">Contact person</label>
            <Input id="contact_name" required maxLength={150} autoComplete="name" value={form.contact_name} onChange={(e) => update('contact_name', e.target.value)} error={errors.contact_name} />
          </div>
          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
              <label className="label" htmlFor="email">Work email</label>
              <Input id="email" type="email" required maxLength={255} autoComplete="email" value={form.email} onChange={(e) => update('email', e.target.value)} error={errors.email} />
            </div>
            <div>
              <label className="label" htmlFor="contact_phone">Phone <span className="font-normal text-ink-faint">(optional)</span></label>
              <Input id="contact_phone" type="tel" maxLength={20} autoComplete="tel" value={form.contact_phone} onChange={(e) => update('contact_phone', e.target.value)} error={errors.contact_phone} />
            </div>
          </div>
          <div>
            <label className="label" htmlFor="password">Password</label>
            <Input id="password" type="password" required autoComplete="new-password" aria-describedby="password-hint" value={form.password} onChange={(e) => update('password', e.target.value)} error={errors.password} />
            {!errors.password && <p id="password-hint" className="mt-1 text-xs text-ink-faint">At least 8 characters with upper and lower case letters and a number.</p>}
          </div>
          <div>
            <label className="label" htmlFor="password_confirmation">Confirm password</label>
            <Input id="password_confirmation" type="password" required autoComplete="new-password" value={form.password_confirmation} onChange={(e) => update('password_confirmation', e.target.value)} error={errors.password_confirmation} />
          </div>
          <div className="flex gap-3">
            <Button type="button" variant="secondary" onClick={() => setStep(1)} disabled={isSubmitting}>
              <ArrowLeft className="h-4 w-4" />
              Back
            </Button>
            <Button type="submit" isLoading={isSubmitting} className="flex-1">Submit for review</Button>
          </div>
        </form>
      )}

      <p className="mt-6 text-center text-sm text-muted">
        Already registered?{' '}
        <Link to="/login" className="font-semibold text-primary hover:text-primary-hov">Sign in</Link>
      </p>
    </div>
  )
}
