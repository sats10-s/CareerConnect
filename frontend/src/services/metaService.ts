import { api } from './api'
import type { Batch, Department } from '@/types/meta'

export const metaService = {
  async departments(): Promise<Department[]> {
    const { data } = await api.get('/meta/departments')
    return data as Department[]
  },

  async batches(): Promise<Batch[]> {
    const { data } = await api.get('/meta/batches')
    return data as Batch[]
  },
}
