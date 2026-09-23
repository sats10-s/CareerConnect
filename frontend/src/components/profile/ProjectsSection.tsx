import { useState, type FormEvent } from 'react'
import { ExternalLink, Pencil, Plus, Save, Trash2, X } from 'lucide-react'
import { Card, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { IconButton } from '@/components/ui/IconButton'
import { Spinner } from '@/components/ui/Spinner'
import { studentProfileService } from '@/services/studentProfileService'
import type { ProjectPayload, StudentProfileData, StudentProject } from '@/types/studentProfile'
import { describeFailure, ErrorBanner, FieldError, fieldError } from './shared'

interface FormState {
  title: string
  description: string
  technologies: string
  project_url: string
}

const EMPTY_FORM: FormState = { title: '', description: '', technologies: '', project_url: '' }

function toForm(project: StudentProject): FormState {
  return {
    title: project.title,
    description: project.description ?? '',
    technologies: project.technologies ?? '',
    project_url: project.project_url ?? '',
  }
}

function toPayload(form: FormState): ProjectPayload {
  return {
    title: form.title,
    description: form.description === '' ? null : form.description,
    technologies: form.technologies === '' ? null : form.technologies,
    project_url: form.project_url === '' ? null : form.project_url,
  }
}

/** Projects - each its own record (student_projects), with Add/Edit/Delete per item. */
export function ProjectsSection({
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
        title="Projects"
        subtitle="Things you've built, with links to see them"
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
              Add project
            </Button>
          ) : undefined
        }
      />

      <div className="divide-y divide-border px-5">
        {isAddingNew && (
          <div className="py-5">
            <ProjectForm
              initial={EMPTY_FORM}
              submitLabel="Add project"
              onCancel={() => setIsAddingNew(false)}
              onSubmit={async (payload) => {
                const updated = await studentProfileService.addProject(payload)
                onProfileChange(updated)
                setIsAddingNew(false)
              }}
            />
          </div>
        )}

        {profile.projects.length === 0 && !isAddingNew ? (
          <p className="py-8 text-center text-sm text-ink-faint">No projects added yet.</p>
        ) : (
          profile.projects.map((project) =>
            editingId === project.id ? (
              <div key={project.id} className="py-5">
                <ProjectForm
                  initial={toForm(project)}
                  submitLabel="Save changes"
                  onCancel={() => setEditingId(null)}
                  onSubmit={async (payload) => {
                    const updated = await studentProfileService.updateProject(project.id, payload)
                    onProfileChange(updated)
                    setEditingId(null)
                  }}
                />
              </div>
            ) : (
              <div key={project.id} className="flex items-start justify-between gap-4 py-4">
                <div className="min-w-0">
                  <h4 className="text-sm font-semibold text-ink">{project.title}</h4>
                  {project.technologies && <p className="mt-0.5 text-xs text-ink-faint">{project.technologies}</p>}
                  {project.description && <p className="mt-1.5 text-sm text-ink-soft">{project.description}</p>}
                  {project.project_url && (
                    <a
                      href={project.project_url}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="mt-1.5 inline-flex items-center gap-1 text-sm font-medium text-indigo hover:underline"
                    >
                      View project
                      <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                  )}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <IconButton
                    icon={Pencil}
                    label={`Edit ${project.title}`}
                    onClick={() => {
                      setIsAddingNew(false)
                      setEditingId(project.id)
                    }}
                  />
                  {deletingId === project.id ? (
                    <Spinner className="h-5 w-5" />
                  ) : (
                    <IconButton
                      icon={Trash2}
                      label={`Delete ${project.title}`}
                      onClick={async () => {
                        if (!window.confirm(`Delete "${project.title}"? This cannot be undone.`)) return
                        setDeletingId(project.id)
                        try {
                          const updated = await studentProfileService.deleteProject(project.id)
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

function ProjectForm({
  initial,
  submitLabel,
  onCancel,
  onSubmit,
}: {
  initial: FormState
  submitLabel: string
  onCancel: () => void
  onSubmit: (payload: ProjectPayload) => Promise<void>
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
      const failure = describeFailure(err, 'Unable to save this project. Please try again.')
      setErrors(failure.errors)
      setFormError(failure.message)
      setIsSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4 rounded-lg border border-border bg-sidebar p-4">
      {formError && <ErrorBanner message={formError} />}

      <div>
        <label className="label" htmlFor="project_title">Title</label>
        <input
          id="project_title"
          required
          maxLength={150}
          className="input"
          value={form.title}
          onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'title')} />
      </div>

      <div>
        <label className="label" htmlFor="project_technologies">Technologies</label>
        <input
          id="project_technologies"
          placeholder="e.g. React, Node.js, PostgreSQL"
          maxLength={500}
          className="input"
          value={form.technologies}
          onChange={(e) => setForm((prev) => ({ ...prev, technologies: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'technologies')} />
      </div>

      <div>
        <label className="label" htmlFor="project_description">Description</label>
        <textarea
          id="project_description"
          rows={3}
          className="input"
          value={form.description}
          onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'description')} />
      </div>

      <div>
        <label className="label" htmlFor="project_url">Project URL</label>
        <input
          id="project_url"
          type="url"
          placeholder="https://…"
          className="input"
          value={form.project_url}
          onChange={(e) => setForm((prev) => ({ ...prev, project_url: e.target.value }))}
        />
        <FieldError message={fieldError(errors, 'project_url')} />
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
