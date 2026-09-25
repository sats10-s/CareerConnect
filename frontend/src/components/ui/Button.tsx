import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Spinner } from './Spinner'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'accent' | 'danger'
  isLoading?: boolean
  children: ReactNode
}

const VARIANT_CLASS: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary: 'btn-primary',
  secondary: 'btn-secondary',
  // Third tier per the button system spec - orange fill, e.g. landing-page "Explore Jobs".
  accent: 'btn-accent',
  danger: 'btn-danger',
}

export function Button({ variant = 'primary', isLoading, children, disabled, className = '', ...rest }: ButtonProps) {
  return (
    <button className={`${VARIANT_CLASS[variant]} ${className}`} disabled={disabled || isLoading} {...rest}>
      {isLoading && <Spinner className="h-4 w-4 text-current" />}
      {children}
    </button>
  )
}
