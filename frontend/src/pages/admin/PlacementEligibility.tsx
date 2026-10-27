import { useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { api } from '@/services/api'
import { toast } from '@/components/ui/Toast'

type StudentRow = { id: number; roll_number: string; placement_status: 'PLACED' | 'NOT_PLACED' | 'UNKNOWN' | 'UNPLACED'; placement_company: string | null; placement_job_role: string | null; placement_package: number | null; placement_date: string | null; user: { name: string; email: string } }
type Opportunity = { id: number; type: 'job' | 'internship'; title: string }
type History = { id: number; student_id: number; opportunity_type: string; opportunity_id: number; grant_reason: string; granted_at: string; revocation_reason: string | null; revoked_at: string | null }
type StatusHistory = { id: number; from_status: string | null; to_status: string; old_details: Record<string, string | null> | null; new_details: Record<string, string | null> | null; reason: string | null; changed_at: string; changed_by: number | null; actor: { id: number; name: string } | null }
type Envelope<T> = { success: boolean; message: string; data: T }

export function AdminPlacementEligibilityPage() {
  const [search, setSearch] = useState('')
  const [students, setStudents] = useState<StudentRow[]>([])
  const [opportunities, setOpportunities] = useState<Opportunity[]>([])
  const [history, setHistory] = useState<History[]>([])
  const [studentId, setStudentId] = useState('')
  const [opportunityKey, setOpportunityKey] = useState('')
  const [reason, setReason] = useState('')
  const [placementStatus, setPlacementStatus] = useState<StudentRow['placement_status']>('UNKNOWN')
  const [company, setCompany] = useState('')
  const [jobRole, setJobRole] = useState('')
  const [packageLpa, setPackageLpa] = useState('')
  const [placementDate, setPlacementDate] = useState('')
  const [statusReason, setStatusReason] = useState('')
  const [statusHistory, setStatusHistory] = useState<StatusHistory[]>([])

  async function refresh() {
    const [found, events, statusEvents] = await Promise.all([
      api.get<Envelope<{ students: StudentRow[]; opportunities: Opportunity[] }>>('/admin/placement-eligibility', { params: { search } }),
      api.get<Envelope<History[]>>('/admin/placement-eligibility/history', { params: studentId ? { student_id: studentId } : {} }),
      studentId ? api.get<Envelope<StatusHistory[]>>(`/admin/placement-eligibility/students/${studentId}/history`) : Promise.resolve({ data: { data: [] as StatusHistory[] } }),
    ])
    setStudents(found.data.data.students); setOpportunities(found.data.data.opportunities); setHistory(events.data.data); setStatusHistory(statusEvents.data.data)
  }
  useEffect(() => { void refresh() }, [search, studentId])

  async function grant() {
    const [type, id] = opportunityKey.split(':')
    if (!studentId || !type || !id || reason.trim().length < 5) return
    await api.post(`/admin/placement-eligibility/opportunities/${type}/${id}/exceptions`, { student_id: Number(studentId), reason })
    toast('success', 'Permission granted.'); setReason(''); await refresh()
  }

  async function savePlacementStatus() {
    if (!studentId) return
    await api.patch(`/admin/placement-eligibility/students/${studentId}`, {
      placement_status: placementStatus === 'UNPLACED' ? 'NOT_PLACED' : placementStatus,
      placement_company: company || null,
      placement_job_role: jobRole || null,
      placement_package: packageLpa ? Number(packageLpa) : null,
      placement_date: placementDate || null,
      reason: statusReason || null,
    })
    toast('success', 'Placement status updated.'); await refresh()
  }

  function selectStudent(value: string) {
    setStudentId(value)
    const selected = students.find((s) => String(s.id) === value)
    if (!selected) return
    setPlacementStatus(selected.placement_status === 'UNPLACED' ? 'NOT_PLACED' : selected.placement_status)
    setCompany(selected.placement_company ?? '')
    setJobRole(selected.placement_job_role ?? '')
    setPackageLpa(selected.placement_package == null ? '' : String(selected.placement_package))
    setPlacementDate(selected.placement_date ?? '')
  }

  async function revoke(id: number) {
    if (!window.confirm('Revoke this student’s permission for this opportunity?')) return
    const why = window.prompt('Reason for revoking this permission (required):')?.trim()
    if (!why || why.length < 5) return
    await api.post(`/admin/placement-eligibility/exceptions/${id}/revoke`, { reason: why })
    toast('success', 'Permission revoked.'); await refresh()
  }

  return <div className="space-y-6">
    <PageHeader title="Placed student eligibility" description="Confirm placement status and manage opportunity specific exceptions. Unknown placement status is restricted until confirmed." />
    <section className="card space-y-4 p-5">
      <h2 className="font-semibold text-ink">Find a student or opportunity</h2>
      <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by student name, email, roll number, or opportunity title" />
      <div className="grid gap-4 md:grid-cols-2">
        <label className="label">Student<select className="input mt-1 w-full" value={studentId} onChange={(e) => selectStudent(e.target.value)}><option value="">Choose student</option>{students.map((s) => <option key={s.id} value={s.id}>{s.user?.name} · {s.roll_number} · {s.placement_status === 'UNPLACED' ? 'NOT_PLACED' : s.placement_status}</option>)}</select></label>
        <label className="label">Opportunity<select className="input mt-1 w-full" value={opportunityKey} onChange={(e) => setOpportunityKey(e.target.value)}><option value="">Choose job or internship</option>{opportunities.map((o) => <option key={`${o.type}:${o.id}`} value={`${o.type}:${o.id}`}>{o.type}: {o.title}</option>)}</select></label>
      </div>
      {studentId && <div className="grid gap-4 border-t border-hairline pt-4 md:grid-cols-2">
        <label className="label">Placement status<select className="input mt-1 w-full" value={placementStatus} onChange={(e) => setPlacementStatus(e.target.value as StudentRow['placement_status'])}><option value="UNKNOWN">Unconfirmed</option><option value="PLACED">Placed</option><option value="NOT_PLACED">Not placed</option></select></label>
        <Input value={company} onChange={(e) => setCompany(e.target.value)} placeholder="Company" />
        <Input value={jobRole} onChange={(e) => setJobRole(e.target.value)} placeholder="Job role" />
        <Input type="number" min="0" step="0.01" value={packageLpa} onChange={(e) => setPackageLpa(e.target.value)} placeholder="Package (LPA)" />
        <Input type="date" value={placementDate} onChange={(e) => setPlacementDate(e.target.value)} />
        <Input value={statusReason} onChange={(e) => setStatusReason(e.target.value)} placeholder="Reason for status change (optional)" />
        <Button onClick={() => void savePlacementStatus()}>Save placement status</Button>
      </div>}
      <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Required justification for this exception" />
      <Button onClick={() => void grant()} disabled={!studentId || !opportunityKey || reason.trim().length < 5}>Grant individual permission</Button>
    </section>
    {studentId && <section className="card space-y-3 p-5"><h2 className="font-semibold text-ink">Placement status history</h2>{statusHistory.length === 0 ? <p className="text-sm text-ink-soft">No status changes recorded.</p> : statusHistory.map((row) => <article key={row.id} className="border-b border-hairline py-3 text-sm"><p className="font-medium">{row.from_status ?? 'Unconfirmed'} → {row.to_status}</p><p className="text-ink-soft">{row.actor?.name ?? (row.changed_by ? `Admin #${row.changed_by}` : 'Admin account removed')} · {new Date(row.changed_at).toLocaleString()}</p>{row.reason && <p className="text-ink-soft">{row.reason}</p>}{row.new_details?.placement_company && <p className="text-ink-soft">{row.new_details.placement_company} · {row.new_details.placement_job_role} · {row.new_details.placement_package ?? 'Package not recorded'} LPA</p>}</article>)}</section>}
    <section className="card space-y-3 p-5"><h2 className="font-semibold text-ink">Permission history</h2>
      {history.length === 0 ? <p className="text-sm text-ink-soft">No exceptions found.</p> : history.map((row) => <article key={row.id} className="flex flex-wrap items-center justify-between gap-3 border-b border-hairline py-3 text-sm"><div><p className="font-medium">Student {row.student_id} · {row.opportunity_type.replace('App\\Models\\', '')} #{row.opportunity_id}</p><p className="text-ink-soft">Granted: {row.grant_reason} · {new Date(row.granted_at).toLocaleString()}</p>{row.revoked_at && <p className="text-ink-soft">Revoked: {row.revocation_reason} · {new Date(row.revoked_at).toLocaleString()}</p>}</div>{!row.revoked_at && <Button variant="secondary" onClick={() => void revoke(row.id)}>Revoke</Button>}</article>)}
    </section>
  </div>
}
