import { facultyService } from '@/services/facultyService'
import type { InternshipAssignmentStatus } from '@/types/internshipAssignment'
import { useApiResource } from './useApiResource'

/**
 * The faculty member's own assignments as picker options (up to 100 -
 * the backend's per_page ceiling). Used for the "which student" select
 * on attendance/progress/documents/evaluations; the backend has
 * already scoped the list to this faculty member.
 */
export function useFacultyAssignments(status?: InternshipAssignmentStatus) {
  return useApiResource(
    () => facultyService.assignments({ per_page: 100, status }).then((d) => d.items),
    `faculty-assignments:${status ?? 'all'}`,
  )
}
