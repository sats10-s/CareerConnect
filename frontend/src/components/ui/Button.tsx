import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Spinner } from './Spinner'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary'
  isLoading?: boolean
  children: ReactNode
}

export function Button({ variant = 'primary', isLoading, children, disabled, className = '', ...rest }: ButtonProps) {
  return (
    <button
      className={`${variant === 'primary' ? 'btn-primary' : 'btn-secondary'} ${className}`}
      disabled={disabled || isLoading}
      {...rest}
    >
      {isLoading && <Spinner className="h-4 w-4 text-current" />}
      {children}
    </button>
  )
}
