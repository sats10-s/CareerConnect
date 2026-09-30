import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Textarea } from '@/components/ui/Textarea'
import { toast } from '@/components/ui/Toast'
import { adminService } from '@/services/adminService'
import { formBanner, parseApiError } from '@/utils/apiError'
import type { CompanyRecord } from '@/types/admin'

/** Add or edit a company (POST / PATCH /admin/companies). Uniqueness and URL format are checked by the API. */
export function CompanyFormModal({ existing, onClose, onSaved }: { existing?: CompanyRecord; onClose: () => void; onSaved: (company: CompanyRecord) => void }) {
  const [name, setName] = useState(existing?.name ?? '')
  const [industry, setIndustry] = useState(existing?.industry ?? '')
  const [website, setWebsite] = useState(existing?.website ?? '')
  const [description, setDescription] = useState(existing?.description ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  async function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!name.trim()) {
      setErrors({ name: 'Enter the company name.' })
      return
    }
    setErrors({})
    setBusy(true)
    const trimmedSite = website.trim()
    const payload = {
      name: name.trim(),
      industry: industry.trim() || null,
      // Accept "novatech.com" as well as a full URL - the API requires http(s).
      website: trimmedSite ? (/^https?:\/\//i.test(trimmedSite) ? trimmedSite : `https://${trimmedSite}`) : null,
      description: description.trim() || null,
    }
    try {
      const saved = existing ? await adminService.companies.update(existing.id, payload) : await adminService.companies.create(payload)
      toast('success', existing ? 'Company updated.' : 'Company added.')
      onSaved(saved)
      onClose()
    } catch (err) {
      const parsed = parseApiError(err, 'Unable to save this company.')
      setErrors(parsed.fieldErrors)
      setError(formBanner(parsed))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={existing ? 'Edit company' : 'Add company'}
      footer={
        <>
          <Button variant="secondary" type="button" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="company-form" isLoading={busy}>{existing ? 'Save changes' : 'Add company'}</Button>
        </>
      }
    >
      <form id="company-form" onSubmit={submit} noValidate className="space-y-4">
        {error && <div role="alert" className="rounded-lg border border-error-line bg-error-bg px-3.5 py-2.5 text-sm text-error">{error}</div>}
        <div>
          <label className="label" htmlFor="co-name">Company name</label>
          <Input id="co-name" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} autoFocus />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className="label" htmlFor="co-industry">Industry</label>
            <Input id="co-industry" maxLength={100} placeholder="e.g. Software" value={industry} onChange={(e) => setIndustry(e.target.value)} error={errors.industry} />
          </div>
          <div>
            <label className="label" htmlFor="co-website">Website</label>
            <Input id="co-website" maxLength={255} placeholder="company.com" value={website} onChange={(e) => setWebsite(e.target.value)} error={errors.website} />
          </div>
        </div>
        <div>
          <label className="label" htmlFor="co-desc">About the company</label>
          <Textarea id="co-desc" rows={4} maxLength={5000} value={description} onChange={(e) => setDescription(e.target.value)} error={errors.description} />
        </div>
      </form>
    </Modal>
  )
}
