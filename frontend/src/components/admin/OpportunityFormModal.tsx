import { useState, type FormEvent, type KeyboardEvent } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { useCompanyOptions } from '@/hooks/useAdminOptions'
import { useApiResource } from '@/hooks/useApiResource'
import { adminService } from '@/services/adminService'
import { metaService } from '@/services/metaService'
import { formBanner, parseApiError } from '@/utils/apiError'
import { todayIso } from '@/utils/dateFormat'
import { EMPLOYMENT_TYPE_LABEL, WORK_MODE_LABEL } from '@/utils/statusMeta'
import type { AdminOpportunity, EmploymentTypeValue, OpportunityPayload, WorkModeValue } from '@/types/admin'

interface OpportunityFormModalProps {
  kind: 'job' | 'internship'
  /** Present when editing; absent when creating. */
  existing?: AdminOpportunity
  onClose: () => void
  onSaved: (saved: AdminOpportunity) => void
}

const YEARS = [1, 2, 3, 4]

/**
 * Create/edit a job posting or internship (POST/PATCH /admin/{jobs|internships}).
 * Status is deliberately NOT a field here: lifecycle changes go through
 * the drawer's publish/close/archive actions, which only offer the
 * transitions OpportunityStatus allows - a free status select would
 * just be a way to send the API a refusal. On create the posting
 * starts as a draft (the API's default).
 */
export function OpportunityFormModal({ kind, existing, onClose, onSaved }: OpportunityFormModalProps) {
  const isEdit = Boolean(existing)
  const isJob = kind === 'job'
  const companies = useCompanyOptions()
  const meta = useApiResource(async () => ({ departments: await metaService.departments(), batches: await metaService.batches() }), 'admin-meta')

  const e = existing
  const [companyId, setCompanyId] = useState(e?.company ? String(e.company.id) : '')
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
  const [isActive, setIsActive] = useState(e?.is_active ?? true)

  const [enforced, setEnforced] = useState(e?.eligibility.enforced ?? false)
  const [minCgpa, setMinCgpa] = useState(e?.eligibility.minimum_cgpa != null ? String(e.eligibility.minimum_cgpa) : '')
  const [maxBacklogs, setMaxBacklogs] = useState(e?.eligibility.maximum_backlogs != null ? String(e.eligibility.maximum_backlogs) : '')
  const [years, setYears] = useState<number[]>(e?.eligibility.years ?? [])
  const [departmentIds, setDepartmentIds] = useState<number[]>(e?.eligibility.departments?.map((d) => d.id) ?? [])
  const [batchIds, setBatchIds] = useState<number[]>(e?.eligibility.batches?.map((b) => b.id) ?? [])
  const [skills, setSkills] = useState<string[]>(e?.eligibility.required_skills ?? [])
  const [skillDraft, setSkillDraft] = useState('')

  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const toggle = <T,>(list: T[], value: T, set: (v: T[]) => void) => set(list.includes(value) ? list.filter((x) => x !== value) : [...list, value])

  function addSkill() {
    const value = skillDraft.trim().replace(/,$/, '')
    if (value && !skills.includes(value)) setSkills([...skills, value])
    setSkillDraft('')
  }
  function onSkillKey(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault()
      addSkill()
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    const local: Record<string, string> = {}
    if (!companyId) local.company_id = 'Choose the company this posting belongs to.'
    if (!title.trim()) local.title = 'Give the posting a title.'
    if (Object.keys(local).length) {
      setErrors(local)
      return
    }
    setErrors({})

    const payload: OpportunityPayload = {
      company_id: Number(companyId),
      title: title.trim(),
      description: description.trim() || null,
      location: location.trim() || null,
      work_mode: (workMode || null) as WorkModeValue | null,
      application_deadline: deadline || null,
      openings: openings ? Number(openings) : null,
      is_active: isActive,
      eligibility_enforced: enforced,
      minimum_cgpa: minCgpa ? Number(minCgpa) : null,
      maximum_backlogs: maxBacklogs ? Number(maxBacklogs) : null,
      eligible_department_ids: departmentIds,
      eligible_batch_ids: batchIds,
      eligible_years: years,
      required_skills: skills,
      ...(isJob
        ? { salary_range: salary.trim() || null, employment_type: (employmentType || null) as EmploymentTypeValue | null }
        : { duration_months: durationMonths ? Number(durationMonths) : null, stipend: stipend.trim() || null }),
    }

    setSubmitting(true)
    try {
      const api = isJob ? adminService.jobs : adminService.internships
      const saved = existing ? await api.update(existing.id, payload) : await api.create(payload)
      toast('success', existing ? 'Changes saved.' : `${isJob ? 'Job' : 'Internship'} created as a draft.`)
      onSaved(saved)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this posting.')
      setErrors(parsed.fieldErrors)
      setFormError(formBanner(parsed))
    } finally {
      setSubmitting(false)
    }
  }

  const formId = 'opportunity-form'
  const noun = isJob ? 'job' : 'internship'

  return (
    <Modal
      open
      onClose={onClose}
      title={isEdit ? `Edit ${noun}` : `New ${noun}`}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form={formId} isLoading={submitting}>{isEdit ? 'Save changes' : `Create ${noun}`}</Button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} noValidate className="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
        {formError && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{formError}</div>}

        <div>
          <label className="label" htmlFor="op-company">Company</label>
          <Select id="op-company" value={companyId} onChange={(ev) => setCompanyId(ev.target.value)} error={errors.company_id}>
            <option value="">{companies.isLoading ? 'Loading companies…' : 'Choose a company…'}</option>
            {(companies.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            {existing?.company && !(companies.data ?? []).some((c) => c.id === existing.company?.id) && <option value={existing.company.id}>{existing.company.name}</option>}
          </Select>
          {companies.error && <p className="mt-1 text-xs text-error">Couldn't load companies: {companies.error}</p>}
          {!companies.isLoading && !companies.error && (companies.data ?? []).length === 0 && (
            <p className="mt-1 text-xs text-ink-faint">No companies yet - add one under Companies first.</p>
          )}
        </div>
        <div>
          <label className="label" htmlFor="op-title">Title</label>
          <Input id="op-title" maxLength={255} value={title} onChange={(ev) => setTitle(ev.target.value)} error={errors.title} />
        </div>
        <div>
          <label className="label" htmlFor="op-desc">Description</label>
          <Textarea id="op-desc" rows={4} value={description} onChange={(ev) => setDescription(ev.target.value)} error={errors.description} />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="op-location">Location</label>
            <Input id="op-location" maxLength={150} value={location} onChange={(ev) => setLocation(ev.target.value)} error={errors.location} />
          </div>
          <div>
            <label className="label" htmlFor="op-mode">Work mode</label>
            <Select id="op-mode" value={workMode} onChange={(ev) => setWorkMode(ev.target.value)} error={errors.work_mode}>
              <option value="">Not specified</option>
              {Object.entries(WORK_MODE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          </div>
          {isJob ? (
            <>
              <div>
                <label className="label" htmlFor="op-type">Employment type</label>
                <Select id="op-type" value={employmentType} onChange={(ev) => setEmploymentType(ev.target.value)} error={errors.employment_type}>
                  <option value="">Not specified</option>
                  {Object.entries(EMPLOYMENT_TYPE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </div>
              <div>
                <label className="label" htmlFor="op-salary">Salary range</label>
                <Input id="op-salary" maxLength={100} placeholder="e.g. ₹8-12 LPA" value={salary} onChange={(ev) => setSalary(ev.target.value)} error={errors.salary_range} />
              </div>
            </>
          ) : (
            <>
              <div>
                <label className="label" htmlFor="op-duration">Duration (months)</label>
                <Input id="op-duration" type="number" min={1} max={60} value={durationMonths} onChange={(ev) => setDurationMonths(ev.target.value)} error={errors.duration_months} />
              </div>
              <div>
                <label className="label" htmlFor="op-stipend">Stipend</label>
                <Input id="op-stipend" maxLength={100} placeholder="e.g. ₹25,000/month" value={stipend} onChange={(ev) => setStipend(ev.target.value)} error={errors.stipend} />
              </div>
            </>
          )}
          <div>
            <label className="label" htmlFor="op-deadline">Application deadline</label>
            <Input id="op-deadline" type="date" min={isEdit ? undefined : todayIso()} value={deadline} onChange={(ev) => setDeadline(ev.target.value)} error={errors.application_deadline} />
          </div>
          <div>
            <label className="label" htmlFor="op-openings">Openings</label>
            <Input id="op-openings" type="number" min={1} value={openings} onChange={(ev) => setOpenings(ev.target.value)} error={errors.openings} />
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm text-ink">
          <input type="checkbox" checked={isActive} onChange={(ev) => setIsActive(ev.target.checked)} className="h-4 w-4 accent-[var(--color-primary)]" />
          Accepting applications (active)
        </label>

        <fieldset className="space-y-4 rounded-xl border border-hairline p-4">
          <legend className="px-1 text-sm font-semibold text-ink">Eligibility</legend>
          <label className="flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" checked={enforced} onChange={(ev) => setEnforced(ev.target.checked)} className="h-4 w-4 accent-[var(--color-primary)]" />
            Enforce these rules (students who don't meet them can't apply)
          </label>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label" htmlFor="op-cgpa">Minimum CGPA</label>
              <Input id="op-cgpa" type="number" min={0} max={10} step="0.1" value={minCgpa} onChange={(ev) => setMinCgpa(ev.target.value)} error={errors.minimum_cgpa} />
            </div>
            <div>
              <label className="label" htmlFor="op-backlogs">Maximum backlogs</label>
              <Input id="op-backlogs" type="number" min={0} value={maxBacklogs} onChange={(ev) => setMaxBacklogs(ev.target.value)} error={errors.maximum_backlogs} />
            </div>
          </div>
          <div>
            <p className="label">Years</p>
            <div className="flex flex-wrap gap-3">
              {YEARS.map((y) => (
                <label key={y} className="flex items-center gap-1.5 text-sm text-ink">
                  <input type="checkbox" checked={years.includes(y)} onChange={() => toggle(years, y, setYears)} className="h-4 w-4 accent-[var(--color-primary)]" />
                  Year {y}
                </label>
              ))}
            </div>
          </div>
          <div>
            <p className="label">Departments</p>
            <div className="flex flex-wrap gap-x-4 gap-y-2">
              {(meta.data?.departments ?? []).map((d) => (
                <label key={d.id} className="flex items-center gap-1.5 text-sm text-ink">
                  <input type="checkbox" checked={departmentIds.includes(d.id)} onChange={() => toggle(departmentIds, d.id, setDepartmentIds)} className="h-4 w-4 accent-[var(--color-primary)]" />
                  {d.code}
                </label>
              ))}
              {meta.isLoading && <span className="text-xs text-ink-faint">Loading…</span>}
            </div>
          </div>
          <div>
            <p className="label">Batches</p>
            <div className="flex flex-wrap gap-x-4 gap-y-2">
              {(meta.data?.batches ?? []).map((b) => (
                <label key={b.id} className="flex items-center gap-1.5 text-sm text-ink">
                  <input type="checkbox" checked={batchIds.includes(b.id)} onChange={() => toggle(batchIds, b.id, setBatchIds)} className="h-4 w-4 accent-[var(--color-primary)]" />
                  {b.name}
                </label>
              ))}
            </div>
          </div>
          <div>
            <label className="label" htmlFor="op-skill">Required skills</label>
            <div className="mb-2 flex flex-wrap gap-2">
              {skills.map((s) => (
                <span key={s} className="inline-flex items-center gap-1 rounded-full bg-primary-tint px-2.5 py-1 text-xs font-medium text-primary">
                  {s}
                  <button type="button" aria-label={`Remove ${s}`} onClick={() => setSkills(skills.filter((x) => x !== s))}><X className="h-3 w-3" /></button>
                </span>
              ))}
            </div>
            <Input id="op-skill" placeholder="Type a skill, press Enter" value={skillDraft} onChange={(ev) => setSkillDraft(ev.target.value)} onKeyDown={onSkillKey} onBlur={addSkill} />
          </div>
        </fieldset>
      </form>
    </Modal>
  )
}
