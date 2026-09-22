import { api } from './api'
import type {
  AdminDashboardData,
  FacultyDashboardData,
  StudentDashboardData,
  SuperAdminDashboardData,
} from '@/types/dashboard'

export const dashboardService = {
  student: () => api.get<StudentDashboardData>('/student/dashboard').then((r) => r.data),
  faculty: () => api.get<FacultyDashboardData>('/faculty/dashboard').then((r) => r.data),
  admin: () => api.get<AdminDashboardData>('/admin/dashboard').then((r) => r.data),
  superAdmin: () => api.get<SuperAdminDashboardData>('/super-admin/dashboard').then((r) => r.data),
}
