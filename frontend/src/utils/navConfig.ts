import {
  LayoutDashboard,
  Briefcase,
  GraduationCap,
  FileText,
  BookMarked,
  Bell,
  User,
  Users,
  Building2,
  CalendarCheck,
  CalendarClock,
  TrendingUp,
  ClipboardCheck,
  FolderOpen,
  Sheet,
  BarChart3,
  Handshake,
  MessagesSquare,
  ShieldCheck,
  Settings,
  Network,
  Landmark,
  Layers,
  Lock,
  History,
  type LucideIcon,
} from 'lucide-react'
import type { Role } from '@/types/auth'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  /** Placeholder nav items render a generic "coming soon" page. */
  implemented?: boolean
}

export const NAV_ITEMS: Record<Role, NavItem[]> = {
  STUDENT: [
    { label: 'Dashboard', path: '/student/dashboard', icon: LayoutDashboard, implemented: true },
    { label: 'Jobs', path: '/student/jobs', icon: Briefcase, implemented: true },
    { label: 'Internships', path: '/student/internships', icon: GraduationCap, implemented: true },
    { label: 'Applications', path: '/student/applications', icon: FileText, implemented: true },
    { label: 'Interviews', path: '/student/interviews', icon: CalendarClock, implemented: true },
    { label: 'My Internship', path: '/student/my-internship', icon: BookMarked, implemented: true },
    { label: 'Notifications', path: '/student/notifications', icon: Bell, implemented: true },
    { label: 'Profile', path: '/student/profile', icon: User, implemented: true },
  ],
  FACULTY: [
    { label: 'Dashboard', path: '/faculty/dashboard', icon: LayoutDashboard, implemented: true },
    { label: 'My Students', path: '/faculty/students', icon: Users, implemented: true },
    { label: 'Internships', path: '/faculty/internships', icon: GraduationCap, implemented: true },
    { label: 'Attendance', path: '/faculty/attendance', icon: CalendarCheck, implemented: true },
    { label: 'Progress', path: '/faculty/progress', icon: TrendingUp, implemented: true },
    { label: 'Evaluations', path: '/faculty/evaluations', icon: ClipboardCheck, implemented: true },
    { label: 'Documents', path: '/faculty/documents', icon: FolderOpen, implemented: true },
    { label: 'Excel Sync', path: '/faculty/excel-sync', icon: Sheet },
    { label: 'Reports', path: '/faculty/reports', icon: BarChart3, implemented: true },
  ],
  PLACEMENT_ADMIN: [
    { label: 'Dashboard', path: '/admin/dashboard', icon: LayoutDashboard, implemented: true },
    { label: 'Students', path: '/admin/students', icon: Users },
    { label: 'Faculty', path: '/admin/faculty', icon: GraduationCap },
    { label: 'Companies', path: '/admin/companies', icon: Building2 },
    { label: 'Jobs', path: '/admin/jobs', icon: Briefcase },
    { label: 'Internships', path: '/admin/internships', icon: BookMarked },
    { label: 'Applications', path: '/admin/applications', icon: FileText },
    { label: 'Placement Drives', path: '/admin/placement-drives', icon: Handshake },
    { label: 'Interviews', path: '/admin/interviews', icon: MessagesSquare },
    { label: 'Excel Sync', path: '/admin/excel-sync', icon: Sheet },
    { label: 'Reports', path: '/admin/reports', icon: BarChart3 },
    { label: 'Notifications', path: '/admin/notifications', icon: Bell, implemented: true },
    { label: 'Audit Logs', path: '/admin/audit-logs', icon: History },
    { label: 'Settings', path: '/admin/settings', icon: Settings },
  ],
  SUPER_ADMIN: [
    { label: 'Dashboard', path: '/super-admin/dashboard', icon: LayoutDashboard, implemented: true },
    { label: 'Users', path: '/super-admin/users', icon: Users },
    { label: 'Roles', path: '/super-admin/roles', icon: Network },
    { label: 'Departments', path: '/super-admin/departments', icon: Landmark },
    { label: 'Batches', path: '/super-admin/batches', icon: Layers },
    { label: 'Security', path: '/super-admin/security', icon: Lock },
    { label: 'Audit Logs', path: '/super-admin/audit-logs', icon: History },
    { label: 'Settings', path: '/super-admin/settings', icon: Settings },
  ],
}

/**
 * A curated subset of each role's nav for the mobile bottom bar (per
 * spec: "Student primary nav can use bottom navigation" - a mobile
 * shell should be intentionally designed, not a shrunk desktop rail).
 * Only STUDENT has one for now, since it's the only role built out
 * this phase; other roles keep the hamburger-drawer-only mobile nav
 * until their own pages exist. Paths not listed here stay reachable
 * through the drawer (the topbar's menu button), which still shows
 * every item.
 */
export const MOBILE_PRIMARY_PATHS: Partial<Record<Role, string[]>> = {
  STUDENT: ['/student/dashboard', '/student/jobs', '/student/applications', '/student/my-internship', '/student/notifications'],
}

export const ROLE_ICON: Record<Role, LucideIcon> = {
  STUDENT: GraduationCap,
  FACULTY: Users,
  PLACEMENT_ADMIN: ShieldCheck,
  SUPER_ADMIN: Lock,
}
