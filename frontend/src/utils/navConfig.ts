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
    { label: 'Jobs', path: '/student/jobs', icon: Briefcase },
    { label: 'Internships', path: '/student/internships', icon: GraduationCap },
    { label: 'Applications', path: '/student/applications', icon: FileText },
    { label: 'My Internship', path: '/student/my-internship', icon: BookMarked },
    { label: 'Notifications', path: '/student/notifications', icon: Bell, implemented: true },
    { label: 'Profile', path: '/student/profile', icon: User, implemented: true },
  ],
  FACULTY: [
    { label: 'Dashboard', path: '/faculty/dashboard', icon: LayoutDashboard, implemented: true },
    { label: 'My Students', path: '/faculty/students', icon: Users },
    { label: 'Internships', path: '/faculty/internships', icon: GraduationCap },
    { label: 'Attendance', path: '/faculty/attendance', icon: CalendarCheck },
    { label: 'Progress', path: '/faculty/progress', icon: TrendingUp },
    { label: 'Evaluations', path: '/faculty/evaluations', icon: ClipboardCheck },
    { label: 'Documents', path: '/faculty/documents', icon: FolderOpen },
    { label: 'Excel Sync', path: '/faculty/excel-sync', icon: Sheet },
    { label: 'Reports', path: '/faculty/reports', icon: BarChart3 },
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

export const ROLE_ICON: Record<Role, LucideIcon> = {
  STUDENT: GraduationCap,
  FACULTY: Users,
  PLACEMENT_ADMIN: ShieldCheck,
  SUPER_ADMIN: Lock,
}
