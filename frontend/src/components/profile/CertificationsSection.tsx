import { useState, type FormEvent } from 'react'
import { Award, ExternalLink, Pencil, Plus, Save, Trash2, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { IconButton } from '@/components/ui/IconButton'
import { Spinner } from '@/components/ui/Spinner'
import { studentProfileService } from '@/services/studentProfileService'
import type { CertificationPayload, StudentCertification, StudentProfileData } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, FieldError, fieldError, formatDate } from './shared'

interface FormState {
  name: string
  issuing_organization: string
  issue_date: string
  credential_url: string
}

const EMPTY_FORM: FormState = { name: '', issuing_organization: '', issue_date: '', credential_url: '' }
const TODAY = new Date().toISOString().slice(0, 10)

function toForm(certification: StudentCertification): FormState {
  return {
    name: certification.name,
    issuing_organization: certification.issuing_organization,
    issue_date: certification.issue_date,
    credential_url: certification.credential_url ?? '',
  }
}

function toPayload(form: FormState): CertificationPayload {
  return {
    name: form.name,
    issuing_organization: form.issuing_organization,
    issue_date: form.issue_date,
    credential_url: form.credential_url === '' ? null : form.credential_url,
  }
}

/** Certifications - each its own record (student_certifications), with Add/Edit/Delete per item. */
export function CertificationsSection({
  profile,
  onProfileChange,
}: {
  profile: StudentProfileData
  onProfileChange: (profile: StudentProfileData) => void
}) {
  const [isAddingNew, setIsAddingNew] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  return (
    <Card>
      <CardHeader
        title="Certifications"
        subtitle="Credentials you've earned"
        action={
          !isAddingNew ? (
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                setEditingId(null)
                setIsAddingNew(true)
              }}
            >
              <Plus className="h-4 w-4" />
              Add certification
            </Button>
          ) : undefined
        }
      />

      <div className="divide-y divide-border px-5">
        {isAddingNew && (
          <div className="py-5">
            <CertificationForm
              initial={EMPTY_FORM}
              submitLabel="Add certification"
              onCancel={() => setIsAddingNew(false)}
              onSubmit={async (payload) => {
                const updated = await studentProfileService.addCertification(payload)
                onProfileChange(updated)
                setIsAddingNew(false)
              }}
            />
          </div>
        )}

        {profile.certifications.length === 0 && !isAddingNew ? (
          <p className="py-8 text-center text-sm text-ink-faint">No certifications added yet.</p>
        ) : (
          profile.certifications.map((certification) =>
            editingId === certification.id ? (
              <div key={certification.id} className="py-5">
                <CertificationForm
                  initial={toForm(certification)}
                  submitLabel="Save changes"
                  onCancel={() => setEditingId(null)}
                  onSubmit={async (payload) => {
                    const updated = await studentProfileService.updateCertification(certification.id, payload)
                    onProfileChange(updated)
                    setEditingId(null)
                  }}
                />
              </div>
            ) : (
              <div key={certification.id} className="flex items-start justify-between gap-4 py-4">
                <div className="flex min-w-0 gap-3">
                  <Award className="mt-0.5 h-4 w-4 shrink-0 text-gold-ink" aria-hidden="true" />
                  <div className="min-w-0">
                    <h4 className="text-sm font-semibold text-ink">{certification.name}</h4>
                    <p className="mt-0.5 text-xs text-ink-faint">
                      {certification.issuing_organization} · {formatDate(certification.issue_date)}
                    </p>
                    {certification.credential_url && (
                      <a
                        href={certification.credential_url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="mt-1.5 inline-flex items-center gap-1 text-sm font-medium text-indigo hover:underline"
                      >
                        View credential
                        <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                      </a>
                    )}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <IconButton
                    icon={Pencil}
                    label={`Edit ${certification.name}`}
                    onClick={() => {
                      setIsAddingNew(false)
                      setEditingId(certification.id)
                    }}
                  />
                  {deletingId === certification.id ? (
                    <Spinner className="h-5 w-5" />
                  ) : (
                    <IconButton
                      icon={Trash2}
                      label={`Delete ${certification.name}`}
                      onClick={async () => {
                        if (!window.confirm(`Delete "${certification.name}"? This cannot be undone.`)) return
                        setDeletingId(certification.id)
                        try {
                          const updated = await studentProfileService.deleteCertification(certification.id)
                          onProfileChange(updated)
                        } finally {
                          setDeletingId(null)
                        }
                      }}
                    />
                  )}
                </div>
              </div>
            )
          )
        )}
      </div>
    </Card>
  )
}

function CertificationForm({
  initial,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  initial: FormState
  submitLabel: string
  onCancel: () => void
  onSubmit: (payload: CertificationPayload) => Promise<void>
}) {
  const [form, setForm] = useState<FormState>(initial)
  const [isSaving, setIsSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setIsSaving(true)
    setErrors({})
    setFormError(null)
    try {
      await onSubmit(toPayload(form))
    } catch (err) {
      const failure = describeFailure(err, 'Unable to save this certification. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
      setIsSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 rounded-lg border border-border bg-sidebar p-4">
      {formError && <ErrorBanner message={formError} />}

      <div>
        <label className="label" htmlFor="certification_name">Certification name</label>
        <input
          id="certification_name"
          required
          maxLength={150}
          className="input"
          value={form.name}
          onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'name')} />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="certification_issuer">Issuing organization</label>
          <input
            id="certification_issuer"
            required
            maxLength={150}
            className="input"
            value={form.issuing_organization}
            onChange={(e) => setForm((prev) => ({ ...prev, issuing_organization: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'issuing_organization')} />
        </div>
        <div>
          <label className="label" htmlFor="certification_issue_date">Issue date</label>
          <input
            id="certification_issue_date"
            type="date"
            required
            max={TODAY}
            className="input"
            value={form.issue_date}
            onChange={(e) => setForm((prev) => ({ ...prev, issue_date: e.target.value }))}
          />
          <FieldError message={fieldError(errors, 'issue_date')} />
        </div>
      </div>

      <div>
        <label className="label" htmlFor="certification_credential_url">Credential URL</label>
        <input
          id="certification_credential_url"
          type="url"
          placeholder="https://…"
          className="input"
          value={form.credential_url}
          onChange={(e) => setForm((prev) => ({ ...prev, credential_url: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'credential_url')} />
      </div>

      <div className="flex gap-3">
        <Button type="submit" isLoading={isSaving}>
          <Save className="h-4 w-4" />
          {submitLabel}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel} disabled={isSaving}>
          <X className="h-4 w-4" />
          Cancel
        </Button>
      </div>
    </form>
  )
}
