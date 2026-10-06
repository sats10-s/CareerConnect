import type { StatusTone } from '@/components/ui/StatusBadge'
import type { StudentProfileData } from '@/types/studentProfile'

/** The college-record fields a student can report as wrong (CorrectionRequest::FIELDS on the backend). */
export type CorrectionField = 'name' | 'roll_number' | 'year_of_joining' | 'gender' | 'department' | 'section' | 'phone' | 'cgpa' | 'backlogs' | 'ssc_score' | 'inter_score' | 'other'

export const CORRECTION_FIELDS: { value: CorrectionField; label: string }[] = [
  { value: 'cgpa', label: 'CGPA' },
  { value: 'backlogs', label: 'Backlogs' },
  { value: 'department', label: 'Department' },
  { value: 'section', label: 'Section' },
  { value: 'year_of_joining', label: 'Year of joining' },
  { value: 'ssc_score', label: '10th score' },
  { value: 'inter_score', label: 'Inter / Diploma score' },
  { value: 'name', label: 'Name' },
  { value: 'roll_number', label: 'JNTU No' },
  { value: 'gender', label: 'Gender' },
  { value: 'phone', label: 'Mobile number' },
  { value: 'other', label: 'Something else' },
]

/**
 * Display rules for the college record. Missing values always say why
 * ("CGPA not yet available"), never show as 0.
 */

/** 2023, 2027 -> "2023–27". */
export function batchLabel(start: number | null, end: number | null): string | null {
  if (!start || !end) return null
  return `${start}–${String(end).slice(-2)}`
}

export function programLabel(program: StudentProfileData['program']): string | null {
  return program === 'mtech' ? 'M.Tech' : program === 'btech' ? 'B.Tech' : null
}

export function entryLabel(entry: StudentProfileData['entry_type']): string | null {
  return entry === 'lateral' ? 'Lateral entry' : entry === 'regular' ? 'Regular' : null
}

export function yearLabel(year: number | null): string | null {
  if (!year) return null
  const suffix = year === 1 ? 'st' : year === 2 ? 'nd' : year === 3 ? 'rd' : 'th'
  return `${year}${suffix} year`
}

export function sscText(score: number | null, scale: StudentProfileData['ssc_scale']): string {
  if (score === null) return 'Not reported'
  return scale === 'cgpa10' ? `${trimNumber(score)} / 10 CGPA` : `${trimNumber(score)}%`
}

export function interLabel(type: StudentProfileData['inter_type'], entry: StudentProfileData['entry_type']): string {
  if (type === 'diploma' || (!type && entry === 'lateral')) return 'Diploma'
  return 'Intermediate'
}

export function backlogsMeta(backlogs: number | null): { label: string; tone: StatusTone } {
  if (backlogs === null) return { label: 'Backlogs not reported', tone: 'neutral' }
  if (backlogs === 0) return { label: 'No backlogs', tone: 'success' }
  return { label: `${backlogs} backlog${backlogs === 1 ? '' : 's'}`, tone: backlogs <= 2 ? 'warning' : 'error' }
}

/** Direction of the latest CGPA change, from the history. */
export function cgpaTrend(profile: Pick<StudentProfileData, 'cgpa' | 'academic_history'>): 'up' | 'down' | 'same' | null {
  const latest = profile.academic_history.find((entry) => entry.cgpa !== null && entry.previous_cgpa !== null)
  if (!latest || profile.cgpa === null || latest.cgpa === null || latest.previous_cgpa === null) return null
  if (latest.cgpa > latest.previous_cgpa) return 'up'
  if (latest.cgpa < latest.previous_cgpa) return 'down'
  return 'same'
}

export function trimNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)))
}

export function genderLabel(gender: StudentProfileData['gender']): string {
  return gender === 'female' ? 'Female' : gender === 'male' ? 'Male' : 'Not recorded'
}
