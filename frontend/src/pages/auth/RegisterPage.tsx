import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { UserPlus } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/Button'
import { metaService } from '@/services/metaService'
import { dashboardPathForRole } from '@/utils/roles'
import type { ApiValidationError } from '@/types/auth'
import type { Batch, Department } from '@/types/meta'

interface FormState {
  name: string
  email: string
  password: string
  password_confirmation: string
  roll_number: string
  department_id: string
  batch_id: string
  year: string
  phone: string
}

const EMPTY_FORM: FormState = {
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  roll_number: '',
  department_id: '',
  batch_id: '',
  year: '',
  phone: '',
}

const YEAR_OPTIONS = [1, 2, 3, 4] as const

export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [departments, setDepartments] = useState<Department[]>([])
  const [batches, setBatches] = useState<Batch[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    metaService.departments().then(setDepartments).catch(() => setDepartments([]))
    metaService.batches().then(setBatches).catch(() => setBatches([]))
  }, [])

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    setErrors({})
    setIsSubmitting(true)
    try {
      const user = await register({
        ...form,
        department_id: Number(form.department_id),
        batch_id: Number(form.batch_id),
        year: Number(form.year),
      })
      navigate(dashboardPathForRole(user.role), { replace: true })
    } catch (err) {
      if (isAxiosError<ApiValidationError>(err) && err.response?.status === 422) {
        setErrors(err.response.data.errors ?? {})
        setFormError(err.response.data.message)
      } else {
        setFormError('Unable to create your account. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  function fieldError(key: string) {
    return errors[key]?.[0]
  }

  return (
    <div>
      <h1 className="font-display font-bold tracking-tight text-3xl text-ink">Create your student account</h1>
      <p className="mt-1 text-sm text-muted">Faculty and admin accounts are provisioned by the placement office.</p>

      <form onSubmit={handleSubmit} className="mt-8 space-y-5">
        {formError && (
          <div className="rounded-lg border border-error/20 bg-error-bg px-3.5 py-2.5 text-sm text-error">{formError}</div>
        )}

        <div>
          <label className="label" htmlFor="name">Full name</label>
          <input id="name" required className="input" value={form.name} onChange={(e) => update('name', e.target.value)} />
          {fieldError('name') && <p className="mt-1 text-xs text-error">{fieldError('name')}</p>}
        </div>

        <div>
          <label className="label" htmlFor="email">College email</label>
          <input id="email" type="email" required className="input" value={form.email} onChange={(e) => update('email', e.target.value)} />
          {fieldError('email') && <p className="mt-1 text-xs text-error">{fieldError('email')}</p>}
        </div>

        <div className="grid grid-cols-2 gap-5">
          <div>
            <label className="label" htmlFor="roll_number">Roll number</label>
            <input id="roll_number" required className="input" value={form.roll_number} onChange={(e) => update('roll_number', e.target.value)} />
            {fieldError('roll_number') && <p className="mt-1 text-xs text-error">{fieldError('roll_number')}</p>}
          </div>
          <div>
            <label className="label" htmlFor="phone">Phone (optional)</label>
            <input id="phone" className="input" value={form.phone} onChange={(e) => update('phone', e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
          <div>
            <label className="label" htmlFor="department_id">Department</label>
            <select id="department_id" required className="input" value={form.department_id} onChange={(e) => update('department_id', e.target.value)}>
              <option value="">Select…</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>{d.code}</option>
              ))}
            </select>
            {fieldError('department_id') && <p className="mt-1 text-xs text-error">{fieldError('department_id')}</p>}
          </div>
          <div>
            <label className="label" htmlFor="batch_id">Batch</label>
            <select id="batch_id" required className="input" value={form.batch_id} onChange={(e) => update('batch_id', e.target.value)}>
              <option value="">Select…</option>
              {batches.map((b) => (
                <option key={b.id} value={b.id}>{b.name}</option>
              ))}
            </select>
            {fieldError('batch_id') && <p className="mt-1 text-xs text-error">{fieldError('batch_id')}</p>}
          </div>
          <div>
            <label className="label" htmlFor="year">Year</label>
            <select id="year" required className="input" value={form.year} onChange={(e) => update('year', e.target.value)}>
              <option value="">Select…</option>
              {YEAR_OPTIONS.map((y) => (
                <option key={y} value={y}>Year {y}</option>
              ))}
            </select>
            {fieldError('year') && <p className="mt-1 text-xs text-error">{fieldError('year')}</p>}
          </div>
        </div>

        <div>
          <label className="label" htmlFor="password">Password</label>
          <input id="password" type="password" required className="input" value={form.password} onChange={(e) => update('password', e.target.value)} />
          {fieldError('password') && <p className="mt-1 text-xs text-error">{fieldError('password')}</p>}
        </div>

        <div>
          <label className="label" htmlFor="password_confirmation">Confirm password</label>
          <input id="password_confirmation" type="password" required className="input" value={form.password_confirmation} onChange={(e) => update('password_confirmation', e.target.value)} />
        </div>

        <Button type="submit" isLoading={isSubmitting} className="w-full">
          <UserPlus className="h-4 w-4" />
          Create account
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-muted">
        Already have an account?{' '}
        <Link to="/login" className="font-semibold text-primary hover:text-primary-hov">Sign in</Link>
      </p>
    </div>
  )
}
