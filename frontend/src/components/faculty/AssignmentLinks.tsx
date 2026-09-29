import { Link } from 'react-router-dom'

/** Shortcuts from an assignment into the faculty screens that are already filtered to it via ?assignment=. */
export function FacultyAssignmentLinks({ assignmentId }: { assignmentId: number }) {
  return (
    <div className="flex flex-wrap justify-end gap-2">
      <Link to={`/faculty/attendance?assignment=${assignmentId}`} className="btn-secondary">Attendance</Link>
      <Link to={`/faculty/progress?assignment=${assignmentId}`} className="btn-secondary">Progress</Link>
      <Link to={`/faculty/documents?assignment=${assignmentId}`} className="btn-secondary">Documents</Link>
    </div>
  )
}

