import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { companyService } from '@/services/companyService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { todayIso } from '@/utils/dateFormat'
import { EMPLOYMENT_TYPE_LABEL, WORK_MODE_LABEL } from '@/utils/statusMeta'
import type { EmploymentTypeValue, WorkModeValue } from '@/types/admin'
import type { CompanyPosting, CompanyPostingPayload } from '@/types/company'

/**
 * Create or edit (while draft) a recruiter posting. No company picker and
 * no eligibility rules: the company is always the recruiter's own, and the
 * placement office sets eligibility when it reviews the posting.
 */
export function PostingFormModal({ existing, onClose, onSaved }: { existing?: CompanyPosting; onClose: () => void; onSaved: (posting: CompanyPosting) => void }) {
  const e = existing
  const [kind, setKind] = useState<'job' | 'internship'>(e?.type ?? 'job')
  const [title, setTitle] = useState(e?.title ?? '')
  const [description, setDescription] = useState(e?.description ?? '')
  const [location, setLocation] = useState(e?.location ?? '')
  const [workMode, setWorkMode] = useState<string>(e?.work_mode ?? '')
  const [deadline, setDeadline] = useState(e?.application_deadline?.slice(0, 10) ?? '')
  const [openings, setOpenings] = useState(e?.openings ? String(e.openings) : '')
  const [salary, setSalary] = useState(e?.salary_range ?? '')
  const [employmentType, setEmploymentType] = useState<string>(e?.employment_type ?? '')
  const [durationMonths, setDurationMonths] = useState(e?.duration_months ? String(e.duration_months) : '')
  const [stipend, setStipend] = useState(e?.stipend ?? '')
  const [busy, setBusy] = useState(false)
  const [banner, setBanner] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const isJob = kind === 'job'

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBanner(null)
    if (!title.trim()) {
      setErrors({ title: 'Give the posting a title.' })
      return
    }
    setErrors({})

    const payload: CompanyPostingPayload = {
      title: title.trim(),
      description: description.trim() || null,
      location: location.trim() || null,
      work_mode: (workMode || null) as WorkModeValue | null,
      application_deadline: deadline || null,
      openings: openings ? Number(openings) : null,
      ...(isJob
        ? { salary_range: salary.trim() || null, employment_type: (employmentType || null) as EmploymentTypeValue | null }
        : { duration_months: durationMonths ? Number(durationMonths) : null, stipend: stipend.trim() || null }),
    }

    setBusy(true)
    try {
      const saved = e
        ? await companyService.postings.update(e.type, e.id, payload)
        : await companyService.postings.create({ ...payload, type: kind, title: title.trim() })
      toast('success', e ? 'Changes saved.' : 'Draft saved. Submit it when it’s ready.')
      onSaved(saved)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this posting.')
      setErrors(parsed.fieldErrors)
      setBanner(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={e ? `Edit ${isJob ? 'job' : 'internship'}` : 'New posting'}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="company-posting-form" isLoading={busy}>{e ? 'Save changes' : 'Save draft'}</Button>
        </>
      }
    >
      <form id="company-posting-form" onSubmit={submit} noValidate className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
        {banner && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{banner}</div>}

        {!e && (
          <fieldset>
            <legend className="label">Type</legend>
            <div className="grid grid-cols-2 gap-2" role="radiogroup">
              {(['job', 'internship'] as const).map((k) => (
                <button
                  key={k}
                  type="button"
                  role="radio"
                  aria-checked={kind === k}
                  onClick={() => setKind(k)}
                  className={`rounded-lg border px-3 py-2 text-sm font-medium transition-colors duration-fast ${kind === k ? 'border-primary bg-surface-raised text-ink' : 'border-hairline text-ink-soft hover:text-ink'}`}
                >
                  {k === 'job' ? 'Full-time job' : 'Internship'}
                </button>
              ))}
            </div>
          </fieldset>
        )}

        <div>
          <label className="label" htmlFor="cpf-title">Title</label>
          <Input id="cpf-title" maxLength={255} value={title} onChange={(ev) => setTitle(ev.target.value)} error={errors.title} />
        </div>
        <div>
          <label className="label" htmlFor="cpf-desc">Description</label>
          <Textarea id="cpf-desc" rows={5} maxLength={10000} placeholder="Role, responsibilities, skills you’re looking for, selection process" value={description} onChange={(ev) => setDescription(ev.target.value)} error={errors.description} />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="cpf-location">Location</label>
            <Input id="cpf-location" maxLength={150} value={location} onChange={(ev) => setLocation(ev.target.value)} error={errors.location} />
          </div>
          <div>
            <label className="label" htmlFor="cpf-mode">Work mode</label>
            <Select id="cpf-mode" value={workMode} onChange={(ev) => setWorkMode(ev.target.value)} error={errors.work_mode}>
              <option value="">Not specified</option>
              {Object.entries(WORK_MODE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </div>
          {isJob ? (
            <>
              <div>
                <label className="label" htmlFor="cpf-type">Employment type</label>
                <Select id="cpf-type" value={employmentType} onChange={(ev) => setEmploymentType(ev.target.value)} error={errors.employment_type}>
                  <option value="">Not specified</option>
                  {Object.entries(EMPLOYMENT_TYPE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </div>
              <div>
                <label className="label" htmlFor="cpf-salary">Salary range</label>
                <Input id="cpf-salary" maxLength={100} placeholder="e.g. ₹8-12 LPA" value={salary} onChange={(ev) => setSalary(ev.target.value)} error={errors.salary_range} />
              </div>
            </>
          ) : (
            <>
              <div>
                <label className="label" htmlFor="cpf-duration">Duration (months)</label>
                <Input id="cpf-duration" type="number" min={1} max={60} value={durationMonths} onChange={(ev) => setDurationMonths(ev.target.value)} error={errors.duration_months} />
              </div>
              <div>
                <label className="label" htmlFor="cpf-stipend">Stipend</label>
                <Input id="cpf-stipend" maxLength={100} placeholder="e.g. ₹25,000/month" value={stipend} onChange={(ev) => setStipend(ev.target.value)} error={errors.stipend} />
              </div>
            </>
          )}
          <div>
            <label className="label" htmlFor="cpf-deadline">Application deadline</label>
            <Input id="cpf-deadline" type="date" min={todayIso()} value={deadline} onChange={(ev) => setDeadline(ev.target.value)} error={errors.application_deadline} />
          </div>
          <div>
            <label className="label" htmlFor="cpf-openings">Openings</label>
            <Input id="cpf-openings" type="number" min={1} value={openings} onChange={(ev) => setOpenings(ev.target.value)} error={errors.openings} />
          </div>
        </div>
        <p className="text-xs text-ink-faint">Eligibility (CGPA, departments, batches) is set by the placement office when it reviews your posting.</p>
      </form>
    </Modal>
  )
}
