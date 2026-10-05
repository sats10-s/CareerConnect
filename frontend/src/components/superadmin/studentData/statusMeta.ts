import type { StatusTone } from '@/components/ui/StatusBadge'

/** Student Data import batch statuses, as the Super Admin sees them. */
export const STATUS_META: Record<string, { label: string; tone: StatusTone }> = {
  UPLOADED: { label: 'Checking', tone: 'neutral' },
  VALIDATED: { label: 'Nothing to import', tone: 'neutral' },
  READY_FOR_CONFIRMATION: { label: 'Waiting for confirmation', tone: 'info' },
  PROCESSING: { label: 'Importing', tone: 'info' },
  CONFIRMED: { label: 'Imported', tone: 'success' },
  FAILED: { label: 'Failed', tone: 'error' },
  ROLLED_BACK: { label: 'Rolled back', tone: 'warning' },
  CANCELLED: { label: 'Cancelled', tone: 'neutral' },
}
